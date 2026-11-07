<?php
namespace Awz\Allxls;

use Awz\Allxls\Helper;

/**
 * Обработка опций и правил сопоставления колонок профиля.
 *
 * Логика перенесена из \Awz\Allxls\Api\Map (описание правил в диалоге
 * сопоставления, этап 3) и применяется на этапе импорта (Pimport::applyMaps).
 *
 * Формат настроек сопоставления (SETTINGS.SHEETS[sheet].COLUMNS[from].MAP_SETTINGS[key]):
 *  - TRIM        Y/N — обрезать пробелы
 *  - SKIP_EMPTY  Y/N — пропускать пустые значения
 *  - IS_NEW      Y/N — применять только для новых элементов
 *  - CASE        ''|lower|upper|firstupper — регистр значения
 *  - DELIMETER   разделитель множественных значений
 *  - SEARCH_USE  Y/N — использовать для поиска элемента
 *  - SEARCH_MODE eq|begin|end|contains — режим поиска
 *  - RULES       массив правил:
 *    [
 *      'TYPE' => 'transform'|'skip'|'entity',
 *      'AGG'  => 'AND'|'OR'|'AND_NOT'|'OR_NOT',
 *      'CONDITIONS' => [ // дерево условий, вложенность без ограничений
 *          ['SOURCE'=>..., 'OP'=>..., 'VALUE'=>..., 'VALUE2'=>...],  // лист-условие
 *          ['AGG'=>'OR', 'CHILDREN'=>[ ... ]],                        // вложенная группа
 *      ],
 *      'ACTION' => ..., 'ACTION_VALUE' => ..., 'ACTION_VALUE2' => ...,
 *    ]
 */
class MapRules {

    /** @var bool флаг срабатывания правила пропуска строки (сбрасывает вызывающий код перед обработкой строки) */
    public static bool $lastSkip = false;

    /** @var array|null правило TYPE=skip, вызвавшее последний пропуск строки (для логов/UI) */
    public static ?array $lastSkipRule = null;

    /** @var string|null значение колонки, обработанное правилом пропуска (для логов) */
    public static ?string $lastSkipValue = null;

    /** @var array сработавшие правила последней обработки (сбрасывает вызывающий код): [{field,type,action,aval}] */
    public static array $lastApplied = [];

    /** @var bool сработало правило удаления элемента (TYPE=delete) — сбрасывает вызывающий код */
    public static bool $lastDelete = false;

    /** @var bool сработало правило «не обрабатывать значение» (TYPE=unset) — поле исключается из загрузки; сбрасывает вызывающий код */
    public static bool $lastUnset = false;

    /**
     * Применение всех настроек и правил сопоставления колонки к значению.
     *
     * @param string $value значение ячейки-источника
     * @param array $col конфиг колонки (MAPS, MAP_SETTINGS)
     * @param array $rowValues значения всей строки (алиас/ключ колонки => значение)
     * @param array $options дополнительные опции:
     *        'columns' => конфиги колонок листа (для макросов #COL_*#),
     *        'from' => ключ текущей колонки,
     *        'iblock_id' => ид инфоблока-приёмника (для действия unique_code),
     *        'is_new' => bool — элемент новый
     * @return string
     */
    public static function applyMaps(string $value, array $col, array $rowValues = [], array $options = []): string
    {
        $maps = is_array($col['MAPS'] ?? null) ? $col['MAPS'] : [];
        $mapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];

        foreach ($maps as $idx => $mapValue) {
            $settings = is_array($mapSettings[$idx] ?? null) ? $mapSettings[$idx] : [];

            // базовые опции обработки значения
            $current = self::applySettings($value, $settings);

            // правила обработки (transform / skip / entity)
            // вычисляются ДО проверки SKIP_EMPTY: правило пропуска строки
            // должно срабатывать и на пустом значении ячейки
            $rules = (isset($settings['RULES']) && is_array($settings['RULES'])) ? $settings['RULES'] : [];
            if ($rules) {
                $before = count(self::$lastApplied);
                $result = self::processRules($rules, $current, $rowValues, $options);
                // правила, сработавшие в этой цепочке, привязываем к полю назначения
                for ($i = $before, $c = count(self::$lastApplied); $i < $c; $i++) {
                    self::$lastApplied[$i]['field'] = $mapValue;
                }
                if ($result['skip']) {
                    // правило пропуска/удаления строки — обработка значения
                    // прекращается. Возвращаем ИСХОДНОЕ значение (не пустую
                    // строку): строка будет пропущена целиком, но значение
                    // поля нужно нетронутым для повторной сборки поисковых
                    // полей (buildItem 'search' → фильтр поиска элемента)
                    self::$lastSkip = true;
                    return $value;
                }
                if (!empty($result['unset'])) {
                    // правило «не обрабатывать значение» — поле исключается
                    // из загрузки (buildItem не запишет его в элемент);
                    // значение возвращаем исходным, строка обрабатывается штатно
                    self::$lastUnset = true;
                    return $value;
                }
                $current = $result['value'];
            }

            if (($settings['SKIP_EMPTY'] ?? 'Y') !== 'N' && $current === '') {
                continue;
            }

            $value = $current;
        }

