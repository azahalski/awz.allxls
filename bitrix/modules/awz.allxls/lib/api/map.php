<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Helper\ProfilesHelper;

use PhpOffice\PhpSpreadsheet\Coordinate;

class Map extends \Bitrix\Main\Engine\Controller {

    /**
     * Constructor Controller.
     * @param Request|null $request
     */
    public function __construct(Request $request = null)
    {
        \Bitrix\Main\Loader::includeModule('awz.allxls');
        parent::__construct($request);
    }

    public function configureActions()
    {
        $config = [
            'settings'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'forward'=> [
                'prefilters' => []
            ]
        ];

        return $config;
    }

    /**
     * HTML настроек сопоставления для всплывающего окна (этап 3).
     *
     * @param int    $id       ID профиля
     * @param string $field    ID выбранного поля (например NAME или PROPERTY_CODE)
     * @param array  $settings Текущие настройки сопоставления
     * @return array|null
     */

    public function settingsAction(int $id = 0, string $field = '', string $sheet = '', string $from = '', int $key = 0, array $columns = [])
    {
        $columnsParam = $columns;
        $id = (int)$id;
        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                'NOT_FOUND'
            ));
            return null;
        }

        if(!$field){
            $this->addError(new \Bitrix\Main\Error(
                "Не передано поле соответствия",
                'NOT_FIELD'
            ));
            return null;
        }
        if(!$from){
            $this->addError(new \Bitrix\Main\Error(
                "Не передано поле источник",
                'NOT_FIELD'
            ));
            return null;
        }
        if(!$sheet){
            $this->addError(new \Bitrix\Main\Error(
                "Не передан номер листа",
                'NOT_SHEET'
            ));
            return null;
        }

        try {
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }

            // имя поля по его ID (из того же списка, что и селекты сопоставления)
            $fieldName = $field;
            $groups = ProfilesHelper::getFieldGroups($profile);
            $activeBxProp = [];
            foreach ($groups as $group) {
                foreach ($group['ITEMS'] as $item) {
                    if ((string)$item['ID'] === $field) {
                        $fieldName = (string)$item['NAME'];
                        $activeBxProp = $item;
                        break 2;
                    }
                }
            }

            // СЂРµР·РѕР»РІ Р»РёСЃС‚Р°: РєР»РёРµРЅС‚ РїРµСЂРµРґР°С‘С‚ РёРјСЏ Р»РёСЃС‚Р°, РІ РЅР°СЃС‚СЂРѕР№РєР°С… РєР»СЋС‡ РјРѕР¶РµС‚ Р±С‹С‚СЊ SHEET1/SHEET2
            $sheetsCfg = is_array($profile['SETTINGS']['SHEETS'] ?? null) ? $profile['SETTINGS']['SHEETS'] : [];
            if (!isset($sheetsCfg[$sheet])) {
                foreach ($sheetsCfg as $sheetKey => $sheetCfg) {
                    if (is_array($sheetCfg) && (string)($sheetCfg['NAME'] ?? '') === $sheet) {
                        $sheet = $sheetKey;
                        break;
                    }
                }
            }
            $settings = $profile['SETTINGS']['SHEETS'][$sheet]['COLUMNS'][$from]['MAP_SETTINGS'][$key] ?? [];
            $columns = $profile['SETTINGS']['SHEETS'][$sheet]['COLUMNS'] ?? [];

            // актуальные колонки с клиента (этап 3 может быть еще не сохранен —
            // тогда в сохраненных настройках список колонок неполный/старый)
            $clientColumns = is_array($columnsParam ?? null) ? $columnsParam : [];
            foreach ($clientColumns as $clientCol) {
                if (!is_array($clientCol)) continue;
                $colKey = (string)($clientCol['KEY'] ?? '');
                if ($colKey === '') continue;
                $savedCol = is_array($columns[$colKey] ?? null) ? $columns[$colKey] : [];
                $columns[$colKey] = array_merge($savedCol, [
                    'KEY' => $colKey,
                    'LETTER' => (string)($clientCol['LETTER'] ?? ($savedCol['LETTER'] ?? '')),
                    'HEADER' => (string)($clientCol['HEADER'] ?? ($savedCol['HEADER'] ?? '')),
                    'ALIAS' => (string)($clientCol['ALIAS'] ?? ($savedCol['ALIAS'] ?? '')),
                ]);
            }

            $trim = (($settings['TRIM'] ?? 'Y') === 'N') ? '' : ' checked';
            $is_new = (string)($settings['IS_NEW'] ?? '');
            $case = (string)($settings['CASE'] ?? '');
            $searchUse = (($settings['SEARCH_USE'] ?? 'N') === 'Y') ? ' checked' : '';
            $searchMode = (string)($settings['SEARCH_MODE'] ?? 'eq');
            // поля разделов элемента (раздел и родительский раздел): опции создания и уровней
            $isSectionField = in_array($field, ['IBLOCK_SECTION', 'IBLOCK_SECTION_PARENT'], true);
            $dlmtr = (string)($settings['DELIMETER'] ?? ($isSectionField || $activeBxProp['MULTIPLE']=='Y' ? ',' : ""));
            $bind = (string)($settings['BIND'] ?? '');
            $createEnum = (($settings['CREATE_ENUM'] ?? 'Y') === 'N') ? 'N' : 'Y';
            $sectionCreate = (($settings['SECTION_CREATE'] ?? 'Y') === 'N') ? 'N' : 'Y';
            $sectionLevelDelim = (string)($settings['SECTION_LEVEL_DELIM'] ?? '/');
            // режим множественного свойства: '' — перезаписать все значения,
            // 'append' — дополнить новыми, 'delete' — удалить совпавшие значения
            $multi = strtolower(trim((string)($settings['MULTI'] ?? '')));
            if (!in_array($multi, ['append', 'delete'], true)) $multi = '';

            // тип привязки значения — для полей разделов и свойств поддерживаемых типов:
            // как определяется значение (ID / XML_ID / название / код)
            $isProperty = str_starts_with($field, 'PROPERTY_') && !empty($activeBxProp);
            $propType = (string)($activeBxProp['PROPERTY_TYPE'] ?? '');
            $propUserType = (string)($activeBxProp['USER_TYPE'] ?? '');
            if ($isSectionField) {
                $bindOptions = self::getSectionBindOptions();
            } else {
                $bindOptions = self::getPropertyBindOptions($isProperty ? $propType : '', $isProperty ? $propUserType : '', is_array($activeBxProp) ? $activeBxProp : []);
            }

            ob_start();
            ?>
            <div class="awz-allxls-mapdlg adm-workarea">

                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Использовать для поиска элемента:</label>
                    <input type="checkbox" name="MAP_SEARCH_USE"<?=$searchUse?> value="Y">
                    <select name="MAP_SEARCH_MODE" class="awz-allxls-mapdlg__search-mode">
                        <option value="eq"<?=$searchMode === 'eq' ? ' selected' : ''?>>полное совпадение</option>
                        <option value="begin"<?=$searchMode === 'begin' ? ' selected' : ''?>>в начале строки</option>
                        <option value="end"<?=$searchMode === 'end' ? ' selected' : ''?>>в конце строки</option>
                        <option value="contains"<?=$searchMode === 'contains' ? ' selected' : ''?>>по подстроке</option>
                    </select>
                </div>

                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Применение поля:</label>
                    <input type="hidden" name="IS_NEW" value="<?=htmlspecialcharsEx($is_new)?>">
                    <div class="awz-allxls-mapdlg__toggles" data-toggle-group="multi" data-toggle-target="IS_NEW">
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=($is_new === '' || $is_new === 'Y') ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="Y">Новые</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=($is_new === '' || $is_new === 'U') ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="U">Обновляемые</button>
                    </div>
                </div>

                <div class="awz-allxls-mapdlg__row" data-unique-code-warn style="display:none;">
                    <div style="color:#8a6100;background:#fff8e1;border:1px solid #f0d78c;border-radius:4px;padding:8px 10px;font-size:12px;line-height:1.4;">
                        Внимание: если отметить применяемость поля для обновляемых элементов,
                        символьный код будет генерироваться заново при каждом обновлении элемента.
                    </div>
                </div>

                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Обрезать пробелы:</label>
                    <input type="checkbox" name="MAP_TRIM"<?=$trim?> value="Y">
                </div>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Регистр значения:</label>
                    <input type="hidden" name="MAP_CASE" value="<?=htmlspecialcharsEx($case)?>">
                    <div class="awz-allxls-mapdlg__toggles" data-toggle-group="single" data-toggle-target="MAP_CASE">
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$case === '' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="">как есть</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$case === 'lower' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="lower">нижний</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$case === 'upper' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="upper">ВЕРХНИЙ</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$case === 'firstupper' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="firstupper">Заглавная</button>
                    </div>
                </div>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label"><?=$isSectionField ? 'Разделитель нескольких разделов:' : 'Разделитель множ. значения:'?></label>
                    <input type="text" name="DELIMETER" value="<?=htmlspecialcharsEx($dlmtr)?>">
                </div>
                <?php if ($bindOptions): ?>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Тип привязки значения:</label>
                    <select name="MAP_BIND">
                        <?php foreach ($bindOptions as $bindVal => $bindTitle): ?>
                            <option value="<?=htmlspecialcharsEx($bindVal)?>"<?=$bind === $bindVal ? ' selected' : ''?>><?=htmlspecialcharsEx($bindTitle)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($isSectionField): ?>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Создавать отсутствующие разделы:</label>
                    <input type="checkbox" name="MAP_SECTION_CREATE"<?=$sectionCreate === 'Y' ? ' checked' : ''?> value="Y">
                </div>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Разделитель уровней разделов:</label>
                    <input type="text" name="MAP_SECTION_LEVEL_DELIM" value="<?=htmlspecialcharsEx($sectionLevelDelim)?>">
                </div>
                <!-- несколько разделов в одной ячейке разделяются значением «Разделитель множ. значения» выше -->
                <?php endif; ?>

                <?php if ($propType === 'L'): ?>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Создавать отсутствующие значения списка:</label>
                    <input type="checkbox" name="MAP_CREATE_ENUM"<?=$createEnum === 'Y' ? ' checked' : ''?> value="Y">
                </div>
                <?php endif; ?>

                <?php if ($isProperty && ($activeBxProp['MULTIPLE'] ?? '') === 'Y'): ?>
                <div class="awz-allxls-mapdlg__row">
                    <label class="awz-allxls-mapdlg__label">Множественное значение:</label>
                    <input type="hidden" name="MAP_MULTI" value="<?=htmlspecialcharsEx($multi)?>">
                    <div class="awz-allxls-mapdlg__toggles" data-toggle-group="single" data-toggle-target="MAP_MULTI">
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$multi === '' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="">перезаписать</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$multi === 'append' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="append">дополнить</button>
                        <button type="button" class="awz-allxls-mapdlg__toggle<?=$multi === 'delete' ? ' awz-allxls-mapdlg__toggle--active' : ''?>" data-value="delete">удалить</button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="awz-allxls-mapdlg__section">Правила обработки</div>

                <?php
                $rules = (isset($settings['RULES']) && is_array($settings['RULES'])) ? $settings['RULES'] : [];
                $uniq = 'awz_map_cond_' . md5($id . '|' . $field . '|' . $sheet . '|' . $from . '|' . $key) . '_' . mt_rand(1000, 999999);
                $condRules = self::buildCondRules($groups, $columns, $from, $key);
                $condValues = self::buildCondValues($rules, $columns, $from, $key);
                ?>
                <form id="<?= $uniq ?>_form" data-map-cond-form onsubmit="return false;">
                    <div id="<?= $uniq ?>"></div>
                </form>
                <script>
                    (window.AwzAllxlsMapCondQueue = window.AwzAllxlsMapCondQueue || []).push({
                        container: '<?= $uniq ?>',
                        form: '<?= $uniq ?>_form',
                        prefix: 'maprule',
                        rules: <?= \Bitrix\Main\Web\Json::encode($condRules) ?>,
                        values: <?= \Bitrix\Main\Web\Json::encode($condValues) ?>,
                        macros: <?= \Bitrix\Main\Web\Json::encode(self::getMacros($columns, $from, $groups ?? [])) ?>
                    });
                </script>
                <style>
                <?foreach(self::getRuleActions(true) as $visibleId=>$visibleAr){?>
                    <?foreach(['aval','aval2'] as $action){?>
                        <?if(!in_array($action, $visibleAr)){?>
                        .awz-allxls-mapdlg a[data-a="<?=$visibleId?>"][data-inp="<?=$action?>"], .awz-allxls-mapdlg a[data-a="<?=$visibleId?>"][data-inp="<?=$action?>"] + input + a {display: none!important;}
                        <?}?>
                    <?}?>
                <?}?>
                <?foreach(self::getRuleOperators(true) as $visibleId=>$visibleAr){?>
                    <?foreach(['value','value2'] as $action){?>
                        <?if(!in_array($action, $visibleAr)){?>
                        .awz-allxls-mapdlg a[data-op="<?=$visibleId?>"][data-inp="<?=$action?>"], .awz-allxls-mapdlg a[data-op="<?=$visibleId?>"][data-inp="<?=$action?>"] + input + a {display: none!important;}
                        <?}?>
                    <?}?>
                <?}?>
                </style>
            </div>
            <?php
            $html = ob_get_clean();

            return [
                'id' => $id,
                'field' => $field,
                'name' => $fieldName,
                'sheet' => $sheet,
                'html' => $html,
            ];
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Варианты «Типа привязки значения» для свойства инфоблока
     * (диалог настроек сопоставления). Как интерпретируется значение из файла:
     *  - ''   — авто (текущее поведение: ID/XML_ID/название/код на усмотрение резолвера);
     *  - id   — только ID;
     *  - xml_id — только XML_ID;
     *  - name — только название;
     *  - code — символьный код (только для элементов E).
     *
     * Селект показывается для свойств, у которых привязка имеет смысл:
     * L (список), E (привязка к элементу), S:directory, S:ElementXmlID,
     * и для полей разделов (IBLOCK_SECTION / IBLOCK_SECTION_PARENT —
     * getSectionBindOptions). Остальные типы (S/N без пользовательского типа,
     * G и прочие) — не показывается.
     *
     * @param string $propType PROPERTY_TYPE свойства ('S','N','L','E','G'), '' — поле не свойство
     * @param string $userType USER_TYPE свойства (пользовательский тип после двоеточия)
     * @return array значение => название (пустой массив — селект не показывать)
     */
    public static function getPropertyBindOptions(string $propType, string $userType = '', array $prop = []): array
    {
        $bindable = $propType === 'L'
            || $propType === 'E'
            || ($propType === 'S' && in_array($userType, ['directory', 'ElementXmlID'], true));
        if (!$bindable) {
            return [];
        }
        // значение свойства S:ElementXmlID — XML_ID элемента, привязка не требуется
        if ($propType === 'S' && $userType === 'ElementXmlID') {
            return ['' => 'авто (значение — XML_ID элемента)'];
        }
        $options = [
            '' => 'авто (как есть)',
            'id' => 'ID',
            'xml_id' => 'XML_ID',
            'name' => 'Название',
        ];
        if ($propType === 'E') {
            $options['code'] = 'Код';
        }
        if ($propType === 'S' && $userType === 'directory') {
            // справочник — HL-блок: варианты привязки по значениям его колонок
            foreach (self::getDirectoryFields($prop) as $fieldName => $field) {
                $label = (string)($field['EDIT_FORM_LABEL'] ?? '')
                    ?: (string)($field['LIST_COLUMN_LABEL'] ?? '')
                    ?: $fieldName;
                $options['col_' . $fieldName] = 'Колонка: ' . $label . ' [' . $fieldName . ']';
            }
        }
        return $options;
    }

    /**
     * Варианты «Типа привязки значения» для полей разделов
     * (IBLOCK_SECTION / IBLOCK_SECTION_PARENT). Как интерпретируется
     * значение из файла при поиске/создании раздела:
     *  - ''     — авто (ID / название / путь по уровням);
     *  - id     — только ID раздела;
     *  - xml_id — только XML_ID раздела;
     *  - name   — только название;
     *  - code   — символьный код раздела.
     *
     * @return array значение => название
     */
    public static function getSectionBindOptions(): array
    {
        return [
            '' => 'авто (ID/название/код/путь)',
            'id' => 'ID',
            'xml_id' => 'XML_ID',
            'name' => 'Название',
            'code' => 'Код',
        ];
    }

    /**
     * Поля (колонки) справочника S:directory (HL-блок).
     * Настройки справочника лежат в USER_TYPE_SETTINGS свойства (TABLE_NAME),
     * по нему находится HL-блок, колонки — пользовательские поля HL-блока.
     *
     * @param array $prop строка описания свойства (PropertyTable / CIBlockProperty)
     * @return array [UF_CODE => описание поля], пустой массив — не удалось получить
     */
    public static function getDirectoryFields(array $prop): array
    {
        $settings = $prop['USER_TYPE_SETTINGS'] ?? '';
        if (is_string($settings)) {
            $settings = (array)(@unserialize($settings, ['allowed_classes' => false]) ?: []);
        }
        if (!is_array($settings)) {
            return [];
        }
        $tableName = (string)($settings['TABLE_NAME'] ?? '');
        if ($tableName === '' || !Loader::includeModule('highloadblock')) {
            return [];
        }
        try {
            $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
                'select' => ['ID'],
                'filter' => ['=TABLE_NAME' => $tableName],
                'limit' => 1,
            ])->fetch();
            if (!$hl) {
                return [];
            }
            return (array)$GLOBALS['USER_FIELD_MANAGER']->GetUserFields('HLBLOCK_' . (int)$hl['ID']);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Типы правил обработки
     */
    public static function getRuleTypes(): array
    {
        return [
            'transform' => 'Преобразовать значение',
            'skip' => 'Пропустить обработку строки',
            'delete' => 'Удалить элемент',
            'unset' => 'Не обрабатывать значение (unset)',
            'entity' => 'Преобразовать существующее значение',
        ];
    }

    /**
     * Операторы условий
     */
    public static function getRuleOperators($inp = false): array
    {
        return [
            'eq' => !$inp ? 'равно' : ['value'],
            'ne' => !$inp ? 'не равно' : ['value'],
            'gt' => !$inp ? 'больше' : ['value'],
            'lt' => !$inp ? 'меньше' : ['value'],
            'ge' => !$inp ? 'больше или равно' : ['value'],
            'le' => !$inp ? 'меньше или равно' : ['value'],
            'between' => !$inp ? 'от-до' : ['value','value2'],
            'contains' => !$inp ? 'содержит' : ['value'],
            'not_contains' => !$inp ? 'не содержит' : ['value'],
            'begins' => !$inp ? 'начинается на' : ['value'],
            'ends' => !$inp ? 'заканчивается на' : ['value'],
            'empty' => !$inp ? 'пустое' : [''],
            'not_empty' => !$inp ? 'не пустое' : [''],
            'regex' => !$inp ? 'соответствует регулярке' : ['value'],
            'not_regex' => !$inp ? 'не соответствует регулярке' : ['value'],
            'any' => !$inp ? 'любое' : ['']
        ];
    }

    /**
     * Действия преобразования
     */
    public static function getRuleActions($inp = false): array
    {
        return [
            'replace' => !$inp ? 'заменить на' : ['aval'],
            'del_substr' => !$inp ? 'удалить подстроку' : ['aval'],
            'replace_substr' => !$inp ? 'заменить подстроку на' : ['aval','aval2'],
            'prepend' => !$inp ? 'добавить в начало' : ['aval'],
            'append' => !$inp ? 'добавить в конец' : ['aval'],
            'lower' => !$inp ? 'привести к нижнему регистру' : [],
            'upper' => !$inp ? 'привести к верхнему регистру' : [],
            'firstupper' => !$inp ? 'привести первый символ к заглавному' : [],
            'cut_before' => !$inp ? 'обрезать до символа' : ['aval','aval2'],
            'cut_after' => !$inp ? 'обрезать после символа' : ['aval','aval2'],
            'translit' => !$inp ? 'транслитерировать' : [],
            'unique_code' => !$inp ? 'сгенерировать уникальный символьный код' : [],
            'round' => !$inp ? 'округлить до n знаков' : ['aval'],
            'mul' => !$inp ? 'умножить на' : ['aval'],
            'div' => !$inp ? 'разделить на' : ['aval'],
            'add' => !$inp ? 'прибавить число' : ['aval'],
            'sub' => !$inp ? 'вычесть число' : ['aval'],
            'inc_percent' => !$inp ? 'увеличить на процент' : ['aval'],
            'dec_percent' => !$inp ? 'уменьшить на процент' : ['aval'],
            // извлечь из JSON по ключу: aval — ключ (url/title/...),
            // aval2 — выходной разделитель множественных значений (по умолчанию ',')
            'json_key' => !$inp ? 'извлечь из JSON по ключу' : ['aval','aval2'],
            // сформировать финальное значение-JSON: aval — JSON-шаблон,
            // ключи/значения произвольные, в значениях работают макросы
            // (пример: {"VALUE":"#VALUE_PROCESSED#","TYPE":"text"})
            'build_json' => !$inp ? 'сформировать финальное значение (JSON)' : ['aval'],
            // сформировать HTML-таблицу из JSON: {"name":"Глубина","unit":"мм",
            // "value":"30...30"} или массив таких объектов; unit добавляется
            // к value; каждый ключ объекта — колонка (класс awz_json_td_<ключ>)
            'json_html' => !$inp ? 'сформировать HTML из JSON' : [],
            // сформировать новое значение: aval — JSON-описание создаваемой записи
            // (справочник: {"UF_NAME":"test","XML_ID":"test"}; элемент: {"NAME":"..","IBLOCK_ID":"5",...});
            // если привязка не найдена — запись создается и привязывается к ней
            'build_new' => !$inp ? 'сформировать новое значение (создать при отсутствии)' : ['aval'],
            'formula' => !$inp ? 'применить формулу' : ['aval']
        ];
    }

    /**
     * Действия, у которых нет параметра-значения
     */
    public static function getRuleActionsNoValue(): array
    {
        return ['lower', 'upper', 'firstupper', 'translit', 'unique_code', 'json_html'];
    }

    /**
     * Действия с двумя параметрами
     */
    public static function getRuleActionsTwoValues(): array
    {
        return ['replace_substr'];
    }

    /**
     * Предустановленный список макросов для вставки в поля правил обработки.
     * Ключ — макрос, значение — описание.
     */
    public static function getMacros(array $columns = [], string $from = '', array $groups = []): array
    {
        $macros = [
            '#VALUE#' => 'Начальное значение колонки-источника (до правил)',
            '#VALUE_PROCESSED#' => 'Текущее (обработанное) значение колонки-источника',
            '#ELEMENT_FOUND#' => 'Найден ли существующий элемент при поиске: Y/N',
            '#VALUE_FOUND#' => 'Найдено ли значение в словаре поля назначения: Y/N',
            // конкретные макросы #ENTITY_<КОД># по полям назначения добавляет
            // обработчик onAwzAllxlsGetMacros (Awz\Allxls\Handlers)
        ];
        foreach ($columns as $k => $v) {
            $letter = (string)($v['LETTER'] ?? '');
            $title = 'Колонка ' . ($letter ?: $k) . ($v['HEADER'] ? ': ' . $v['HEADER'] : '');
            // на каждую колонку два макроса: начальное (сырое из файла)
            // и преобразованное (после настроек и правил колонки) значения
            $macros['#COL_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $k)) . '#'] =
                $title . ' — начальное значение';
            $macros['#COL_PROCESSED_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $k)) . '#'] =
                $title . ' — обработанное значение';
        }
        // событие D7 (новое ядро): сторонние модули добавляют свои макросы.
        // Обработчик: EventManager::getInstance()->addEventHandler(
        //   'awz.allxls', 'onAwzAllxlsGetMacros', fn(\Bitrix\Main\Event $event) {
        //       $event->addResult(new \Bitrix\Main\EventResult(
        //           \Bitrix\Main\EventResult::SUCCESS,
        //           ['macros' => ['#MY_MACRO#' => 'Описание']]
        //       ));
        //   })
        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetMacros', [
            'macros' => $macros,
            'columns' => $columns,
            // группы полей назначения: обработчики могут добавить макросы
            // #ENTITY_<КОД># по конкретным полям (см. Awz\Allxls\Handlers)
            'groups' => $groups,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (isset($params['macros']) && is_array($params['macros'])) {
                    // Используем оператор + для сохранения ключей
                    $macros = $params['macros'] + $macros;
                }
            }
        }
        return $macros;
    }

    public static function getRuleCurrentValues(array $groups = [], array $columns = [], string $from = '', string $key = ''): array
    {
        $values = [];
        foreach($columns as $k=>$v){
            $letter = (string)($v['LETTER'] ?? '');
            $title = 'Колонка ' . ($letter !== '' ? $letter : $k) . ($v['HEADER'] ? ': ' . $v['HEADER'] : '');
            // на каждую колонку два источника: начальное (сырое из файла)
            // и преобразованное (после применения настроек и правил колонки)
            $values[$k] = $title . ' — начальное';
            $values['__PROCESSED_' . $k] = $title . ' — преобразованное';
        }
        // служебные признаки: источник-макрос, раскрывается в checkCondition
        // (#ELEMENT_FOUND# — найден ли элемент при поиске Y/N;
        //  #VALUE_FOUND# — найдено ли значение в словаре поля назначения Y/N)
        $values['#ELEMENT_FOUND#'] = 'Элемент найден при поиске (Y/N)';
        $values['#VALUE_FOUND#'] = 'Значение найдено в словаре (Y/N)';
        // поля найденного при поиске элемента: #ENTITY_<ПОЛЕ># — значение поля
        // существующего элемента, найденного при поиске (ID, NAME, CODE,
        // PROPERTY_XXX — по кодам полей назначения $groups; элемент не найден
        // или поле отсутствует — пустая строка).
        // Без модуля catalog макросы каталога (CATALOG_*) не выводятся.
        // Служебные поля без значения у найденного элемента
        // (IBLOCK_SECTION_PARENT, CATEGORY_PATH) в макросы не включаются.
        $catalogLoaded = \Bitrix\Main\Loader::includeModule('catalog');
        $excluded = ['IBLOCK_SECTION_PARENT' => true, 'CATEGORY_PATH' => true];
        $entityCodes = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_array($group['ITEMS'] ?? null)) continue;
            foreach ($group['ITEMS'] as $item) {
                $code = trim((string)($item['ID'] ?? ''));
                if ($code === '' || isset($entityCodes[$code]) || isset($excluded[$code])) continue;
                if (!$catalogLoaded && str_starts_with($code, 'CATALOG_')) continue;
                $entityCodes[$code] = true;
                $values['#ENTITY_' . strtoupper(preg_replace('/[^a-z0-9_]/i', '', $code)) . '#'] =
                    'Элемент: ' . (string)($item['NAME'] ?? $code);
            }
        }
        return $values;
    }

    /**
     * controlId правила в дереве условий по типу правила
     */
    public static function getRuleControlId(string $type): string
    {
        $map = [
            'transform' => 'ruleTransform',
            'skip' => 'ruleSkip',
            'delete' => 'ruleDelete',
            'unset' => 'ruleUnset',
            'entity' => 'ruleEntity',
        ];
        return $map[$type] ?? 'ruleTransform';
    }

    /**
     * Описания контролов для BX.TreeConditions (по аналогии с условиями скидок каталога).
     *
     * Структура:
     *  - CondGroup (корень) — набор правил;
     *  - правило (ruleTransform / ruleSkip / ruleEntity) — группа: действие в заголовке
     *    + вложенные условия (множественные, неограниченная вложенность через condGroup);
     *  - cond — условие (лист), condGroup — вложенная группа условий (И/ИЛИ).
     *
     * @param array $groups группы полей сущности
     * @return array
     */
    public static function buildCondRules(array $groups = [], array $columns = [], string $from = '', string $key = ''): array
    {
        $currentSourceValues = self::getRuleCurrentValues($groups, $columns, $from, $key);

        // визуальная логика И/ИЛИ для групп условий
        $groupVisual = [
            'controls' => ['fLevel', 'notLevel'],
            'values' => [
                ['fLevel' => 'AND', 'notLevel' => 'N'],
                ['fLevel' => 'OR', 'notLevel' => 'N'],
                ['fLevel' => 'AND', 'notLevel' => 'Y'],
                ['fLevel' => 'OR', 'notLevel' => 'Y'],
            ],
            'logic' => [
                ['style' => 'condition-logic-and', 'message' => 'И'],
                ['style' => 'condition-logic-or', 'message' => 'ИЛИ'],
                ['style' => 'condition-logic-and', 'message' => 'И НЕ'],
                ['style' => 'condition-logic-or', 'message' => 'ИЛИ НЕ'],
            ],
        ];

        $rules = [];

        // корневая группа — набор правил
        $rules[] = [
            'controlId' => 'CondGroup',
            'defaultText' => '',
            'group' => true,
            'label' => 'Добавить правило',
            'showIn' => [],
            'visual' => [
                'controls' => ['fLevel'],
                'values' => [['fLevel' => 'AND']],
                'logic' => [['style' => 'condition-logic-and', 'message' => '+']],
            ],
            'control' => [
                'Выполнять ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все правила'],
                    'defaultText' => 'все правила',
                    'defaultValue' => 'AND',
                ],
            ],
        ];

        // условие (лист) — доступно внутри любого правила и вложенной группы
        $rules[] = [
            'controlId' => 'cond',
            'group' => false,
            'label' => 'Условие',
            'defaultText' => 'Условие',
            'showIn' => ['ruleTransform', 'ruleSkip', 'ruleDelete', 'ruleUnset', 'ruleEntity', 'condGroup'],
            'control' => [
                'Если ',
                [
                    'type' => 'select',
                    'id' => 'source',
                    'name' => 'source',
                    'values' => $currentSourceValues,
                    'defaultValue' => $currentSourceValues[$from] ? $from : '',
                ],
                [
                    'type' => 'select',
                    'id' => 'op',
                    'name' => 'op',
                    'values' => self::getRuleOperators(),
                    'defaultValue' => 'eq',
                ],
                [
                    'type' => 'input',
                    'id' => 'value',
                    'name' => 'value',
                    'defaultValue' => '',
                ],
                [
                    'type' => 'input',
                    'id' => 'value2',
                    'name' => 'value2',
                    'defaultValue' => '',
                ],
            ],
        ];

        // вложенная группа условий — вложенность без ограничений
        $rules[] = [
            'controlId' => 'condGroup',
            'group' => true,
            'label' => 'Группа условий',
            'defaultText' => 'Группа условий',
            'showIn' => ['ruleTransform', 'ruleSkip', 'ruleDelete', 'ruleUnset', 'ruleEntity', 'condGroup'],
            'visual' => $groupVisual,
            'control' => [
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        // правило 1: преобразовать значение
        $rules[] = [
            'controlId' => 'ruleTransform',
            'group' => true,
            'label' => 'Преобразовать значение с файла',
            'defaultText' => 'Преобразовать значение с файла',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Действие со значением с файла: ',
                [
                    'type' => 'select',
                    'id' => 'action',
                    'name' => 'action',
                    'values' => self::getRuleActions(),
                    'defaultValue' => 'replace',
                ],
                [
                    'type' => 'input',
                    'id' => 'aval',
                    'name' => 'aval',
                    'defaultValue' => '',
                ],
                [
                    'type' => 'input',
                    'id' => 'aval2',
                    'name' => 'aval2',
                    'defaultValue' => '',
                ],
                'Если ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        // правило 2: пропустить обработку строки
        $rules[] = [
            'controlId' => 'ruleSkip',
            'group' => true,
            'label' => 'Пропустить обработку строки в файле',
            'defaultText' => 'Пропустить обработку строки в файле',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Пропустить обработку строки в файле, если ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        // правило 2b: удалить элемент (правило пропуска строки с удалением
        // найденного по фильтру поиска элемента; обработка в Pimport::deleteRowByRule)
        $rules[] = [
            'controlId' => 'ruleDelete',
            'group' => true,
            'label' => 'Удалить элемент',
            'defaultText' => 'Удалить элемент',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Удалить элемент, если ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        // правило 2c: не обрабатывать значение (unset) — поле исключается
        // из загрузки (не попадает в элемент), строка обрабатывается штатно
        $rules[] = [
            'controlId' => 'ruleUnset',
            'group' => true,
            'label' => 'Не обрабатывать значение',
            'defaultText' => 'Не обрабатывать значение',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Не обрабатывать значение, если ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        if(false) {
            // правило 3: преобразовать существующее значение
            $rules[] = [
                    'controlId' => 'ruleEntity',
                    'group' => true,
                    'label' => 'Преобразовать значение элемента',
                    'defaultText' => 'Преобразовать значение элемента',
                    'showIn' => ['CondGroup'],
                    'visual' => $groupVisual,
                    'control' => [
                            'Действие над значением элемента: ',
                            [
                                    'type' => 'select',
                                    'id' => 'action',
                                    'name' => 'action',
                                    'values' => self::getRuleActions(),
                                    'defaultValue' => 'replace',
                            ],
                            [
                                    'type' => 'input',
                                    'id' => 'aval',
                                    'name' => 'aval',
                                    'defaultValue' => '',
                            ],
                            [
                                    'type' => 'input',
                                    'id' => 'aval2',
                                    'name' => 'aval2',
                                    'defaultValue' => '',
                            ],
                            [
                                    'id' => 'fLevel',
                                    'name' => 'aggregator',
                                    'type' => 'select',
                                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                                    'defaultValue' => 'AND',
                            ],
                            [
                                    'id' => 'notLevel',
                                    'name' => 'not',
                                    'type' => 'select',
                                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                                    'defaultValue' => 'N',
                            ],
                    ],
            ];
        }

        return $rules;
    }

    /**
     * Значения дерева условий из сохраненных правил
     *
     * Формат правила:
     * [
     *   'TYPE' => 'transform'|'skip'|'entity',
     *   'AGG' => 'AND'|'OR',
     *   'CONDITIONS' => [ // дерево условий, вложенность без ограничений
     *      ['SOURCE'=>..., 'OP'=>..., 'VALUE'=>..., 'VALUE2'=>...],           // лист-условие
     *      ['AGG'=>'OR', 'CHILDREN'=>[ ... ]],                                 // вложенная группа
     *   ],
     *   'ACTION' => ..., 'ACTION_VALUE' => ..., 'ACTION_VALUE2' => ...,
     * ]
     *
     * Старый плоский формат (OP/VALUE на уровне правила) конвертируется автоматически.
     *
     * @param array $rules массив правил (SETTINGS.RULES)
     * @return array
     */
    public static function buildCondValues(array $rules, array $columns = [], string $from = '', string $key = ''): array
    {
        $children = [];
        $i = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $type = (string)($rule['TYPE'] ?? 'transform');
            $controlId = self::getRuleControlId($type);
            // id узла — простой индекс уровня: полное имя поля строится
            // как путь parentDivId + '__' + id узла (см. BX.TreeConditions.RenderLevel)
            $ruleId = (string)$i;

            // обратная совместимость: старый формат — условие прямо в правиле
            $conditions = (isset($rule['CONDITIONS']) && is_array($rule['CONDITIONS']))
                ? $rule['CONDITIONS']
                : [['OP' => $rule['OP'] ?? 'eq', 'VALUE' => $rule['VALUE'] ?? '', 'VALUE2' => $rule['VALUE2'] ?? '']];

            $agg = (string)($rule['AGG'] ?? 'AND');
            $values = [
                'fLevel' => ($agg === 'OR' || $agg === 'OR_NOT') ? 'OR' : 'AND',
                'notLevel' => ($agg === 'AND_NOT' || $agg === 'OR_NOT') ? 'Y' : 'N',
            ];
            if ($type !== 'skip' && $type !== 'delete' && $type !== 'unset') {
                $values['action'] = (string)($rule['ACTION'] ?? 'replace');
                $values['aval'] = (string)($rule['ACTION_VALUE'] ?? '');
                $values['aval2'] = (string)($rule['ACTION_VALUE2'] ?? '');
            }

            $children[] = [
                'id' => $ruleId,
                'controlId' => $controlId,
                'values' => $values,
                'children' => self::buildCondConditionNodes($conditions, $ruleId),
            ];
            $i++;
        }

        return [
            'id' => '0',
            'controlId' => 'CondGroup',
            'values' => [],
            'children' => $children,
        ];
    }

    /**
     * Построение узлов дерева условий (листы и вложенные группы)
     */
    protected static function buildCondConditionNodes(array $conditions, string $parentId): array
    {
        $nodes = [];
        $j = 0;
        foreach ($conditions as $cond) {
            if (!is_array($cond)) {
                continue;
            }
            $id = (string)$j;
            if (isset($cond['CHILDREN']) && is_array($cond['CHILDREN'])) {
                $agg = (string)($cond['AGG'] ?? 'AND');
                $nodes[] = [
                    'id' => $id,
                    'controlId' => 'condGroup',
                    'values' => [
                        'fLevel' => ($agg === 'OR' || $agg === 'OR_NOT') ? 'OR' : 'AND',
                        'notLevel' => ($agg === 'AND_NOT' || $agg === 'OR_NOT') ? 'Y' : 'N',
                    ],
                    'children' => self::buildCondConditionNodes($cond['CHILDREN'], $id),
                ];
            } else {
                $nodes[] = [
                    'id' => $id,
                    'controlId' => 'cond',
                    'values' => [
                        'source' => (string)($cond['SOURCE'] ?? ''),
                        'op' => (string)($cond['OP'] ?? 'eq'),
                        'value' => (string)($cond['VALUE'] ?? ''),
                        'value2' => (string)($cond['VALUE2'] ?? ''),
                    ],
                    'children' => [],
                ];
            }
            $j++;
        }
        return $nodes;
    }

}