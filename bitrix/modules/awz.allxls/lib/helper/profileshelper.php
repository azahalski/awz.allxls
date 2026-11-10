<?php
namespace Awz\Allxls\Helper;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Iblock\PropertyTable;
use Awz\Allxls\Logger;
use Awz\Allxls\Helper\FastJsonReader;
use Awz\Allxls\Helper\FastXmlReader;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Статические помощники профиля импорта (общие для API-контроллеров).
 */
class ProfilesHelper {

    /** @var array кеш транслит-кода строки (строки повторяются между элементами XML) */
    protected static array $translitCache = [];

    /**
     * Транслитерация строки в код-алиас колонки (дублирует JS-функцию translit)
     *
     * @param string $text исходный текст заголовка
     * @return string
     */
    public static function transliterate(string $text): string
    {
        if ($text === '') return '';
        if (isset(self::$translitCache[$text])) {
            return self::$translitCache[$text];
        }
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ];
        $lower = mb_strtolower($text);
        $result = '';
        $len = mb_strlen($lower);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($lower, $i, 1);
            if (isset($map[$ch])) {
                $result .= $map[$ch];
            } elseif (preg_match('/[a-z0-9]/', $ch)) {
                $result .= $ch;
            } elseif ($ch === ' ' || $ch === '-' || $ch === '_') {
                $result .= '_';
            }
        }
        $result = preg_replace('/_+/', '_', $result);
        $result = trim($result, '_');
        return self::$translitCache[$text] = $result;
    }

    /**
     * Приведение значения CSV к UTF-8 (файлы в cp1251 перекодируются).
     *
     * @param string $value исходное значение
     * @return string
     */
    public static function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $converted = @iconv('cp1251', 'utf-8//IGNORE', $value);
        return $converted === false ? $value : $converted;
    }

    /**
     * Список типов приёмников (DEST_TYPE) для шага 2 мастера.
     * Базовые: iblock (инфоблок), orm (ORM-сущность). Сторонние модули
     * добавляют свои или подменяют описания через событие D7
     * onAwzAllxlsGetDestTypes (EventResult(SUCCESS, ['types' => [...]])).
     * Новый сервис-приёмник — класс, реализующий Awz\Allxls\Services\Base,
     * зарегистрированный в .settings.php модуля (или своем) под ключом
     * 'awz.allxls.<dest_type>'.
     *
     * @param string $profileType тип профиля (import|export) — для фильтрации обработчиком
     * @return array [code => title]
     */
    public static function getDestTypes(string $profileType = 'import'): array
    {
        $types = [
            'iblock' => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_TYPE_IBLOCK') ?: 'Инфоблок',
            'orm' => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_TYPE_ORM') ?: 'ORM-сущность',
        ];
        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetDestTypes', [
            'types' => $types,
            'profile_type' => $profileType,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            $params = $result->getParameters();
            if (is_array($params['types'] ?? null)) {
                $types = array_merge($types, $params['types']);
            }
        }
        return $types;
    }

    /** @var array|null статический кеш единиц измерения (ID => строка b_catalog_measure) */
    private static ?array $measuresCache = null;
    /** @var array|null статический кеш ставок НДС (ID => строка b_catalog_vat) */
    private static ?array $vatsCache = null;
    /** @var array|null статический кеш складов (ID => строка b_catalog_store) */
    private static ?array $storesCache = null;

    /**
     * Список единиц измерения каталога (Магазин -> Настройки -> Единицы измерения).
     * Справочник подгружается по мере необходимости и держится в статическом кеше.
     *
     * @return array [ID => ['ID','CODE','MEASURE_TITLE','SYMBOL_RUS','SYMBOL_INTL','IS_DEFAULT']]
     */
    public static function getMeasures(): array
    {
        if (self::$measuresCache !== null) {
            return self::$measuresCache;
        }
        self::$measuresCache = [];
        if (Loader::includeModule('catalog')) {
            $rs = \Bitrix\Catalog\MeasureTable::getList([
                'select' => ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_RUS', 'SYMBOL_INTL', 'IS_DEFAULT'],
                'order' => ['CODE' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                self::$measuresCache[(int)$row['ID']] = $row;
            }
        }
        return self::$measuresCache;
    }

    /**
     * Резолв значения единицы измерения: числовое — ID, иначе поиск по
     * MEASURE_TITLE / SYMBOL_RUS / SYMBOL_INTL / CODE (без учета регистра).
     *
     * @param mixed $value значение из файла импорта
     * @return int ID единицы измерения (0 — не найдено)
     */
    public static function resolveMeasureId($value): int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }
        $upper = mb_strtoupper($value);
        foreach (self::getMeasures() as $measure) {
            if (mb_strtoupper((string)$measure['MEASURE_TITLE']) === $upper
                || mb_strtoupper((string)$measure['SYMBOL_RUS']) === $upper
                || mb_strtoupper((string)$measure['SYMBOL_INTL']) === $upper
                || (string)$measure['CODE'] === $value
            ) {
                return (int)$measure['ID'];
            }
        }
        return 0;
    }

    /**
     * Список активных ставок НДС каталога. Справочник подгружается
     * по мере необходимости и держится в статическом кеше.
     *
     * @return array [ID => ['ID','NAME','RATE','ACTIVE','SORT']]
     */
    public static function getVats(): array
    {
        if (self::$vatsCache !== null) {
            return self::$vatsCache;
        }
        self::$vatsCache = [];
        if (Loader::includeModule('catalog')) {
            $rs = \Bitrix\Catalog\VatTable::getList([
                'select' => ['ID', 'NAME', 'RATE', 'ACTIVE', 'SORT'],
                'filter' => ['ACTIVE' => 'Y'],
                'order' => ['SORT' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                self::$vatsCache[(int)$row['ID']] = $row;
            }
        }
        return self::$vatsCache;
    }

    /**
     * Резолв значения ставки НДС: числовое — ID, иначе поиск по NAME
     * или по размеру ставки (RATE, допускается запись «20», «20%», «20,00»,
     * а также «без НДС» для нулевой ставки).
     *
     * @param mixed $value значение из файла импорта
     * @return int ID ставки НДС (0 — не найдено)
     */
    public static function resolveVatId($value): int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }
        $vats = self::getVats();
        // поиск по названию
        $upper = mb_strtoupper($value);
        foreach ($vats as $vat) {
            if (mb_strtoupper((string)$vat['NAME']) === $upper) {
                return (int)$vat['ID'];
            }
        }
        // поиск по размеру ставки (20, 20%, 20.00, «без НДС»)
        $rateStr = str_replace(['%', ' '], ['', ''], $value);
        $rateStr = str_replace(',', '.', $rateStr);
        $isNoVat = in_array(mb_strtoupper(trim($value)), ['БЕЗ НДС', 'БЕЗ НДС.', 'NO VAT', '0'], true);
        if (is_numeric($rateStr) || $isNoVat) {
            $rate = $isNoVat ? 0.0 : round((float)$rateStr, 4);
            foreach ($vats as $vat) {
                if (round((float)$vat['RATE'], 4) === $rate) {
                    return (int)$vat['ID'];
                }
            }
        }
        return 0;
    }

    /**
     * Список складов каталога. Справочник подгружается по мере
     * необходимости и держится в статическом кеше.
     *
     * @return array [ID => ['ID','CODE','TITLE','ACTIVE']]
     */
    public static function getStores(): array
    {
        if (self::$storesCache !== null) {
            return self::$storesCache;
        }
        self::$storesCache = [];
        if (Loader::includeModule('catalog')) {
            $rs = \Bitrix\Catalog\StoreTable::getList([
                'select' => ['ID', 'CODE', 'TITLE', 'ACTIVE'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                self::$storesCache[(int)$row['ID']] = $row;
            }
        }
        return self::$storesCache;
    }

    /**
     * Группированный список полей профиля (базовые поля + свойства инфоблока + событие).
     *
     * @param array $profile строка профиля
     * @param int   $profileId ID профиля (для события)
     * @return array
     */
    public static function getFieldGroups(array $profile, int $profileId = 0): array
    {
        $groups = [];
        $iblockId = 0;
        // свойства инфоблока, если он привязан к профилю
        $settings = $profile['SETTINGS'] ?? null;
        $settings['DEST_TYPE'] = $settings['DEST_TYPE'] ?? '';

        if($settings['DEST_TYPE']=='iblock' && Loader::includeModule('iblock')){
            $iblockId = (int)($settings['IBLOCK_ID'] ?? 0);
            // базовые поля элемента
            if($iblockId > 0){
                $groups[] = [
                    'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_BASE') ?: 'Поля',
                    'ITEMS' => [
                        ['ID' => 'ID', 'NAME' => 'Идентификатор'],
                        ['ID' => 'XML_ID', 'NAME' => 'Внешний код'],
                        ['ID' => 'NAME', 'NAME' => 'Название'],
                        ['ID' => 'ACTIVE', 'NAME' => 'Активность'],
                        ['ID' => 'CODE', 'NAME' => 'Символьный код'],
                        ['ID' => 'SORT', 'NAME' => 'Сортировка'],
                        ['ID' => 'PREVIEW_TEXT', 'NAME' => 'Описание для анонса'],
                        ['ID' => 'PREVIEW_TEXT_TYPE', 'NAME' => 'Тип описания для анонса (text/html)'],
                        ['ID' => 'DETAIL_TEXT', 'NAME' => 'Детальное описание'],
                        ['ID' => 'DETAIL_TEXT_TYPE', 'NAME' => 'Тип детального описания (text/html)'],
                        ['ID' => 'IBLOCK_SECTION', 'NAME' => 'Раздел'],
                        ['ID' => 'IBLOCK_SECTION_PARENT', 'NAME' => 'Родительский раздел'],
                        ['ID' => 'CATEGORY_PATH', 'NAME' => 'Путь разделов из categoryId (category_path)'],
                        ['ID' => 'PREVIEW_PICTURE', 'NAME' => 'Картинка анонса'],
                        ['ID' => 'DETAIL_PICTURE', 'NAME' => 'Детальная картинка']
                    ],
                ];

                $props = [];

                // Получаем свойства инфоблока через ORM-класс PropertyTable (D7)
                $propertyIterator = PropertyTable::getList([
                    'select' => ['*'],
                    'filter' => [
                        '=IBLOCK_ID' => $iblockId,
                        '=ACTIVE' => 'Y',
                        //'=PROPERTY_TYPE' => PropertyTable::TYPE_STRING // Соответствует старому типу 'S'
                    ],
                    'order' => ['SORT' => 'ASC', 'ID' => 'ASC']
                ]);

                while ($prop = $propertyIterator->fetch()) {

                    if(!in_array($prop['PROPERTY_TYPE'], [
                        PropertyTable::TYPE_STRING, PropertyTable::TYPE_NUMBER,
                        PropertyTable::TYPE_LIST, PropertyTable::CHECKBOX, PropertyTable::LISTBOX,
                        PropertyTable::TYPE_ELEMENT, PropertyTable::TYPE_SECTION,
                        PropertyTable::TYPE_FILE
                    ])){
                        continue;
                    }

                    if ($prop['CODE']) {
                        $prop['ORIGINAL_ID'] = $prop['ID'];
                        $prop['ID'] = 'PROPERTY_' . $prop['CODE'];
                        $prop['NAME'] = $prop['PROPERTY_TYPE'].': '.$prop['NAME'] . ' [' . $prop['CODE'] . ']';
                    }

                    $props[] = $prop;
                }
                if ($props) {
                    $groups[] = [
                        'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_PROPS') ?: 'Свойства',
                        'ITEMS' => $props,
                    ];
                }

                // цены торгового каталога: типы цен берутся с системы (модуль catalog)
                if (Loader::includeModule('catalog')) {
                    $priceItems = [];
                    $priceIterator = \CCatalogGroup::GetList(
                        ['SORT' => 'ASC', 'ID' => 'ASC'],
                        [],
                        false,
                        false,
                        ['ID', 'NAME', 'NAME_LANG', 'SORT']
                    );
                    while ($priceType = $priceIterator->Fetch()) {
                        $priceTypeId = (int)$priceType['ID'];
                        $name = (string)($priceType['NAME_LANG'] ?? '') !== ''
                            ? (string)$priceType['NAME_LANG']
                            : (string)$priceType['NAME'];
                        $title = 'Цена: ' . $name . ' [' . $priceTypeId . ']';
                        $priceItems[] = [
                            'ID' => 'CATALOG_PRICE_' . $priceTypeId,
                            'NAME' => $title,
                        ];
                        // валюта цены (обязательна для \CPrice; если не задана —
                        // берется базовая валюта модуля торгового каталога)
                        $priceItems[] = [
                            'ID' => 'CATALOG_PRICE_' . $priceTypeId . '_CURRENCY',
                            'NAME' => 'Валюта ' . $title,
                        ];
                    }
                    if ($priceItems) {
                        $groups[] = [
                            'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_PRICES') ?: 'Цены',
                            'ITEMS' => $priceItems,
                        ];
                    }

                    // каталожные поля товара (b_catalog_product)
                    $catalogItems = [];
                    foreach ([
                        'WEIGHT' => 'Вес',
                        'WIDTH' => 'Ширина',
                        'LENGTH' => 'Длина',
                        'HEIGHT' => 'Высота',
                        'QUANTITY' => 'Остаток',
                        'QUANTITY_RESERVED' => 'Зарезервированный остаток',
                        'MEASURE' => 'Единица измерения',
                        'VAT_ID' => 'Ставка НДС',
                        'VAT_INCLUDED' => 'НДС включен в цену (Y/N)',
                        'CAN_BUY_ZERO' => 'Разрешить покупку при нуле (Y/N)',
                        'NEGATIVE_AMOUNT_TRACE' => 'Разрешить отрицательный остаток (Y/N)',
                        'QUANTITY_TRACE' => 'Включен количественный учет (Y/N)',
                        'PURCHASING_PRICE' => 'Закупочная цена',
                        'PURCHASING_CURRENCY' => 'Валюта закупочной цены',
                    ] as $fieldCode => $fieldTitle) {
                        $catalogItems[] = [
                            'ID' => 'CATALOG_' . $fieldCode,
                            'NAME' => 'Каталог: ' . $fieldTitle,
                        ];
                    }
                    $groups[] = [
                        'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_CATALOG') ?: 'Каталог',
                        'ITEMS' => $catalogItems,
                    ];

                    // UF-поля товара (b_catalog_product), например UF_PRODUCT_GROUP
                    $ufItems = [];
                    $ufIterator = \CUserTypeEntity::GetList(
                        [],
                        ['ENTITY_ID' => 'PRODUCT']
                    );
                    while ($uf = $ufIterator->Fetch()) {
                        if (!$uf['FIELD_NAME']) continue;
                        $title = (string)($uf['EDIT_FORM_LABEL'] ?? '') !== ''
                            ? $uf['EDIT_FORM_LABEL']
                            : $uf['FIELD_NAME'];
                        $ufItems[] = [
                            'ID' => 'CATALOG_' . $uf['FIELD_NAME'],
                            'NAME' => 'Каталог: ' . $title . ' [' . $uf['FIELD_NAME'] . ']',
                        ];
                    }
                    if ($ufItems) {
                        $groups[] = [
                            'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_CATALOG_UF') ?: 'Каталог (UF-поля)',
                            'ITEMS' => $ufItems,
                        ];
                    }

                    // остатки по конкретным складам (b_catalog_store_product):
                    // CATALOG_STORE_<ID> — целевой остаток на складе
                    $storeItems = [];
                    foreach (self::getStores() as $store) {
                        $storeTitle = trim((string)($store['TITLE'] ?? ''));
                        if ($storeTitle === '') {
                            $storeTitle = trim((string)($store['CODE'] ?? ''));
                        }
                        if ($storeTitle === '') {
                            $storeTitle = (string)$store['ID'];
                        }
                        $storeItems[] = [
                            'ID' => 'CATALOG_STORE_' . (int)$store['ID'],
                            'NAME' => 'Остаток на складе: ' . $storeTitle . ' [' . (int)$store['ID'] . ']',
                        ];
                    }
                    if ($storeItems) {
                        $groups[] = [
                            'NAME' => Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FIELDS_STORES') ?: 'Склады',
                            'ITEMS' => $storeItems,
                        ];
                    }
                }
            }
        }

        // кастомные поля профиля (задаются на этапе настроек полей,
        // могут отсутствовать в текущем файле источника)
        $customFields = $profile['CUSTOM_FIELDS'] ?? [];
        if (!is_array($customFields)) {
            $customFields = [];
        }
        if ($customFields) {
            $customItems = [];
            foreach ($customFields as $customField) {
                if (!is_array($customField)) {
                    continue;
                }
                $code = trim((string)($customField['CODE'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $customItems[] = [
                    'ID' => $code,
                    'NAME' => trim((string)($customField['NAME'] ?? '')) . ' [' . $code . ']',
                ];
            }
            if ($customItems) {
                $groups[] = [
                    'NAME' => 'Кастомные поля',
                    'ITEMS' => $customItems,
                ];
            }
        }

        // событие для расширения списка полей
        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetProfileFields', [
            'PROFILE_ID' => $profileId,
            'IBLOCK_ID' => $iblockId,
            'GROUPS' => $groups,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (is_array($params['GROUPS'] ?? null)) {
                    $groups = $params['GROUPS'];
                }
            }
        }

        return $groups;
    }
    /**
     * Готовые значения времени жизни кеша внешнего источника (сек => название)
     */
    public static function fileCacheLifetimes(): array
    {
        return [
            0 => 'Обновлять при каждом запуске',
            1800 => 'Не чаще 1 раза в 30 минут',
            3600 => 'Не чаще 1 раза в час',
            7200 => 'Не чаще 1 раза в 2 часа',
            21600 => 'Не чаще 1 раза в 6 часов',
            43200 => 'Не чаще 1 раза в 12 часов',
            86400 => 'Не чаще 1 раза в сутки',
            604800 => 'Не чаще 1 раза в неделю',
        ];
    }

    /**
     * Путь к файлу источника — внешняя ссылка (http/https/ftp/ftps)?
     */
    public static function isExternalPath(string $filePath): bool
    {
        return (bool)preg_match('#^(https?|ftps?)://#i', trim((string)$filePath));
    }

    /**
     * Явно заданный тип файла источника (SETTINGS['FILE_EXT']):
     * 'auto' — определять по расширению пути; иначе csv|xlsx|xls|xml|yml.
     * Нужно для внешних ссылок без расширения в пути (например,
     * https://host/api/export?id=1 — отдает CSV, но об этом знает только пользователь).
     */
    public static function forcedFileExt(array $settings): string
    {
        $ext = strtolower(trim((string)($settings['FILE_EXT'] ?? '')));
        return in_array($ext, ['csv', 'xlsx', 'xls', 'xml', 'yml', 'json'], true) ? $ext : '';
    }

    /**
     * Расширение файла источника с учетом явно заданного типа (FILE_EXT).
     * Пустой путь/неизвестное расширение → '' (формат определяется читателем).
     */
    public static function fileExtOf(string $absPath, array $settings = []): string
    {
        $forced = self::forcedFileExt($settings);
        if ($forced !== '') return $forced;
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return in_array($ext, ['csv', 'xlsx', 'xls', 'xml', 'yml', 'json', 'gz'], true) ? $ext : '';
    }

    /**
     * Локальный кеш-файл внешнего источника профиля:
     * /upload/awz.allxls/cache/profile_<ID>_<md5(url)>.<ext>
     * (ид профиля + md5-хеш ссылки; расширение — из пути ссылки
     * либо явно заданное в SETTINGS['FILE_EXT'])
     *
     * @return string относительный путь
     */
    public static function externalCacheRelPath(int $profileId, string $url, array $settings = []): string
    {
        $url = trim((string)$url);
        if ($url === '') return '';
        // явный тип файла имеет приоритет над расширением в пути ссылки
        $ext = self::forcedFileExt($settings);
        if ($ext === '') {
            $pathPart = (string)(parse_url($url, PHP_URL_PATH) ?: '');
            $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
            if ($ext === 'gz') {
                // .gz распаковывается на сервере: итоговое расширение — внутреннее
                $inner = strtolower(pathinfo($pathPart, PATHINFO_FILENAME) !== '' ? pathinfo(pathinfo($pathPart, PATHINFO_FILENAME), PATHINFO_EXTENSION) : '');
                $ext = ($inner !== '') ? $inner : 'csv';
            }
        }
        if ($ext === '' || !in_array($ext, ['csv', 'xlsx', 'xls', 'xml', 'yml', 'json'], true)) {
            // неизвестное расширение — без расширения (.tmp)
            $ext = '';
        }
        $hash = md5($url);
        return '/upload/awz.allxls/cache/profile_'.$profileId.'_'.$hash.($ext !== '' ? '.'.$ext : '.tmp');
    }

    /**
     * Скачивание файла по внешней ссылке в локальный путь (curl, поддержка
     * прокси http(s)://user:pass@host:port — как PROXY профиля).
     *
     * @param string $url ссылка
     * @param string $destPath абсолютный путь назначения
     * @param string $proxy опциональный прокси
     * @param int &$httpCode код HTTP-ответа
     * @return bool
     */
    public static function downloadUrl(string $url, string $destPath, string $proxy = '', int &$httpCode = 0): bool
    {
        // ftp/ftps: curl с передачей логина/пароля из URL (ftp://user:pass@host/path)
        if (preg_match('#^ftps?://#i', (string)$url)) {
            $ch = curl_init((string)$url);
            $out = @fopen($destPath, 'wb');
            if (!$out) {
                return false;
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $out,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'awz.allxls',
            ]);
            if (str_starts_with(strtolower($url), 'ftps://')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTPS);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_FTP);
            }
            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_errno($ch);
            curl_close($ch);
            fclose($out);
            if ($curlErr !== 0) {
                return false;
            }
            return (int)(@filesize($destPath) ?: 0) > 0;
        }

        $out = @fopen($destPath, 'wb');
        if (!$out) {
            return false;
        }
        $ch = curl_init((string)$url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'awz.allxls',
        ]);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, str_starts_with(strtolower($proxy), 'https://') ? CURLPROXY_HTTPS : CURLPROXY_HTTP);
        }
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);
        curl_close($ch);
        fclose($out);
        if ($curlErr !== 0 || ($httpCode > 0 && $httpCode >= 400)) {
            return false;
        }
        return (int)(@filesize($destPath) ?: 0) > 0;
    }

    /**
     * Распаковка gzip-файла (.gz) в указанный путь.
     * Копирует содержимое порциями — большие файлы не грузятся в память целиком.
     *
     * @param string $srcPath путь к .gz файлу
     * @param string $destPath путь назначения (распакованный файл)
     * @return bool
     */
    public static function gunzipFile(string $srcPath, string $destPath): bool
    {
        if (!is_file($srcPath) || !function_exists('gzopen')) {
            return false;
        }
        $in = @gzopen($srcPath, 'rb');
        if (!$in) {
            // не gzip либо поврежден — считаем ошибкой
            return false;
        }
        $out = @fopen($destPath, 'wb');
        if (!$out) {
            gzclose($in);
            return false;
        }
        while (!gzeof($in)) {
            $chunk = gzread($in, 262144);
            if ($chunk === false || $chunk === '') break;
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
        return (int)(@filesize($destPath) ?: 0) > 0;
    }

    /**
     * Актуализация кеш-файла внешнего источника профиля.
     *
     * TTL берется из SETTINGS['FILE_CACHE_LIFETIME'] (сек, 0 — обновлять
     * при каждом вызове; по умолчанию 7200 — 2 часа). Свежий файл не
     * перекачивается. Скачивание идет во временный файл рядом с кешем
     * с атомарной заменой; при ошибке старый кеш сохраняется.
     *
     * @param array $profile строка профиля (SETTINGS)
     * @param bool $force игнорировать TTL (принудительное обновление)
     * @return array{status: string, file_path: string, http: int, message: string}
     *         status: skipped|fresh|downloaded|failed
     */
    public static function refreshProfileSource(array $profile, bool $force = false): array
    {
        $id = (int)($profile['ID'] ?? 0);
        $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
        $filePath = (string)($settings['FILE_PATH'] ?? '');
        if (!self::isExternalPath($filePath)) {
            return ['status' => 'skipped', 'file_path' => $filePath, 'http' => 0, 'message' => ''];
        }

        $relCache = self::externalCacheRelPath($id, $filePath, $settings);
        $absCache = \Bitrix\Main\Application::getDocumentRoot().$relCache;
        $absDir = dirname($absCache);
        if (!is_dir($absDir)) {
            @mkdir($absDir, BX_DIR_PERMISSIONS ?? 0755, true);
        }

        $lifetime = max(0, (int)($settings['FILE_CACHE_LIFETIME'] ?? 7200));
        if (!$force && $lifetime > 0 && is_file($absCache)) {
            $age = time() - (int)(@filemtime($absCache) ?: 0);
            if ($age < $lifetime) {
                return ['status' => 'fresh', 'file_path' => $relCache, 'http' => 0, 'message' => ''];
            }
        }

        $httpCode = 0;
        $tmpPath = $absCache.'.downloading';
        $ok = self::downloadUrl($filePath, $tmpPath, (string)($settings['PROXY'] ?? ''), $httpCode);
        if (!$ok) {
            @unlink($tmpPath);
            Logger::warning(
                'Не удалось актуализировать файл внешнего источника',
                ['profile_id' => $id, 'url' => $filePath, 'http' => $httpCode]
            );
            return [
                'status' => 'failed',
                'file_path' => $relCache,
                'http' => $httpCode,
                'message' => 'HTTP '.$httpCode,
            ];
        }

        // gzip-файл (.gz или принудительный тип gz): распаковываем в кеш,
        // итоговое расширение — внутреннее (name.csv.gz → кеш .csv)
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext !== 'gz') {
            // при FILE_EXT=gz явный тип задается пользователем
            $ext = (self::forcedFileExt($settings) === '') ? strtolower(pathinfo((string)(parse_url($filePath, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION)) : self::forcedFileExt($settings);
        }
        if ($ext === 'gz') {
            $inner = strtolower(pathinfo(pathinfo((string)(parse_url($filePath, PHP_URL_PATH) ?: ''), PATHINFO_FILENAME), PATHINFO_EXTENSION));
            if ($inner === '' || !in_array($inner, ['csv', 'xlsx', 'xls', 'xml', 'yml', 'json'], true)) {
                $inner = self::forcedFileExt($settings);
            }
            $gunzipPath = $absCache.'.gunzip';
            $unpacked = self::gunzipFile($tmpPath, $gunzipPath);
            if ($unpacked) {
                @unlink($tmpPath);
                $tmpPath = $gunzipPath;
                if ($inner !== '' && $inner !== strtolower(pathinfo($absCache, PATHINFO_EXTENSION))) {
                    // имя кеша без расширения/с другим расширением — пересоздаем с верным
                    $hash = md5($filePath);
                    $relCache = '/upload/awz.allxls/cache/profile_'.$id.'_'.$hash.'.'.$inner;
                    $absCache = \Bitrix\Main\Application::getDocumentRoot().$relCache;
                }
            } else {
                Logger::warning(
                    'Не удалось распаковать gzip-файл внешнего источника',
                    ['profile_id' => $id, 'url' => $filePath]
                );
            }
        }

        // атомарная замена кеша
        if (is_file($absCache)) @unlink($absCache);
        if (!@rename($tmpPath, $absCache)) {
            $ok = @copy($tmpPath, $absCache);
            @unlink($tmpPath);
            if (!$ok) {
                Logger::error(
                    'Не удалось заменить кеш-файл внешнего источника',
                    ['profile_id' => $id, 'tmp' => $tmpPath, 'cache' => $absCache]
                );
                return ['status' => 'failed', 'file_path' => $relCache, 'http' => $httpCode, 'message' => 'rename fail'];
            }
        }
        @touch($absCache);
        Logger::info(
            'Файл внешнего источника скачан в кеш',
            ['profile_id' => $id, 'url' => $filePath, 'cache' => $relCache, 'size' => (int)filesize($absCache), 'http' => $httpCode]
        );
        return ['status' => 'downloaded', 'file_path' => $relCache, 'http' => $httpCode, 'message' => ''];
    }

    /**
     * Абсолютный путь файла источника профиля с учетом внешних ссылок:
     * для http/https/ftp/ftps — локальный кеш-файл (без скачивания).
     *
     * @return string абсолютный путь (файл может еще не существовать)
     */
    public static function sourceAbsPath(int $profileId, array $settings): string
    {
        $filePath = (string)($settings['FILE_PATH'] ?? '');
        if (self::isExternalPath($filePath)) {
            return \Bitrix\Main\Application::getDocumentRoot().self::externalCacheRelPath($profileId, $filePath, $settings);
        }
        return \Bitrix\Main\Application::getDocumentRoot().$filePath;
    }

    /**
     * Статус кеш-файла внешнего источника (для опроса шага 3):
     * {external, ready, age, lifetime, file_path}
     */
    public static function externalCacheStatus(int $profileId, array $settings): array
    {
        $filePath = (string)($settings['FILE_PATH'] ?? '');
        $external = self::isExternalPath($filePath);
        $result = [
            'external' => $external,
            'ready' => !$external,
            'age' => 0,
            'lifetime' => 0,
            'file_path' => $filePath,
        ];
        if (!$external) return $result;
        $relCache = self::externalCacheRelPath($profileId, $filePath, $settings);
        $absCache = \Bitrix\Main\Application::getDocumentRoot().$relCache;
        $result['file_path'] = $relCache;
        $result['lifetime'] = max(0, (int)($settings['FILE_CACHE_LIFETIME'] ?? 7200));
        if (is_file($absCache)) {
            $result['ready'] = true;
            $result['age'] = max(0, time() - (int)(@filemtime($absCache) ?: 0));
        }
        return $result;
    }

    /**
     * Актуализация кеш-файлов внешних источников профилей (cron/агент).
     * Вызывается из cron/run.php перед прогонами и из агента runAll.
     *
     * @param string $cronMode фильтр по способу запуска: '' — все профили,
     *                         'cron'|'cron_own'|'agent' — только свой режим
     * @param array $ids ограничение списком ID профиля (0 — без ограничения)
     * @return array[] список {id, name, status, message}
     */
    public static function refreshExternalProfiles(string $cronMode = '', array $ids = []): array
    {
        $result = [];
        $filter = ['=TYPE' => 'import'];
        if ($ids) $filter['@ID'] = array_map('intval', $ids);
        try {
            $rs = \Awz\Allxls\Tables\ProfilesTable::getList([
                'select' => ['ID', 'NAME', 'SETTINGS'],
                'filter' => $filter,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        while ($profile = $rs->fetch()) {
            $id = (int)$profile['ID'];
            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            if (!self::isExternalPath((string)($settings['FILE_PATH'] ?? ''))) continue;
            // фильтр по способу запуска: CRON_MODE — летучий ключ профиля
            if ($cronMode !== '' && (string)($settings['CRON_MODE'] ?? 'agent') !== $cronMode) continue;
            $refresh = self::refreshProfileSource($profile);
            $result[] = [
                'id' => $id,
                'name' => (string)$profile['NAME'],
                'status' => (string)$refresh['status'],
                'message' => (string)$refresh['message'],
            ];
        }
        return $result;
    }

    /**
     * Разбор строки «путей до элементов» XML в массив путей.
     * Разделители путей — запятая, точка с запятой или перенос строки.
     * Путь — теги через '->' или '/' (items->item, shop/offers/offer).
     *
     * @param string|array $raw значение настройки XML_PATHS
     * @param int $minSegments минимальная глубина пути (для JSON допустим один
     *        сегмент — товары в корне: products или корневой массив объектов)
     * @return array[] [['NAME'=>..,'PATH'=>..,'SEGMENTS'=>[]],...]
     */
    public static function parseXmlPaths($raw, int $minSegments = 2): array
    {
        if (is_array($raw)) {
            // массив путей либо сохраненные структуры [['NAME'=>..,'PATH'=>..,'SEGMENTS'=>[]],...]
            $parts = [];
            foreach ($raw as $part) {
                if (is_array($part)) {
                    $part = trim((string)($part['PATH'] ?? $part['NAME'] ?? ''));
                    if ($part !== '') $parts[] = $part;
                } else {
                    $parts[] = trim((string)$part);
                }
            }
            $raw = implode(',', $parts);
        }
        $raw = trim((string)$raw);
        if ($raw === '') return [];
        $parts = preg_split('/[,;\r\n]+/', $raw);
        $result = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part === '') continue;
            $segments = FastXmlReader::parsePath($part);
            if (count($segments) < $minSegments) continue;
            $result[] = [
                'NAME' => implode('->', $segments),
                'PATH' => implode('->', $segments),
                'SEGMENTS' => $segments,
            ];
        }
        return $result;
    }

    /**
     * Нормализация имени колонки для сравнения (без разделителей, lowercase)
     */
    public static function normColKey(string $key): string
    {
        return (string)preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($key)));
    }

    /**
     * Является ли путь XML_PATHS листом категорий (последний сегмент — 'category')
     */
    public static function isCategoryPath(string $path): bool
    {
        $segments = FastXmlReader::parsePath($path);
        if (!$segments) return false;
        return mb_strtolower(trim((string)end($segments))) === 'category';
    }

    /**
     * Построение дерева категорий из XML/YML файла.
     *
     * Формат элементов: <category id="111418" parentId="30119">Название</category>.
     * Разделитель '|' из названий вырезается. Возвращает:
     *  - path — путь листа категорий (categories->category);
     *  - map  — [id => ['name' => название, 'parent' => parentId|'']].
     *
     * @param string $absPath абсолютный путь к файлу
     * @param string $catPath путь до элементов категорий (XML_PATHS)
     * @return array{path: string, map: array}
     */
    public static function buildCategoryTree(string $absPath, string $catPath): array
    {
        $result = ['path' => $catPath, 'map' => []];
        if ($catPath === '' || !is_file($absPath)) {
            return $result;
        }
        $segments = FastXmlReader::parsePath($catPath);
        if (!$segments) {
            return $result;
        }
        $tagName = (string)end($segments);
        $reader = new \XMLReader();
        if (@$reader->open($absPath, null, LIBXML_NONET | LIBXML_COMPACT) === false) {
            return $result;
        }
        try {
            $map = [];
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== $tagName) {
                    continue;
                }
                $id = trim((string)$reader->getAttribute('id'));
                if ($id === '') continue;
                $parent = trim((string)$reader->getAttribute('parentId'));
                // дубликаты id: первый вариант названия остается в дереве
                if (isset($map[$id])) continue;
                $name = '';
                if ($reader->isEmptyElement) {
                    $name = '';
                } else {
                    $name = trim((string)$reader->readString());
                }
                $map[$id] = [
                    'name' => str_replace('|', '', $name),
                    'parent' => str_replace('|', '', $parent),
                ];
            }
            $result['map'] = $map;
        } catch (\Throwable $e) {
            // битый файл — возвращаем что успели собрать
        } finally {
            try { $reader->close(); } catch (\Throwable $e) {}
        }
        return $result;
    }

    /**
     * Путь категорий по ID (от корня к элементу, разделитель '|').
     * Родитель отсутствует в дереве — путь начинается с известного предка.
     * Циклы/глубина ограничены (50 уровней). ID не найден — ''.
     *
     * @param array $map дерево категорий (buildCategoryTree)
     * @param string $id ID категории (categoryId из XML)
     * @return string
     */
    public static function categoryPathById(array $map, string $id, string $delim = '|'): string
    {
        $id = trim($id);
        if ($id === '' || !isset($map[$id])) {
            return '';
        }
        $names = [];
        $current = $id;
        $depth = 0;
        while ($current !== '' && isset($map[$current]) && $depth < 50) {
            $depth++;
            $item = $map[$current];
            $name = (string)($item['name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
            $parent = (string)($item['parent'] ?? '');
            if ($parent === '' || $parent === $current) break;
            // защита от циклов: родитель уже встречался — стоп
            if (isset($map[$parent]) && $parent === $id) break;
            $current = $parent;
        }
        if (!$names) {
            return '';
        }
        return implode($delim, array_reverse($names));
    }

    /**
     * Построение структуры «листов» из XML/YML файла по путям до элементов.
     * Каждый путь (items->item) — отдельный лист; шапка листа — union ключей
     * первых N элементов, HEADER_ROW всегда 1.
     *
     * @param string $absPath абсолютный путь к файлу
     * @param string|array $xmlPaths пути до элементов (XML_PATHS)
     * @param array $savedSheets сохраненные настройки листов (SHEET1/SHEET2/...)
     * @return array[] список структур листов (buildSheetData)
     */
    public static function buildXmlSheets(string $absPath, $xmlPaths, array $savedSheets = []): array
    {
        $paths = self::parseXmlPaths($xmlPaths);
        if (!$paths) return [];
        $pathStrings = array_map(function ($p) { return $p['PATH']; }, $paths);

        $sheets = [];
        $fast = new FastXmlReader($absPath, $pathStrings);
        $heads = $fast->readSheetsHead(30);
        // карта именованных колонок: ключ (param_xxx) => исходное значение
        // атрибута name (реальное название на русском) — для отображения в UI
        $namesMap = method_exists($fast, 'getNamesMap') ? $fast->getNamesMap() : [];
        // дерево категорий: если в файле есть <category id/parentId> —
        // в каждый лист (кроме листа категорий) добавляется синтетическая
        // колонка category_path (путь по дереву, '|' — вложенность,
        // ';' — множественность), заполняется при разборе строки импорта
        $catTree = self::buildCategoryTree($absPath, 'categories->category');
        $hasCatTree = !empty($catTree['map']);
        $catSheetKey = self::normColKey('categories->category');
        foreach ($heads as $headIndex => $head) {
            $sheetIndex = (int)($head['INDEX'] ?? $headIndex);
            $sheetKey = 'SHEET'.($sheetIndex + 1);
            $saved = is_array($savedSheets[$sheetKey] ?? null) ? $savedSheets[$sheetKey] : [];
            $rows = is_array($head['ROWS'] ?? null) ? $head['ROWS'] : [];
            // строка 1 — ключи колонок (шапка), строки 2..N — сэмплы данных
            $headers = array_values(array_map('strval', (array)($rows[1] ?? [])));
            $sampleRows = [];
            for ($rowNum = 2; $rowNum <= 21; $rowNum++) {
                if (!isset($rows[$rowNum])) break;
                $sampleRows[] = array_values((array)$rows[$rowNum]);
            }
            $highestColIndex = 0;
            foreach (array_merge([$headers], $sampleRows) as $r) {
                $highestColIndex = max($highestColIndex, is_array($r) ? count($r) : 0);
            }
            // синтетическая колонка category_path
            $isCatSheet = self::normColKey((string)$head['NAME']) === $catSheetKey;
            if ($hasCatTree && !$isCatSheet) {
                // сэмплы: разрешаем id из колонки categoryId в пути.
                // Ключ может быть с префиксом родительского тега
                // (offer_categoryid) — сравниваем по суффиксу
                $catIdIndex = -1;
                foreach ($headers as $hIdx => $hName) {
                    $hNorm = self::normColKey((string)$hName);
                    if ($hNorm === 'categoryid'
                        || (mb_strlen($hNorm) > 10 && mb_substr($hNorm, -10) === 'categoryid')) {
                        $catIdIndex = (int)$hIdx;
                        break;
                    }
                }
                foreach ($sampleRows as &$sampleRow) {
                    $sampleValue = '';
                    if ($catIdIndex >= 0 && isset($sampleRow[$catIdIndex])) {
                        foreach (preg_split('/[;,]+/u', trim((string)$sampleRow[$catIdIndex])) as $part) {
                            $part = trim((string)$part);
                            if ($part === '') continue;
                            $path = self::categoryPathById($catTree['map'], $part);
                            if ($path !== '') {
                                $sampleValue = $sampleValue === '' ? $path : $sampleValue.';'.$path;
                            }
                        }
                    }
                    $sampleRow[] = $sampleValue;
                }
                unset($sampleRow);
                $headers[] = 'category_path';
                $highestColIndex++;
            }
            $sheetData = self::buildSheetData(
                (string)$head['NAME'], $saved, 1, $headers, $sampleRows, $highestColIndex, $sheetKey
            );
            // TITLE: реальное название (name) для именованных колонок (param_*)
            // HEADER остается ключом XML — по нему выравниваются строки при импорте
            if ($namesMap) {
                foreach ($sheetData['COLUMNS'] as &$col) {
                    $ruName = (string)($namesMap[(string)$col['HEADER']] ?? '');
                    if ($ruName !== '') {
                        $col['TITLE'] = $ruName;
                    }
                }
                unset($col);
            }
            $sheets[] = $sheetData;
        }
        return $sheets;
    }

    /**
     * Построение структуры «листов» из JSON файла по путям до элементов.
     * Каждый путь (items->item) — отдельный лист; шапка листа — union ключей
     * первых N элементов, HEADER_ROW всегда 1. Чтение через FastJsonReader
     * (побайтовый потоковый сканер, как FastXmlReader у XML/YML).
     *
     * @param string $absPath абсолютный путь к файлу
     * @param string|array $xmlPaths пути до элементов (XML_PATHS)
     * @param array $savedSheets сохраненные настройки листов (SHEET1/SHEET2/...)
     * @return array[] список структур листов (buildSheetData)
     */
    public static function buildJsonSheets(string $absPath, $xmlPaths, array $savedSheets = []): array
    {
        // JSON: допустим один сегмент — товары в корне файла (products
        // либо корневой массив объектов)
        $paths = self::parseXmlPaths($xmlPaths, 1);
        if (!$paths) return [];
        $pathStrings = array_map(function ($p) { return $p['PATH']; }, $paths);

        $sheets = [];
        $fast = new FastJsonReader($absPath, $pathStrings);
        $heads = $fast->readSheetsHead(30);
        foreach ($heads as $headIndex => $head) {
            $sheetIndex = (int)($head['INDEX'] ?? $headIndex);
            $sheetKey = 'SHEET'.($sheetIndex + 1);
            $saved = is_array($savedSheets[$sheetKey] ?? null) ? $savedSheets[$sheetKey] : [];
            $rows = is_array($head['ROWS'] ?? null) ? $head['ROWS'] : [];
            // строка 1 — ключи колонок (шапка), строки 2..N — сэмплы данных
            $headers = array_values(array_map('strval', (array)($rows[1] ?? [])));
            $sampleRows = [];
            for ($rowNum = 2; $rowNum <= 21; $rowNum++) {
                if (!isset($rows[$rowNum])) break;
                $sampleRows[] = array_values((array)$rows[$rowNum]);
            }
            $highestColIndex = 0;
            foreach (array_merge([$headers], $sampleRows) as $r) {
                $highestColIndex = max($highestColIndex, is_array($r) ? count($r) : 0);
            }
            $sheets[] = self::buildSheetData(
                (string)$head['NAME'], $saved, 1, $headers, $sampleRows, $highestColIndex, $sheetKey
            );
        }
        return $sheets;
    }

    /**
     * Построение данных "листа" из CSV через штатный CCSVData (побайтовое чтение).
     *
     * @param string $absPath абсолютный путь к файлу
     * @param string $delimiter разделитель
     * @param array  $saved сохраненные настройки листа
     * @param array  $headerRows переопределение строки заголовков с клиента
     * @return array структура листа (NAME, ACTIVE, HEADER_ROW, COLUMNS, ...)
     */
    public static function buildCsvSheet(string $absPath, string $delimiter, array $saved = [], array $headerRows = []): array
    {
        $sheetName = 'CSV';
        $saved = is_array($saved) ? $saved : [];

        $headerRow = max(1, (int)($saved['HEADER_ROW'] ?? 1));
        if (isset($headerRows[$sheetName])) {
            $headerRow = max(1, (int)$headerRows[$sheetName]);
        }

        $headers = [];
        $sampleRows = [];

        $csv = new \CCSVData('R');
        $csv->LoadFile($absPath);
        $csv->SetDelimiter($delimiter === '' ? ',' : $delimiter);

        // пропускаем строки до строки заголовков включительно
        for ($i = 1; $i < $headerRow; $i++) {
            if ($csv->Fetch() === false) break;
        }
        $row = $csv->Fetch();
        if (is_array($row)) {
            $headers = array_map([self::class, 'toUtf8'], array_map('strval', $row));
        }

        // читаем до 10 строк данных для примеров значений
        for ($i = 0; $i < 10; $i++) {
            $row = $csv->Fetch();
            if (!is_array($row)) break;
            $sampleRows[] = array_map([self::class, 'toUtf8'], array_map('strval', $row));
        }
        unset($csv);

        $firstDataRow = $sampleRows[0] ?? [];
        $highestColIndex = 0;
        foreach (array_merge([$headers], $sampleRows) as $r) {
            $highestColIndex = max($highestColIndex, is_array($r) ? count($r) : 0);
        }

        $columns = [];
        $savedColumns = is_array($saved['COLUMNS'] ?? null) ? $saved['COLUMNS'] : [];

        // индексы сохраненных настроек по алиасу — соответствие колонок в первую
        // очередь по транслиту ЗАГОЛОВКА: колонки могут сдвигаться/добавляться,
        // позиционный ключ col_N в этом случае указывает на чужую колонку
        $savedByAlias = [];
        foreach ($savedColumns as $savedCol) {
            if (is_array($savedCol) && !empty($savedCol['ALIAS'])) {
                $savedByAlias[(string)$savedCol['ALIAS']] = $savedCol;
            }
        }

        for ($i = 0; $i < $highestColIndex; $i++) {
            $key = 'col_'.$i;
            $header = (string)($headers[$i] ?? '');
            // транслит текущего заголовка — основной ключ соответствия
            $headerAlias = ($header !== '') ? ProfilesHelper::transliterate($header) : '';

            $savedCol = [];
            if ($headerAlias !== '' && isset($savedByAlias[$headerAlias])) {
                // приоритет 1: сохраненная колонка с алиасом = транслит заголовка
                $savedCol = $savedByAlias[$headerAlias];
            } elseif (isset($savedColumns[$key])) {
                // приоритет 2: колонка не сдвигалась — соответствие по позиции
                $savedCol = is_array($savedColumns[$key]) ? $savedColumns[$key] : [];
            }
            // алиас: из найденной настройки, иначе транслит заголовка.
            // Самолечение: если сохраненный алиас не соответствует транслиту
            // текущего заголовка (остался от старой версии/другого файла),
            // пересчитываем от заголовка — иначе чужой алиас воспроизводится
            // при каждом сохранении
            $alias = (string)($savedCol['ALIAS'] ?? '');
            if ($headerAlias !== '' && $alias !== $headerAlias) {
                $alias = $headerAlias;
            }
            if ($alias === '') {
                $alias = $headerAlias;
            }
            // колонки param_* по умолчанию скрыты (без сохраненной настройки)
            $defaultInclude = (str_starts_with($alias, 'param_') || str_starts_with(mb_strtolower($header), 'param')) ? 'N' : 'Y';

            $columns[] = [
                'KEY' => $key,
                'INDEX' => $i,
                'LETTER' => Coordinate::stringFromColumnIndex($i + 1),
                'HEADER' => $header,
                'SAMPLE' => (string)($firstDataRow[$i] ?? ''),
                'SAMPLES' => array_map(function ($row) use ($i) {
                    return (string)($row[$i] ?? '');
                }, $sampleRows),
                'INCLUDE' => (($savedCol['INCLUDE'] ?? $defaultInclude) === 'N') ? 'N' : 'Y',
                'ALIAS' => $alias,
                'MAPS' => is_array($savedCol['MAPS'] ?? null) ? $savedCol['MAPS'] : [],
                'MAP_SETTINGS' => is_array($savedCol['MAP_SETTINGS'] ?? null) ? $savedCol['MAP_SETTINGS'] : [],
            ];
        }

        // порядок колонок из сохраненных настроек: сортировка (drag-and-drop)
        // задает порядок обработки строк при импорте — восстанавливаем ее
        $columns = self::applySavedColumnsOrder($columns, $savedColumns);

        return [
            'NAME' => $sheetName,
            'CODE' => 'CSV',
            'ACTIVE' => (($saved['ACTIVE'] ?? 'Y') === 'N') ? 'N' : 'Y',
            'HEADER_ROW' => $headerRow,
            'COLUMNS' => $columns,
            'SAMPLE_COLS' => max(1, min(10, (int)($saved['SAMPLE_COLS'] ?? 1))),
            'SAMPLE_HIDDEN' => array_values(array_filter(array_map('intval', (array)($saved['SAMPLE_HIDDEN'] ?? [])), function ($v) {
                return $v >= 1 && $v <= 9;
            })),
        ];
    }

    /**
     * Построение структуры листа из заголовков и строк-сэмплов (общее для быстрого и PhpSpreadsheet пути).
     *
     * @param string $sheetName имя листа
     * @param string $sheetCode безопасный код листа (SHEET1/SHEET2/...)
     * @param array $saved сохраненные настройки листа
     * @param int $headerRow строка заголовков
     * @param array $headers заголовки колонок
     * @param array $sampleRows строки-сэмплы
     * @param int $highestColIndex количество колонок
     * @return array структура листа
     */
    public static function buildSheetData(string $sheetName, array $saved, int $headerRow, array $headers, array $sampleRows, int $highestColIndex, string $sheetCode = ''): array
    {
        $firstDataRow = $sampleRows[0] ?? [];

        $columns = [];
        $savedColumns = is_array($saved['COLUMNS'] ?? null) ? $saved['COLUMNS'] : [];

        // индексы сохраненных настроек по алиасу (транслит-код) — приоритет,
        // при отсутствии заголовков настройки привязываются по порядковому номеру
        $savedByAlias = [];
        foreach ($savedColumns as $savedCol) {
            if (is_array($savedCol) && !empty($savedCol['ALIAS'])) {
                $savedByAlias[(string)$savedCol['ALIAS']] = $savedCol;
            }
        }

        for ($i = 0; $i < $highestColIndex; $i++) {
            $key = 'col_'.$i;
            $header = (string)($headers[$i] ?? '');
            // транслит текущего заголовка — основной ключ соответствия
            // (колонки могут сдвигаться: позиционный ключ col_N укажет на чужую колонку)
            $headerAlias = ($header !== '') ? ProfilesHelper::transliterate($header) : '';

            $savedCol = [];
            if ($headerAlias !== '' && isset($savedByAlias[$headerAlias])) {
                // приоритет 1: сохраненная колонка с алиасом = транслит заголовка
                $savedCol = $savedByAlias[$headerAlias];
            } elseif (isset($savedColumns[$key])) {
                // приоритет 2: колонка не сдвигалась — соответствие по позиции
                $savedCol = is_array($savedColumns[$key]) ? $savedColumns[$key] : [];
            }
            // алиас: из найденной настройки, иначе транслит заголовка.
            // Самолечение: если сохраненный алиас не соответствует транслиту
            // текущего заголовка (остался от старой версии/другого файла),
            // пересчитываем от заголовка — иначе чужой алиас воспроизводится
            // при каждом сохранении
            $alias = (string)($savedCol['ALIAS'] ?? '');
            if ($headerAlias !== '' && $alias !== $headerAlias) {
                $alias = $headerAlias;
            }
            if ($alias === '') {
                $alias = $headerAlias;
            }
            // колонки param_* по умолчанию скрыты (без сохраненной настройки)
            $defaultInclude = (str_starts_with($alias, 'param_') || str_starts_with(mb_strtolower($header), 'param')) ? 'N' : 'Y';

            $columns[] = [
                'KEY' => $key,
                'INDEX' => $i,
                'LETTER' => Coordinate::stringFromColumnIndex($i + 1),
                'HEADER' => $header,
                'SAMPLE' => (string)($firstDataRow[$i] ?? ''),
                'SAMPLES' => array_map(function ($row) use ($i) {
                    return (string)($row[$i] ?? '');
                }, $sampleRows),
                'INCLUDE' => (($savedCol['INCLUDE'] ?? $defaultInclude) === 'N') ? 'N' : 'Y',
                'ALIAS' => $alias,
                'MAPS' => is_array($savedCol['MAPS'] ?? null) ? $savedCol['MAPS'] : [],
                'MAP_SETTINGS' => is_array($savedCol['MAP_SETTINGS'] ?? null) ? $savedCol['MAP_SETTINGS'] : [],
            ];
        }

        // порядок колонок из сохраненных настроек: сортировка (drag-and-drop)
        // задает порядок обработки строк при импорте — восстанавливаем ее
        $columns = self::applySavedColumnsOrder($columns, $savedColumns);

        return [
            'NAME' => $sheetName,
            'CODE' => $sheetCode !== '' ? $sheetCode : $sheetName,
            'ACTIVE' => (($saved['ACTIVE'] ?? 'Y') === 'N') ? 'N' : 'Y',
            'HEADER_ROW' => $headerRow,
            'COLUMNS' => $columns,
            'SAMPLE_COLS' => max(1, min(10, (int)($saved['SAMPLE_COLS'] ?? 1))),
            'SAMPLE_HIDDEN' => array_values(array_filter(array_map('intval', (array)($saved['SAMPLE_HIDDEN'] ?? [])), function ($v) {
                return $v >= 1 && $v <= 9;
            })),
        ];
    }

    /**
     * Восстановление сохраненного порядка колонок (сортировка drag-and-drop на шаге 3).
     * Колонки строятся в порядке следования в файле (col_0, col_1, ...),
     * но порядок обработки при импорте задает сохраненный порядок —
     * переставляем построенные колонки в соответствии с $savedColumns.
     *
     * @param array $columns построенные по файлу колонки (KEY/ALIAS)
     * @param array $savedColumns сохраненные настройки колонок (в сохраненном порядке)
     * @return array колонки в сохраненном порядке (+ новые колонки файла в конце)
     */
    public static function applySavedColumnsOrder(array $columns, array $savedColumns): array
    {
        if (empty($savedColumns) || count($columns) < 2) {
            return $columns;
        }
        $byAlias = [];
        $byKey = [];
        foreach ($columns as $i => $col) {
            if (!empty($col['ALIAS'])) {
                $byAlias[(string)$col['ALIAS']] = $i;
            }
            $byKey[(string)($col['KEY'] ?? '')] = $i;
        }
        $ordered = [];
        $used = [];
        foreach ($savedColumns as $savedKey => $savedCol) {
            if (!is_array($savedCol)) continue;
            $idx = null;
            $alias = (string)($savedCol['ALIAS'] ?? '');
            // приоритет 1 — соответствие по алиасу (как в buildSheetData)
            if ($alias !== '' && isset($byAlias[$alias])) {
                $idx = $byAlias[$alias];
            }
            // приоритет 2 — по позиции (col_N), если колонка не сдвигалась
            if ($idx === null) {
                $key = (string)$savedKey;
                if (isset($byKey[$key])) {
                    $idx = $byKey[$key];
                }
            }
            if ($idx === null || isset($used[$idx])) continue;
            $used[$idx] = true;
            $ordered[] = $columns[$idx];
        }
        // новые колонки файла, которых не было в сохраненных настройках — в конце
        foreach ($columns as $i => $col) {
            if (!isset($used[$i])) $ordered[] = $col;
        }
        return $ordered;
    }

}