        return $value;
    }

    /**
     * Применение базовых опций сопоставления к значению
     * (TRIM, CASE, замены REPLACE).
     *
     * @param string $value исходное значение
     * @param array $settings настройки сопоставления
     * @return string
     */
    public static function applySettings(string $value, array $settings): string
    {
        if (($settings['TRIM'] ?? 'Y') !== 'N') {
            $value = trim($value);
        }

        $case = (string)($settings['CASE'] ?? '');
        if ($case === 'lower') $value = mb_strtolower($value);
        if ($case === 'upper') $value = mb_strtoupper($value);
        if ($case === 'firstupper') {
            $value = mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
        }

        foreach ((array)($settings['REPLACE'] ?? []) as $rule) {
            if (!is_array($rule)) continue;
            $from = (string)($rule['FROM'] ?? '');
            if ($from === '') continue;
            $match = (($rule['MODE'] ?? 'eq') === 'neq') ? $value !== $from : $value === $from;
            if ($match) {
                $value = (string)($rule['TO'] ?? '');
            }
        }

        return $value;
    }

    /**
     * Обработка массива правил.
     *
     * @param array $rules правила (SETTINGS.RULES)
     * @param string $value текущее значение колонки (с файла)
     * @param array $rowValues значения всей строки
     * @param array $options опции (columns, from, is_new)
     * @return array{value: string, skip: bool}
     */
    public static function processRules(array $rules, string $value, array $rowValues = [], array $options = []): array
    {
        $skip = false;

        // начальное значение цепочки правил: макрос #VALUE# всегда указывает
        // на него, а текущее (обработанное предыдущими правилами) — #VALUE_PROCESSED#
        $options['initial_value'] = $value;

        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;

            $type = (string)($rule['TYPE'] ?? 'transform');

            $conditions = (isset($rule['CONDITIONS']) && is_array($rule['CONDITIONS']))
                ? $rule['CONDITIONS']
                : [];

            $agg = (string)($rule['AGG'] ?? 'AND');
            $matched = self::evalConditions($conditions, $agg, $value, $rowValues, $options);
            if (!$matched) continue;

            // фиксация сработавшего правила (для технической информации/логов)
            self::$lastApplied[] = [
                'type' => $type,
                'action' => ($type === 'skip' || $type === 'delete' || $type === 'unset') ? '' : (string)($rule['ACTION'] ?? ''),
                'aval' => ($type === 'skip' || $type === 'delete' || $type === 'unset') ? '' : (string)($rule['ACTION_VALUE'] ?? ''),
                'rule' => $rule,
            ];

            if ($type === 'unset') {
                // правило «не обрабатывать значение»: поле исключается из
                // загрузки (не попадает в элемент), строка НЕ пропускается —
                // остальные поля обрабатываются штатно
                self::$lastUnset = true;
                self::$lastSkipRule = $rule;
                self::$lastSkipValue = $value;
                continue;
            }

            if ($type === 'skip' || $type === 'delete') {
                $skip = true;
                // правило удаления элемента: строка пропускается, а найденный
                // элемент удаляется (Pimport::deleteRowByRule)
                if ($type === 'delete') {
                    self::$lastDelete = true;
                }
                // запоминаем сработавшее правило (для логов/статистики пропусков);
                // delete перезаписывает skip — оно сильнее
                self::$lastSkipRule = $rule;
                // значение, по которому сравнивалось условие (для логов)
                self::$lastSkipValue = $value;
                // не break: правила ниже по цепочке (например delete после
                // skip) тоже должны проверить свои условия
                continue;
            }

            // строка уже помечена на пропуск/удаление — преобразования
            // больше не применяем, но правила skip/delete ниже по цепочке
            // продолжают проверяться (ветка выше)
            if ($skip) {
                continue;
            }

            $action = (string)($rule['ACTION'] ?? 'replace');
            $aval = (string)($rule['ACTION_VALUE'] ?? '');
            $aval2 = (string)($rule['ACTION_VALUE2'] ?? '');

            if ($type === 'entity') {
                // преобразование существующего значения (старого или установленного правилами выше)
                $value = self::applyAction($action, $value, $aval, $aval2, $value, $rowValues, $options);
            } else {
                // transform — преобразование значения с файла
                $value = self::applyAction($action, $value, $aval, $aval2, $value, $rowValues, $options);
            }}

        return ['value' => $value, 'skip' => $skip, 'unset' => self::$lastUnset];
    }

    /**
     * Вычисление группы условий (И/ИЛИ, с отрицанием).
     *
     * @param array $conditions дерево условий
     * @param string $agg AND|OR|AND_NOT|OR_NOT
     * @param string $currentValue текущее значение колонки
     * @param array $rowValues значения всей строки
     * @param array $options опции
     * @return bool
     */
    public static function evalConditions(array $conditions, string $agg, string $currentValue, array $rowValues = [], array &$options = []): bool
    {
        if (!$conditions) return true;

        $results = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond)) continue;
            if (isset($cond['CHILDREN']) && is_array($cond['CHILDREN'])) {
                // вложенная группа условий
                $childAgg = (string)($cond['AGG'] ?? 'AND');
                $results[] = self::evalConditions($cond['CHILDREN'], $childAgg, $currentValue, $rowValues, $options);
            } else {
                $results[] = self::checkCondition($cond, $currentValue, $rowValues, $options);
            }
        }
        if (!$results) return true;

        $isOr = ($agg === 'OR' || $agg === 'OR_NOT');
        $result = $isOr ? (in_array(true, $results, true)) : (!in_array(false, $results, true));

        if ($agg === 'AND_NOT' || $agg === 'OR_NOT') {
            $result = !$result;
        }

        return $result;
    }

    /**
     * Проверка одного условия (листа дерева).
     *
     * @param array $cond ['SOURCE'=>..., 'OP'=>..., 'VALUE'=>..., 'VALUE2'=>...]
     * @param string $currentValue текущее значение колонки
     * @param array $rowValues значения всей строки
     * @param array $options опции
     * @return bool
     */
    public static function checkCondition(array $cond, string $currentValue, array $rowValues = [], array &$options = []): bool
    {
        $op = (string)($cond['OP'] ?? 'eq');
        $source = (string)($cond['SOURCE'] ?? '');
        $value = self::expandMacros((string)($cond['VALUE'] ?? ''), $currentValue, $rowValues, $options);
        $value2 = self::expandMacros((string)($cond['VALUE2'] ?? ''), $currentValue, $rowValues, $options);

        // значение источника условия:
        //  - макрос источника ('#ELEMENT_FOUND#', '#VALUE_FOUND#') — результат
        //    раскрытия макроса (Y/N), условие вида «элемент найден = N → пропуск»;
        //  - '__PROCESSED_<key>' — преобразованное значение колонки (после
        //    настроек и правил этой колонки), вычисляется лениво с кешем;
        //  - ключ колонки (в т.ч. текущей) — начальное (сырое из файла)
        //    значение из $rowValues;
        //  - пустой/неизвестный источник — текущее значение колонки-источника правила.
        if (str_starts_with($source, '__PROCESSED_')) {
            $subject = self::getProcessedColumnValue(substr($source, strlen('__PROCESSED_')), $rowValues, $options);
        } elseif ($source !== '' && $source[0] === '#') {
            // источник-макрос: сравниваем результат раскрытия (#ELEMENT_FOUND# = 'Y'...)
            $subject = self::expandMacros($source, $currentValue, $rowValues, $options);
        } elseif ($source !== '' && array_key_exists($source, $rowValues)) {
            $subject = (string)$rowValues[$source];
        } else {
            $subject = $currentValue;
        }

        return self::checkOperator($subject, $op, $value, $value2);
    }

    /**
     * Преобразованное значение колонки: к значению из файла применяются
     * настройки и правила этой колонки (MapRules::applyMaps). Вычисляется
     * лениво и кешируется в $options['__row_processed'] на время обработки
     * строки — колонки, ссылающиеся друг на друга, не пересчитываются.
     *
     * @param string $key ключ колонки
     * @param array $rowValues начальные значения строки
     * @param array $options опции (columns, from, initial_value, __row_processed)
     * @return string
     */
    protected static function getProcessedColumnValue(string $key, array $rowValues, array &$options): string
    {
        if (!is_array($options['__row_processed'] ?? null)) {
            $options['__row_processed'] = [];
        }
        $cache =& $options['__row_processed'];
        if (array_key_exists($key, $cache)) {
            return (string)$cache[$key];
        }
        $raw = (string)($rowValues[$key] ?? '');
        // защита от взаимной рекурсии: пока колонка «в обработке»,
        // ссылающиеся на неё условия видят начальное значение
        $cache[$key] = $raw;
        $columns = is_array($options['columns'] ?? null) ? $options['columns'] : [];
        $colCfg = is_array($columns[$key] ?? null) ? $columns[$key] : [];
        if ($colCfg === []) {
            return $raw;
        }
        // изоляция статики пропуска: skip-правила вложенной колонки не должны
        // влиять на решение о пропуске строки основной колонки
        $prevSkip = self::$lastSkip;
        $prevSkipRule = self::$lastSkipRule;
        $prevSkipValue = self::$lastSkipValue;
        self::$lastSkip = false;
        self::$lastSkipRule = null;
        self::$lastSkipValue = null;
        try {
            $value = self::applyMaps($raw, $colCfg, $rowValues, $options);
        } finally {
            self::$lastSkip = $prevSkip;
            self::$lastSkipRule = $prevSkipRule;
            self::$lastSkipValue = $prevSkipValue;
        }
        $cache[$key] = $value;
        return $value;
    }

    /**
     * Проверка оператора условия.
     */
    public static function checkOperator(string $subject, string $op, string $value = '', string $value2 = ''): bool
    {
        switch ($op) {
            case 'eq':          return $subject === $value;
            case 'ne':          return $subject !== $value;
            case 'gt':          return (float)$subject > (float)$value;
            case 'lt':          return (float)$subject < (float)$value;
            case 'ge':          return (float)$subject >= (float)$value;
            case 'le':          return (float)$subject <= (float)$value;
            case 'between':     return (float)$subject >= (float)$value && (float)$subject <= (float)$value2;
            case 'contains':    return $value !== '' && mb_stripos($subject, $value) !== false;
            case 'not_contains': return $value === '' || mb_stripos($subject, $value) === false;
            case 'begins':      return $value !== '' && mb_stripos($subject, $value) === 0;
            case 'ends':
                if ($value === '') return false;
                $len = mb_strlen($value);
                return mb_stripos(mb_substr($subject, -$len), $value) === 0;
            case 'empty':       return trim($subject) === '';
            case 'not_empty':   return trim($subject) !== '';
            case 'regex':       return $value !== '' && (bool)@preg_match($value, $subject);
            case 'not_regex':   return $value === '' || !(bool)@preg_match($value, $subject);
            case 'any':         return true;
        }
        return false;
    }

    /**
     * Применение действия преобразования к значению.
     *
     * @param string $action код действия
     * @param string $value значение (с файла или существующее)
     * @param string $aval параметр действия
     * @param string $aval2 второй параметр действия
     * @param string $entityValue существующее значение элемента (для контекста)
     * @param array $rowValues значения всей строки
     * @param array $options опции
     * @return string
     */
    public static function applyAction(
        string $action, string $value, string $aval = '', string $aval2 = '',
        string $entityValue = '', array $rowValues = [], array $options = []
    ): string {
        $aval = self::expandMacros($aval, $value, $rowValues, $options);
        $aval2 = self::expandMacros($aval2, $value, $rowValues, $options);

        switch ($action) {
            case 'replace':        return $aval;
            case 'del_substr':     return $aval === '' ? $value : str_replace($aval, '', $value);
            case 'replace_substr': return str_replace($aval, $aval2, $value);
            case 'prepend':        return $aval . $value;
            case 'append':         return $value . $aval;
            case 'lower':          return mb_strtolower($value);
            case 'upper':          return mb_strtoupper($value);
            case 'firstupper':
                return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
            case 'translit':
                return self::translit($value);
            case 'cut_before':
                // обрезать всё до символа: "12.01.2026, пн, 10:00" с ", пн" -> "пн, 10:00"
                $pos = $aval === '' ? false : mb_strpos($value, $aval);
                if ($pos === false) return $value; // символа нет — значение не трогаем
                return $aval2 === 'last'
                    ? mb_substr($value, (int)mb_strrpos($value, $aval) + mb_strlen($aval))
                    : mb_substr($value, $pos + mb_strlen($aval));
            case 'cut_after':
                // обрезать всё после символа: "12.01.2026, пн" с "," -> "12.01.2026"
                $pos = $aval === '' ? false : mb_strpos($value, $aval);
                if ($pos === false) return $value; // символа нет — значение не трогаем
                return $aval2 === 'last'
                    ? mb_substr($value, 0, (int)mb_strrpos($value, $aval))
                    : mb_substr($value, 0, $pos);
            case 'unique_code':
                return Helper\Iblock::generateUniqueCode((string)($options['iblock_id'] ?? ''), $value);
            case 'round':
                $precision = (int)$aval;
                return (string)round((float)$value, $precision);
            case 'mul':            return (string)((float)$value * (float)$aval);
            case 'div':
                $d = (float)$aval;
                return $d != 0.0 ? (string)((float)$value / $d) : $value;
            case 'add':            return (string)((float)$value + (float)$aval);
            case 'sub':            return (string)((float)$value - (float)$aval);
            case 'inc_percent':
                return (string)((float)$value + (float)$value * (float)$aval / 100);
            case 'dec_percent':
                return (string)((float)$value - (float)$value * (float)$aval / 100);
            case 'formula':
                return self::formula($aval, $value, $entityValue, $rowValues, $options);
            case 'json_key':
                // извлечь из JSON по ключу (aval — ключ, aval2 — выходной разделитель)
                return self::jsonKey($value, $aval, $aval2);
            case 'build_json':
                // сформировать финальное значение-JSON из шаблона (aval)
                return self::buildJson($aval, $value, $rowValues, $options);
            case 'json_html':
                // сформировать HTML-таблицу из JSON
                return self::jsonHtml($value);
            case 'build_new':
                // сформировать новое значение (JSON-описание для создания записи
                // справочника/раздела/элемента, если привязка не найдена) —
                // шаблон обрабатывается так же, как build_json; создание выполняет
                // сервис приёмника при отсутствии значения
                return self::buildJson($aval, $value, $rowValues, $options);
        }
        return $value;
    }

    /**
     * Извлечение значений из JSON по ключу.
     * Поддерживаются:
     *  - одиночный объект {"title":"..","url":".."} — берется значение ключа;
     *  - массив объектов [{"title":"..","url":".."},{...}] — значения ключа
     *    всех элементов, склеенные разделителем (aval2, по умолчанию ',');
     *  - массив скаляров ["a","b"] — элементы склеиваются разделителем.
     * Ключ отсутствует у элемента — элемент пропускается.
     * Значение не является валидным JSON — не изменяется.
     *
     * @param string $value исходное значение (JSON-строка)
     * @param string $key ключ для извлечения значения
     * @param string $delim выходной разделитель множественных значений
     * @return string
     */
    protected static function jsonKey(string $value, string $key, string $delim = ','): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }
        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return $value; // не JSON — значение не трогаем
        }
        $delim = $delim !== '' ? $delim : ',';
        // одиночный объект трактуется как массив из одного элемента
        $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
        if (!$isList) {
            $data = [$data];
        }
        $result = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                if (array_key_exists($key, $item) && !is_array($item[$key])) {
                    $result[] = trim((string)$item[$key]);
                }
            } elseif (!is_array($item)) {
                // массив скаляров — ключ не применим, берется сам элемент
                $result[] = trim((string)$item);
            }
        }
        if (!$result) {
            return '';
        }
        return implode($delim, $result);
    }

    /**
     * Формирование HTML-таблицы из JSON.
     *
     * Поддерживаются:
     *  - одиночный объект {"name":"Глубина","unit":"мм","value":"30...30"};
     *  - массив объектов [{"name":"..","unit":"..","value":".."},{...}].
     * Каждый объект — строка таблицы, каждый ключ объекта — колонка.
     * Таблица: <table class="awz_json_table">, ячейка ключа —
     * <td class="awz_json_td_<ключ>"> (awz_json_td_name, awz_json_td_value, ...).
     * Ключ "unit" (если есть в объекте) добавляется к значению "value"
     * и отдельной колонкой не выводится.
     * Ключей может быть любое количество (не только name/value).
     * Значение не является валидным JSON — не изменяется.
     *
     * @param string $value исходное значение (JSON-строка)
     * @return string
     */
    protected static function jsonHtml(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }
        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return $value; // не JSON — значение не трогаем
        }
        // одиночный объект трактуется как массив из одного элемента
        $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
        if (!$isList) {
            $data = [$data];
        }
        $rows = '';
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue; // скаляры в списке — не таблица ключ/значение
            }
            $unit = isset($item['unit']) && is_scalar($item['unit'])
                ? trim((string)$item['unit']) : '';
            $cells = '';
            foreach ($item as $key => $cellValue) {
                if (!is_scalar($cellValue)) {
                    continue; // вложенные структуры — не колонка «значение»
                }
                $key = trim((string)$key);
                if ($key === '' || $key === 'unit') {
                    continue; // unit — служебный ключ, добавляется к value
                }
                $text = trim((string)$cellValue);
                // unit добавляется к значению value
                if ($key === 'value' && $unit !== '' && $text !== '') {
                    $text .= ' ' . $unit;
                }
                $cellClass = 'awz_json_td_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
                $cells .= '<td class="' . $cellClass . '">'
                    . htmlspecialcharsbx($text) . '</td>';
            }
            if ($cells === '') {
                continue;
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        if ($rows === '') {
            return $value;
        }
        return '<table class="awz_json_table">' . $rows . '</table>';
    }

    /**
     * Формирование финального значения-JSON из шаблона.
     *
     * aval — JSON-шаблон, например:
     *  - {"VALUE":"#VALUE_PROCESSED#","TYPE":"text"}
     *  - [{"VALUE":"#VALUE#","TYPE":"text"},{"VALUE":"#COL_COLNAME#","TYPE":"html"}]
     * Ключей может быть любое количество. В строковых значениях шаблона
     * работают макросы (#VALUE#, #VALUE_PROCESSED#, #COL_*#, #ENTITY# и др.).
     * Шаблон не является валидным JSON — значение не изменяется.
     *
     * @param string $template JSON-шаблон (ACTION_VALUE)
     * @param string $value текущее значение колонки
     * @param array $rowValues значения всей строки
     * @param array $options опции
     * @return string
     */
    protected static function buildJson(string $template, string $value, array $rowValues, array $options): string
    {
        $template = trim($template);
        if ($template === '') {
            return $value;
        }
        $data = json_decode($template, true);
        if (!is_array($data)) {
            // шаблон не JSON — значение не трогаем
            return $value;
        }
        $data = self::expandJsonTemplate($data, $value, $rowValues, $options);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $value : (string)$encoded;
    }

    /**
     * Рекурсивная подстановка макросов в строковые значения JSON-шаблона.
     */
    protected static function expandJsonTemplate($data, string $value, array $rowValues, array $options)
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = self::expandJsonTemplate($v, $value, $rowValues, $options);
            }
            return $out;
        }
        return self::expandMacros((string)$data, $value, $rowValues, $options);
    }

    /**
     * Применение формулы к значению.
     * Поддерживаются макросы #VALUE#, #ENTITY#, #COL_*# и арифметика через eval-подобный разбор.
     */
    protected static function formula(string $formula, string $value, string $entityValue, array $rowValues, array $options): string
    {
        $expr = self::expandMacros($formula, $value, $rowValues, $options);
        $expr = str_replace('#ENTITY#', (string)(float)$entityValue, $expr);
        // оставляем только безопасные символы арифметики
        if (!preg_match('/^[0-9\s\+\-\*\/\(\)\.,]+$/', $expr)) {
            return $value;
        }
        $expr = str_replace(',', '.', $expr);
        $result = 0.0;
        $code = 'return (float)(' . $expr . ');';
        try {
            $result = eval($code);
        } catch (\Throwable $e) {
            return $value;
        }
        if (!is_finite($result)) return $value;
        return (string)$result;
    }

    /**
     * Транслитерация строки (кириллица -> латиница).
     */
    public static function translit(string $value): string
    {
        static $trans = null;
        if ($trans === null) {
            $trans = [
                'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh','з'=>'z',
                'и'=>'i','й'=>'j','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
                'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
                'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            ];
        }
        $result = '';
        $len = mb_strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1);
            $lower = mb_strtolower($ch);
            if (isset($trans[$lower])) {
                $t = $trans[$lower];
                $result .= ($ch !== $lower) ? mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1) : $t;
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    /**
     * Замена макросов в строке.
* Поддерживаются:
      *  - #VALUE#                  — начальное значение колонки-источника (до правил обработки);
      *  - #VALUE_PROCESSED#       — текущее (уже обработанное правилами) значение колонки-источника;
      *  - #ENTITY#                — существующее значение элемента;
      *  - #COL_<KEY>#             — начальное значение другой колонки строки;
      *  - #COL_PROCESSED_<KEY>#   — преобразованное значение другой колонки
      *                              (после ее настроек и правил, лениво с кешем строки).
     *
     * @param string $str строка с макросами
     * @param string $currentValue текущее значение колонки
     * @param array $rowValues значения всей строки
     * @param array $options опции (columns, from, initial_value)
     * @return string
     */
    public static function expandMacros(string $str, string $currentValue = '', array $rowValues = [], array $options = []): string
    {
        if ($str === '' || mb_strpos($str, '#') === false) {
            return $str;
        }

        // начальное значение колонки-источника: фиксируется до применения правил
        // (processRules кладет его в options['initial_value']); вне правил
        // начальным считается само текущее значение
        $initialValue = array_key_exists('initial_value', $options)
            ? (string)$options['initial_value']
            : $currentValue;
        $str = str_replace('#VALUE#', $initialValue, $str);
        // обработанное значение — то, которое получилось после предыдущих правил цепочки
        $str = str_replace('#VALUE_PROCESSED#', $currentValue, $str);

        // значения колонок: по ключу и по сгенерированному имени #COL_<KEY>#
        $columns = is_array($options['columns'] ?? null) ? $options['columns'] : [];
        foreach ($rowValues as $key => $val) {
            $str = str_replace('#COL_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', (string)$key)) . '#', (string)$val, $str);
            // также поддерживаем прямые макросы по алиасу колонки
            $alias = (string)($columns[$key]['ALIAS'] ?? '');
            if ($alias !== '') {
                $str = str_replace('#' . $alias . '#', (string)$val, $str);
            }
        }

        // преобразованные значения других колонок: #COL_PROCESSED_<KEY>#
        // (после настроек и правил этой колонки; лениво, с кешем в options)
        foreach ($columns as $key => $colCfg) {
            $macro = '#COL_PROCESSED_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', (string)$key)) . '#';
            if (mb_strpos($str, $macro) === false) {
                continue;
            }
            $str = str_replace(
                $macro,
                self::getProcessedColumnValue((string)$key, $rowValues, $options),
                $str
            );
        }

        // признак «найден ли существующий элемент при поиске» (Y|N):
        // передается вызывающим кодом (Pimport::applyMaps из processItem);
        // в полной сборке (до поиска) всегда 'N'
        if (mb_strpos($str, '#ELEMENT_FOUND#') !== false) {
            $str = str_replace('#ELEMENT_FOUND#', (string)($options['element_found'] ?? 'N'), $str);
        }

        // признак «найдено ли значение в словаре назначения» (Y|N):
        // списки (L), привязки к элементам (E) и разделам (G)
        if (mb_strpos($str, '#VALUE_FOUND#') !== false) {
            $str = str_replace('#VALUE_FOUND#', self::checkValueExists($currentValue, $options), $str);
        }

        // значения полей найденного при поиске элемента: #ENTITY_<ПОЛЕ>#
        // (#ENTITY_ID#, #ENTITY_NAME#, #ENTITY_PROPERTY_CODE#, #ENTITY_CATALOG_PRICE_1#...).
        // Расчет вынесен на обработчик события onAwzAllxlsExpandMacros
        // (Awz\Allxls\Handlers): данные элемента передаются вызывающим кодом
        // (Pimport::processItem после поиска) в options['entity_data']; в полной
        // сборке (до поиска) их нет — макрос раскрывается в пустую строку.

        // событие D7 (новое ядро): сторонние модули раскрывают свои макросы
        // (объявленные через onAwzAllxlsGetMacros). Обработчик изменяет
        // параметр 'str' и добавляет результат:
        //   EventManager::getInstance()->addEventHandler(
        //       'awz.allxls', 'onAwzAllxlsExpandMacros', fn(\Bitrix\Main\Event $event) {
        //           $str = $event->getParameter('str');
        //           $event->setParameter('str', str_replace('#MY#', 'x', $str));
        //           $event->addResult(new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, []));
        //       })
        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsExpandMacros', [
            'str' => $str,
            'value' => $currentValue,
            'row_values' => $rowValues,
            'options' => $options,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            $params = $result->getParameters();
            if (array_key_exists('str', $params)) {
                $str = (string)$params['str'];
            }
        }

        return $str;
    }

    /** @var array кеш проверок существования значения (#VALUE_FOUND#) */
    protected static array $valueFoundCache = [];

    /** @var array кеш описаний свойств инфоблока (для checkValueExists) */
    protected static array $iblockPropsCache = [];

    /**
     * Проверка существования значения в словаре поля назначения (#VALUE_FOUND#).
     * Поддерживаются свойства: L (значение списка по VALUE/XML_ID),
     * E (элемент привязанного инфоблока по ID/XML_ID/CODE/NAME),
     * G (раздел по ID/NAME/CODE). Для обычных полей значения словаря нет — 'Y'.
     *
     * @param string $value проверяемое значение (обработанное значение колонки)
     * @param array $options опции (value_check: iblock_id, field)
     * @return string 'Y'|'N'
     */
    public static function checkValueExists(string $value, array $options = []): string
    {
        if ($value === '') return 'N';
        $vc = is_array($options['value_check'] ?? null) ? $options['value_check'] : [];
        $iblockId = (int)($vc['iblock_id'] ?? 0);
        $field = (string)($vc['field'] ?? '');
        if ($iblockId <= 0 || $field === '') return 'N';

        $cacheKey = $iblockId.'|'.$field.'|'.$value;
        if (array_key_exists($cacheKey, self::$valueFoundCache)) {
            return (string)self::$valueFoundCache[$cacheKey];
        }

        $found = 'Y';
        $code = '';
        if (preg_match('/^PROPERTY_(.+)$/i', $field, $m)) {
            $code = strtoupper((string)$m[1]);
            $prop = self::getIblockProperty($iblockId, $code);
            if ($prop === null) {
                $found = 'Y';
            } elseif ((string)$prop['PROPERTY_TYPE'] === 'L') {
                $found = self::enumExists((int)$prop['ID'], $value) ? 'Y' : 'N';
            } elseif ((string)$prop['PROPERTY_TYPE'] === 'E') {
                $found = self::linkedElementExists((int)($prop['LINK_IBLOCK_ID'] ?? 0), $value) ? 'Y' : 'N';
            } elseif ((string)$prop['PROPERTY_TYPE'] === 'G') {
                $found = self::sectionExists($iblockId, $value) ? 'Y' : 'N';
            }
        }
        self::$valueFoundCache[$cacheKey] = $found;
        return $found;
    }

    /**
     * Описание свойства инфоблока по коду (старое ядро, кеш на процесс).
     *
     * @param int $iblockId ID инфоблока
     * @param string $code код свойства
     * @return array|null
     */
    protected static function getIblockProperty(int $iblockId, string $code): ?array
    {
        if (isset(self::$iblockPropsCache[$iblockId])) {
            return self::$iblockPropsCache[$iblockId][$code] ?? null;
        }
        self::$iblockPropsCache[$iblockId] = [];
        $res = \CIBlockProperty::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']
        );
        while ($row = $res->Fetch()) {
            self::$iblockPropsCache[$iblockId][(string)$row['CODE']] = $row;
            if ((string)$row['CODE'] === '') {
                self::$iblockPropsCache[$iblockId]['ID_'.$row['ID']] = $row;
            }
        }
        return self::$iblockPropsCache[$iblockId][$code] ?? null;
    }

    /**
     * Наличие значения списка (VALUE или XML_ID).
     */
    protected static function enumExists(int $propertyId, string $value): bool
    {
        $res = \CIBlockPropertyEnum::GetList(
            [],
            ['PROPERTY_ID' => $propertyId, 'VALUE' => $value]
        );
        if ($res->Fetch()) return true;
        $res = \CIBlockPropertyEnum::GetList(
            [],
            ['PROPERTY_ID' => $propertyId, 'XML_ID' => $value]
        );
        return (bool)$res->Fetch();
    }

    /**
     * Наличие элемента в привязанном инфоблоке (ID, XML_ID, CODE или NAME).
     */
    protected static function linkedElementExists(int $linkIblockId, string $value): bool
    {
        if ($linkIblockId <= 0) return false;
        $filters = [['ID' => $value], ['XML_ID' => $value], ['CODE' => $value], ['NAME' => $value]];
        foreach ($filters as $filter) {
            $res = \CIBlockElement::GetList(
                ['ID' => 'ASC'], $filter + ['IBLOCK_ID' => $linkIblockId, 'CHECK_PERMISSIONS' => 'N'],
                false, ['nTopCount' => 1], ['ID']
            );
            if ($res->Fetch()) return true;
        }
        return false;
    }

    /**
     * Наличие раздела (ID, NAME или CODE).
     */
    protected static function sectionExists(int $iblockId, string $value): bool
    {
        $filters = [['ID' => $value], ['NAME' => $value], ['CODE' => $value]];
        foreach ($filters as $filter) {
            $res = \CIBlockSection::GetList(
                ['ID' => 'ASC'], $filter + ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                false, ['nTopCount' => 1], ['ID']
            );
            if ($res->Fetch()) return true;
        }
        return false;
    }

    /**
     * Список макросов, доступных в правилах (для UI).
     *
     * @param array $columns конфиги колонок листа
     * @param string $from ключ текущей колонки
     * @return array
     */
    public static function getMacros(array $columns = [], string $from = ''): array
    {
        $macros = [
            '#VALUE#' => 'Текущее значение колонки-источника',
            '#ELEMENT_FOUND#' => 'Найден ли существующий элемент при поиске: Y/N (в полной сборке — N)',
            '#VALUE_FOUND#' => 'Найдено ли значение в словаре поля назначения: Y/N (списки, привязки E/G)',
        ];
        foreach ($columns as $k => $v) {
            $letter = (string)($v['LETTER'] ?? '');
            $macros['#COL_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $k)) . '#'] =
                'Колонка ' . ($letter ?: $k) . ($v['HEADER'] ? ': ' . $v['HEADER'] : '');
        }
        return $macros;
    }

    /**
     * Фактические значения макросов для строки (для отладки в режиме
     * «по одному элементу»). Значения раскрываются через подстановку
     * в шаблон «#MACRO#» тем же expandMacros — как увидят правила.
     *
     * @param array $columns конфиги колонок листа
     * @param string $from ключ текущей колонки
     * @param string $currentValue текущее значение колонки-источника
     * @param array $rowValues начальные значения строки
     * @param array $options опции (element_found и др.)
     * @return array макрос => раскрытое значение
     */
    public static function getMacrosValues(array $columns = [], string $from = '', string $currentValue = '', array $rowValues = [], array $options = []): array
    {
        // полная изоляция статики правил: раскрытие макросов повторно
        // применяет правила колонок и не должно влиять на состояние
        // правил строки (lastApplied/lastDelete/lastSkip)
        $prevSkip = self::$lastSkip;
        $prevSkipRule = self::$lastSkipRule;
        $prevSkipValue = self::$lastSkipValue;
        $prevDelete = self::$lastDelete;
        $prevApplied = self::$lastApplied;
        self::$lastSkip = false;
        self::$lastSkipRule = null;
        self::$lastSkipValue = null;
        self::$lastDelete = false;
        self::$lastApplied = [];
        try {
            $result = [];
            foreach (array_keys(self::getMacros($columns, $from)) as $macro) {
                $result[$macro] = self::expandMacros($macro, $currentValue, $rowValues, $options);
            }
            return $result;
        } finally {
            self::$lastSkip = $prevSkip;
            self::$lastSkipRule = $prevSkipRule;
            self::$lastSkipValue = $prevSkipValue;
            self::$lastDelete = $prevDelete;
            self::$lastApplied = $prevApplied;
        }
    }
}
