<?php
namespace Awz\Allxls\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

use Awz\Allxls\Handlers;
use Awz\Allxls\Logger;
use Awz\Allxls\Helper\ProfilesHelper;
use Awz\Allxls\Tables\ProfilesTable;

class iblock implements Base
{
    /** @var int размер батча агента очистки устаревших элементов */
    const FINISH_CLEAN_BATCH = 200;
    /** @var float лимит времени одного шага агента очистки, сек */
    const FINISH_CLEAN_TIME_LIMIT = 20.0;
    /**
     * @inheritDoc
     */
    public function findElement(string $entityId, array $filter, array $select = ['*'])
    {
        if (empty($filter) || !Loader::includeModule('iblock')) {
            return null;
        }

        $isD7 = true;
        foreach($filter as $key=>$val){
            if(mb_strpos(mb_strtoupper($key), 'PROPERTY_')!==false){
                $isD7 = false;
                break;
            }
            if(mb_strpos(mb_strtoupper($key), 'CATALOG_')!==false){
                $isD7 = false;
                break;
            }
        }

        if($isD7){
            $res = \Bitrix\Iblock\ElementTable::getList([
                'select'=>$select,
                'filter'=>$filter,
                'limit'=>1
            ]);
            $row = $res->fetch();
        }else{
            // права не должны влиять на поиск элемента при импорте
            $filter['CHECK_PERMISSIONS'] = 'N';

            $res = \CIblockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                $select
            );
            $row = $res->fetch();
        }

        return $row ? $row : null;
    }

    /**
     * @inheritDoc
     */
    public function addElement(string $entityId, array $fields): int
    {
        $fields['IBLOCK_ID'] = $entityId;
        $fields = $this->prepareFields($fields);
        $system = $fields['SYSTEM'] ?? [];
        $catalog = $fields['CATALOG'] ?? [];
        // список всех разделов элемента (основной — IBLOCK_SECTION в полях)
        $sectionList = $fields['IBLOCK_SECTION_LIST'] ?? null;
        unset($fields['IBLOCK_SECTION_LIST']);
        unset($fields['SYSTEM']);
        unset($fields['CATALOG']);
        unset($fields['PROPERTY_MULTI']);
        unset($system['SEARCH_UPDATE']);

        // PROPERTY_VALUES: у множественных свойств ядро ждет массив значений —
        // false (очистка) -> [], скаляр -> [значение]
        if (isset($fields['PROPERTY_VALUES']) && is_array($fields['PROPERTY_VALUES'])) {
            $propsMapAdd = $this->getIblockProperties((int)$entityId);
            foreach ($fields['PROPERTY_VALUES'] as $propCode => $propValue) {
                if ($propValue === '' || $propValue === null) {
                    unset($fields['PROPERTY_VALUES'][$propCode]);
                    continue;
                }
                $prop = $propsMapAdd[strtoupper((string)$propCode)] ?? null;
                if ((is_scalar($propValue) || $propValue === false) &&
                    (!$prop || ($prop['MULTIPLE'] ?? 'N') === 'Y')) {
                    if ($propValue === false) {
                        $fields['PROPERTY_VALUES'][$propCode] = [];
                    } elseif ($prop) {
                        $fields['PROPERTY_VALUES'][$propCode] = [$propValue];
                    } else {
                        $fields['PROPERTY_VALUES'][$propCode] = ['VALUE' => $propValue];
                    }
                }
            }
        }

        $el = new \CIblockElement();
        // индексация для поиска: два флажка профиля — SEARCH_NEW (новые
        // элементы) и SEARCH_UPDATE (обновляемые элементы), по умолчанию оба включены
        $newId = $el->Add(
            $fields,
            false,
            ($system['SEARCH_NEW'] ?? 'Y') === 'Y',
            true,
            false,
            false,
            ($system['RESIZE_PICTURES'] ?? 'N') === 'Y'
        );
        if (!$newId) {
            throw new \RuntimeException((string)$el->LAST_ERROR);
        }

        $this->applySystemOptions((int)$entityId, (int)$newId, $system);
        $this->applyCatalog((int)$entityId, (int)$newId, $catalog ?? []);
        // привязка множественных разделов (основной уже установлен через IBLOCK_SECTION)
        if (is_array($sectionList) && count($sectionList) > 1) {
            \CIBlockElement::SetElementSection((int)$newId, $sectionList, true, (int)$entityId);
        }

        return (int)$newId;
    }

    /**
     * @inheritDoc
     */
    public function updateElement(string $entityId, string $id, array $fields): bool
    {
        $fields['IBLOCK_ID'] = $entityId;
        $fields = $this->prepareFields($fields, (int)$id);
        unset($fields['IBLOCK_ID']);

        $system = $fields['SYSTEM'] ?? [];
        $catalog = $fields['CATALOG'] ?? [];
        // список всех разделов элемента (основной — IBLOCK_SECTION в полях)
        $sectionList = $fields['IBLOCK_SECTION_LIST'] ?? null;
        unset($fields['IBLOCK_SECTION_LIST']);
        unset($fields['SYSTEM']);
        unset($fields['CATALOG']);

        // PROPERTIES обновляются отдельно через SetPropertyValuesEx:
        // передача PROPERTY_VALUES в Update затирает свойства, не указанные в массиве,
        // а у нас обновляться может только часть полей
        $properties = $fields['PROPERTY_VALUES'] ?? null;
        unset($fields['PROPERTY_VALUES']);
        // режимы множественных свойств (append/delete) — merge выполняется
        // по текущим значениям элемента перед записью
        $multiModes = $fields['PROPERTY_MULTI'] ?? [];
        unset($fields['PROPERTY_MULTI']);

        // обновление вхолостую не нужно: нет ни полей элемента (кроме IBLOCK_ID),
        // ни непустых свойств, ни каталожных данных, ни разделов —
        // элемент не трогаем (Update без полей зря дергает TIMESTAMP_X и события)
        $propsToSet = [];
        $propsMap = $this->getIblockProperties((int)$entityId);
        if (is_array($properties)) {
            if ($multiModes) {
                // append/delete: новые значения объединяются с текущими
                // значениями множественных свойств элемента
                $properties = $this->mergeMultiProperties((int)$id, (int)$entityId, $properties, $multiModes);
            }
            foreach ($properties as $propCode => $propValue) {
                if ($propValue === '' || $propValue === null) continue;
                $prop = $propsMap[strtoupper((string)$propCode)] ?? null;
                $isMultiple = $prop ? ($prop['MULTIPLE'] ?? 'N') === 'Y' : false;
                // скаляр (в т.ч. false = «очистить») у множественного
                // свойства — SetPropertyValuesEx ждет массив значений;
                // тип неизвестен — скаляр оборачивается в VALUE-формат,
                // ядро принимает его и для одиночных, и для множественных
                if (is_scalar($propValue) || $propValue === false) {
                    if ($propValue === false) {
                        if ($isMultiple) {
                            $propValue = [];
                        } else {
                            continue;
                        }
                    } elseif ($isMultiple) {
                        $propValue = [$propValue];
                    } elseif (!$prop) {
                        $propValue = ['VALUE' => $propValue];
                    }
                }
                if ($prop) {
                    $lookupCode = (string)($prop['CODE'] ?? '') !== '' ? (string)$prop['CODE'] : (string)$propCode;
                    if ($this->isPropertyValueUnchanged((int)$id, $lookupCode, $prop, $propValue)) {
                        continue;
                    }
                }
                $propsToSet[$propCode] = $propValue;
            }
        }
        $hasElementFields = false;
        foreach ($fields as $fieldValue) {
            $hasElementFields = true;
            break;
        }
        $hasCatalog = is_array($catalog) && $catalog !== [];
        $hasSections = is_array($sectionList) && count($sectionList) > 1;
        if (!$hasElementFields && !$propsToSet && !$hasCatalog && !$hasSections) {
            return true;
        }

        // тип обновления: SYSTEM_UPDATE_ORDER = fields_first | props_first
        // fields_first — сперва Update полей, затем свойства через SetPropertyValuesEx;
        // props_first — сперва свойства, затем Update полей элемента
        $propsFirst = (string)($system['UPDATE_ORDER'] ?? 'fields_first') === 'props_first';
        $el = new \CIblockElement();
        // индексация для поиска: флажок SEARCH_UPDATE (обновляемые элементы),
        // по умолчанию включен
        $saveFields = $fields;
        if ($propsFirst) {
            if (is_array($properties) && $properties) {
                $this->setPropertyValuesSafe((int)$id, (int)$entityId, $propsToSet);
            }
            if($hasElementFields){
                $saveFields['IBLOCK_ID'] = $entityId;
                $updated = $el->Update(
                    (int)$id,
                    $saveFields,
                    false,
                    false,
                    true,
                    ($system['RESIZE_PICTURES'] ?? 'N') === 'Y',
                    false
                );
            }
        } else {
            if ($hasElementFields) {
                $saveFields['IBLOCK_ID'] = $entityId;
                $updated = $el->Update(
                    (int)$id,
                    $saveFields,
                    false,
                    false,
                    true,
                    ($system['RESIZE_PICTURES'] ?? 'N') === 'Y',
                    false
                );
            }
            if (is_array($properties) && $properties) {
                $this->setPropertyValuesSafe((int)$id, (int)$entityId, $propsToSet);
            }
        }
        if (!$updated && $hasElementFields) {
            throw new \RuntimeException((string)$el->LAST_ERROR."\n".json_encode($saveFields, JSON_UNESCAPED_UNICODE));
        }

        $this->applySystemOptions((int)$entityId, (int)$id, $system);
        $this->applyCatalog((int)$entityId, (int)$id, $catalog);
        // привязка множественных разделов (основной уже установлен через IBLOCK_SECTION)
        if ($hasSections) {
            \CIBlockElement::SetElementSection((int)$id, $sectionList, true, (int)$entityId);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function touchElement(string $entityId, int $id): bool
    {
        if (!\CModule::IncludeModule('iblock') || $id <= 0) {
            return false;
        }

        $el = new \CIBlockElement;

        // Массив полей для обновления
        $arFields = [
            // Передаем текущую дату и время в формате текущего сайта
            'TIMESTAMP_X' => \ConvertTimeStamp(time(), 'FULL')
        ];

        // Переписываем на старое ядро с отключением тяжелых процессов
        $res = $el->Update(
            $id,
            $arFields,
            false, // $bWorkFlow = false (отключает документооборот/бизнес-процессы)
            false, // $bUpdateSearch = false (ОТКЛЮЧАЕТ переиндексацию модулем Поиск)
            false  // $bResizePictures = false (отключает ресайз картинок)
        );

        if (!$res) {
            Logger::warning('Не удалось обновить дату элемента', [
                'entity_id' => $entityId,
                'element_id' => $id,
                'error' => $el->LAST_ERROR // Логируем ошибку старого ядра, если она есть
            ]);
            return false;
        }

        return true;
    }

    /**
     * Удаление элемента инфоблока (правило TYPE=delete).
     *
     * @param int $id ID элемента
     * @return bool
     */
    public function deleteElement(string $entityId, int $id): bool
    {
        if ($id <= 0 || !Loader::includeModule('iblock')) {
            return false;
        }
        $el = new \CIBlockElement();
        // переиндексация поиска при удалении пусть работает штатно:
        // из поискового индекса элемент должен исчезнуть
        $res = $el->Delete($id, false);
        if (!$res) {
            Logger::warning('Не удалось удалить элемент по правилу импорта', [
                'element_id' => $id,
                'error' => (string)$el->LAST_ERROR,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Каталожные поля элемента из массива загрузки CATALOG_*:
     *  - PRICE_<ID> — цена по типу цен (типы берутся с системы, поле CATALOG_PRICE_<ID>);
     *  - PRICE_<ID>_CURRENCY — валюта цены (необязательно, по умолчанию базовая);
     *  - поля товара b_catalog_product: WEIGHT, WIDTH, LENGTH, HEIGHT,
     *    QUANTITY, QUANTITY_RESERVED, MEASURE, VAT_ID, PURCHASING_PRICE,
     *    PURCHASING_CURRENCY.
     * Пустые/нечисловые числовые значения не трогают.
     *
     * @param string $entityId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $catalog массив CATALOG из prepareFields
     */
    protected function applyCatalog(string $entityId, int $elementId, array $catalog): void
    {
        if (!$catalog || $elementId <= 0 || !Loader::includeModule('catalog')) {
            return;
        }

        // обновление цен/остатков выполняется самим импортом —
        // обработчики (сброс хешей по событиям каталога) отключаются
        // для текущего инфоблока на время обновления
        Handlers::disableForEntity($entityId);
        try {
            $this->applyCatalogInner($entityId, $elementId, $catalog);
        } finally {
            Handlers::enableForEntity($entityId);
        }
    }

    protected function applyCatalogInner(string $entityId, int $elementId, array $catalog): void
    {

        // все поля товара из D7 (b_catalog_product): и проверка существования,
        // и база для сравнения значений — в старом ядре (CCatalogProduct::GetByID)
        // набор полей отличается от D7 (другие имена/часть полей отсутствует),
        // поэтому сравнение делаем по D7-строке
        $productRow = \Bitrix\Catalog\ProductTable::getByPrimary($elementId, [
            'select' => ['*']
        ])->fetch();

        if (!$productRow) {
            // Регистрируем только если записи еще нет
            \Bitrix\Catalog\Model\Product::add(['ID' => $elementId]);
            // новый товар — сравнивать не с чем, все значения применяются
            $productRow = null;
        }

        // каталожные поля товара (b_catalog_product), кроме цен
        static $productKeys = [
            'WEIGHT', 'WIDTH', 'LENGTH', 'HEIGHT', 'QUANTITY', 'QUANTITY_RESERVED',
            'MEASURE', 'VAT_ID', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY',
            'VAT_INCLUDED', 'CAN_BUY_ZERO', 'NEGATIVE_AMOUNT_TRACE', 'QUANTITY_TRACE',
        ];
        // флаговые поля товара (Y/N): значения нормализуются к 'Y'/'N'
        static $flagKeys = [
            'VAT_INCLUDED', 'CAN_BUY_ZERO', 'NEGATIVE_AMOUNT_TRACE', 'QUANTITY_TRACE',
        ];
        try {
            $productFields = [];

            foreach ($catalog as $key => $value) {
                $key = strtoupper((string)$key);
                if (preg_match('/^PRICE_(\d+)$/', $key, $m)) {
                    $this->applyCatalogPrice($elementId, (int)$m[1], (string)$value, $catalog);
                    continue;
                }
                // остаток на конкретном складе: STORE_<ID> — целевой остаток
                if (preg_match('/^STORE_(\d+)$/', $key, $m)) {
                    $this->applyCatalogStore($elementId, (int)$m[1], (string)$value);
                    continue;
                }
                if (!in_array($key, $productKeys, true) && !preg_match('/^UF_/', $key)) {
                    continue;
                }
                $v = trim((string)$value);
                if ($v === '') continue;
                // единица измерения: числовое — ID, иначе поиск по названию/
                // обозначению в справочнике единиц (статический кеш)
                if ($key === 'MEASURE') {
                    $measureId = ProfilesHelper::resolveMeasureId($v);
                    if ($measureId > 0) {
                        $productFields['MEASURE'] = $measureId;
                    } else {
                        Logger::warning('Единица измерения не найдена в справочнике', [
                            'element_id' => $elementId, 'value' => $v,
                        ]);
                    }
                    continue;
                }
                // ставка НДС: числовое — ID, иначе поиск по названию/размеру
                // ставки в справочнике НДС (статический кеш)
                if ($key === 'VAT_ID') {
                    $vatId = ProfilesHelper::resolveVatId($v);
                    if ($vatId > 0) {
                        $productFields['VAT_ID'] = $vatId;
                    } else {
                        Logger::warning('Ставка НДС не найдена в справочнике', [
                            'element_id' => $elementId, 'value' => $v,
                        ]);
                    }
                    continue;
                }
                // флаговые поля Y/N: 'Y','ДА','YES','1','TRUE' => Y;
                // 'N','НЕТ','NO','0','FALSE' => N; прочее — поле не трогаем
                if (in_array($key, $flagKeys, true)) {
                    $flag = $this->normalizeFlagValue($v);
                    if ($flag !== '') {
                        $productFields[$key] = $flag;
                    }
                    continue;
                }
                if ($key !== 'PURCHASING_CURRENCY' && !preg_match('/^UF_/', $key)) {
                    $v = str_replace([' ', ','], ['', '.'], $v);
                    if (!is_numeric($v)) continue;
                    $v = $v + 0;
                }
                $productFields[$key] = $v;
            }
            if ($productFields) {
                // обновление вхолостую не нужно: сравниваем подготовленные
                // значения с текущими (все поля товара получены выше);
                // числа сравниваются как числа (значения из файла приходят строками),
                // прочие — как строки
                $diffFields = [];
                foreach ($productFields as $key => $v) {
                    if ($productRow === null || !array_key_exists($key, $productRow)) {
                        $diffFields[$key] = $v;
                        continue;
                    }
                    $current = $productRow[$key];
                    if (is_numeric($v) && ($current === null || is_numeric($current))) {
                        if ((float)$current !== (float)$v) {
                            $diffFields[$key] = $v;
                        }
                    } elseif (trim((string)$current) !== (string)$v) {
                        $diffFields[$key] = $v;
                    }
                }
                if ($diffFields) {
                    // UF-поля товара (например UF_PRODUCT_GROUP) обновляются
                    // только через D7-модель: CCatalogProduct::Update их не меняет
                    $stdFields = array_filter($diffFields, function ($k) {
                        return !preg_match('/^UF_/', $k);
                    }, ARRAY_FILTER_USE_KEY);
                    $ufFields = array_diff_key($diffFields, $stdFields);
                    if ($stdFields) {
                        \CCatalogProduct::Update($elementId, $stdFields);
                    }
                    foreach ($ufFields as $ufKey => $ufVal) {
                        $res = \Bitrix\Catalog\Model\Product::update($elementId, [$ufKey => $ufVal]);
                        if (!$res->isSuccess()) {
                            Logger::warning('Ошибка сохранения UF-поля товара', [
                                'element_id' => $elementId, 'field' => $ufKey,
                                'error' => $res->getErrorMessages(),
                            ]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка сохранения каталожных данных элемента',
                ['entity_id' => $entityId, 'element_id' => $elementId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Нормализация значения флагового поля товара (Y/N):
     * 'Y','ДА','YES','1','TRUE','+' => 'Y'; 'N','НЕТ','NO','0','FALSE' => 'N';
     * прочее (пустое/нераспознанное) — '' (поле не трогаем).
     *
     * @param string $raw значение из файла
     * @return string 'Y'|'N'|''
     */
    protected function normalizeFlagValue(string $raw): string
    {
        $v = mb_strtoupper(trim($raw));
        $v = str_replace(['Ё', 'Ë'], ['Е', 'E'], $v);
        if ($v === '') return '';
        if (in_array($v, ['Y', 'YES', 'ДА', '1', 'TRUE', '+', 'ВКЛ'], true)) {
            return 'Y';
        }
        if (in_array($v, ['N', 'NO', 'НЕТ', '0', 'FALSE', '-', 'ВЫКЛ'], true)) {
            return 'N';
        }
        return '';
    }

    /**
     * Базовая валюта торгового каталога (с фолбэком на настройку модуля catalog).
     */
    /** @var string|null кеш базовой валюты каталога (null — еще не вычислялась) */
    private static ?string $baseCurrencyCache = null;

    protected static function getCatalogBaseCurrency(): string
    {
        if (self::$baseCurrencyCache !== null) {
            return self::$baseCurrencyCache;
        }
        $currency = '';
        try {
            if (class_exists('\CCurrency') && \Bitrix\Main\Loader::includeModule('currency')) {
                $currency = (string)\CCurrency::GetBaseCurrency();
            }
        } catch (\Throwable $e) {
            // валюта недоступна — берем настройку модуля catalog
        }
        if ($currency === '') {
            $currency = (string)\Bitrix\Main\Config\Option::get('catalog', 'default_currency', 'RUB');
        }
        self::$baseCurrencyCache = $currency;
        return $currency;
    }

    /**
     * Сохранение одной цены элемента по типу цен.
     *
     * @param int $elementId ID элемента
     * @param int $priceTypeId ID типа цен
     * @param string $rawValue значение из файла
     * @param array $catalog массив CATALOG (для PRICE_<ID>_CURRENCY)
     */
    protected function applyCatalogPrice(int $elementId, int $priceTypeId, string $rawValue, array $catalog): void {

        $price = str_replace([' ', ','], ['', '.'], trim($rawValue));
        if ($price === '' || !is_numeric($price)) {
            return; // пустое/нечисловое значение цену не трогает
        }
        $price = (float)$price;

        $currency = trim((string)($catalog['PRICE_' . $priceTypeId . '_CURRENCY'] ?? ''));
        if ($currency === '') {
            $currency = $this->getCatalogBaseCurrency();
        }

        // Ищем существующую цену без диапазонов количества
        // (select * — все поля: и поиск ID, и база для сравнения значений;
        // сравнение с ценой из файла делаем по D7-строке, у старого ядра
        // \CPrice::GetList набор/имена полей отличаются)
        $priceId = 0;
        $priceRow = null;
        $dbPrice = \Bitrix\Catalog\PriceTable::getList([
            'select' => ['*'],
            'filter' => [
                '=PRODUCT_ID' => $elementId,
                '=CATALOG_GROUP_ID' => $priceTypeId,
                '==QUANTITY_FROM' => null, // в D7 false/null проверяется так, либо через 'QUANTITY_FROM' => null
                '==QUANTITY_TO' => null
            ],
            'limit' => 1
        ]);

        if ($row = $dbPrice->fetch()) {
            $priceId = (int)$row['ID'];
            $priceRow = $row;
        }

        $priceFields = [
            'PRODUCT_ID' => $elementId,
            'CATALOG_GROUP_ID' => $priceTypeId,
            'PRICE' => $price,
            'CURRENCY' => $currency
        ];

        $diffFields = [];
        if ($priceId > 0) {
            // обновление вхолостую не нужно: сравниваем с текущими значениями
            // (цена — число с допуском на округление float, остальное — строки)
            foreach ($priceFields as $key => $v) {
                if (!array_key_exists($key, $priceRow)) {
                    $diffFields[$key] = $v;
                    continue;
                }
                $current = $priceRow[$key];
                if ($key === 'PRICE' && ($current === null || is_numeric($current))) {
                    if (round((float)$current, 4) !== round((float)$v, 4)) {
                        $diffFields[$key] = $v;
                    }
                } elseif (trim((string)$current) !== (string)$v) {
                    $diffFields[$key] = $v;
                }
            }
            if (!$diffFields) {
                return; // цена не изменилась — не трогаем
            }
            $result = \Bitrix\Catalog\Model\Price::update($priceId, $priceFields);
        } else {
            $result = \Bitrix\Catalog\Model\Price::add($priceFields);
        }

        // Опционально: можно обработать ошибки, если что-то пошло не так
        if (!$result->isSuccess()) {
            Logger::warning(
                'Ошибка сохранения цены',
                ['price_type_id' => $priceTypeId, 'element_id' => $elementId, 'error' => $result->getErrorMessages(), 'diff_fields'=>$diffFields, 'all_fields'=>$priceFields]
            );
        }
    }


    /**
     * Установка целевого остатка товара на конкретном складе
     * (b_catalog_store_product). Существующая связь товара и склада
     * обновляется, отсутствующая — создается. Обновление вхолостую
     * не выполняется: значение сравнивается с текущим остатком.
     * После успешной записи пересчитывается общий остаток товара
     * (b_catalog_product.QUANTITY / QUANTITY_RESERVED).
     *
     * @param int $elementId ID элемента
     * @param int $storeId ID склада
     * @param string $rawValue значение остатка из файла
     */
    protected function applyCatalogStore(int $elementId, int $storeId, string $rawValue): void
    {
        if ($elementId <= 0 || $storeId <= 0 || !Loader::includeModule('catalog')) {
            return;
        }
        $amount = str_replace([' ', ','], ['', '.'], trim($rawValue));
        if ($amount === '' || !is_numeric($amount)) {
            return; // пустое/нечисловое значение остаток не трогает
        }
        $amount = (float)$amount;

        try {
            // существующая связь товара и склада
            $row = \Bitrix\Catalog\StoreProductTable::getList([
                'select' => ['ID', 'AMOUNT'],
                'filter' => ['=PRODUCT_ID' => $elementId, '=STORE_ID' => $storeId],
                'limit' => 1,
            ])->fetch();

            if ($row) {
                // обновление вхолостую не нужно
                if ((float)$row['AMOUNT'] === $amount) {
                    return;
                }
                $result = \Bitrix\Catalog\StoreProductTable::update((int)$row['ID'], ['AMOUNT' => $amount]);
            } else {
                $result = \Bitrix\Catalog\StoreProductTable::add([
                    'PRODUCT_ID' => $elementId,
                    'STORE_ID' => $storeId,
                    'AMOUNT' => $amount,
                ]);
            }

            if (!$result->isSuccess()) {
                Logger::warning(
                    'Ошибка сохранения остатка на складе',
                    ['element_id' => $elementId, 'store_id' => $storeId, 'error' => $result->getErrorMessages()]
                );
                return;
            }

            // пересчет общего остатка товара по всем складам
            $this->recalcProductQuantity($elementId);
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка сохранения остатка на складе',
                ['element_id' => $elementId, 'store_id' => $storeId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Пересчет общего остатка и резерва товара (b_catalog_product)
     * по суммам всех складских записей (b_catalog_store_product).
     *
     * @param int $elementId ID элемента
     */
    protected function recalcProductQuantity(int $elementId): void
    {
        if ($elementId <= 0 || !Loader::includeModule('catalog')) {
            return;
        }
        try {
            $amount = 0.0;
            $reserved = 0.0;
            $rs = \Bitrix\Catalog\StoreProductTable::getList([
                'select' => ['AMOUNT', 'QUANTITY_RESERVED'],
                'filter' => ['=PRODUCT_ID' => $elementId],
            ]);
            while ($r = $rs->fetch()) {
                $amount += (float)$r['AMOUNT'];
                $reserved += (float)$r['QUANTITY_RESERVED'];
            }

            $productRow = \Bitrix\Catalog\ProductTable::getByPrimary($elementId, [
                'select' => ['QUANTITY', 'QUANTITY_RESERVED'],
            ])->fetch();
            if (!$productRow) {
                \Bitrix\Catalog\Model\Product::add(['ID' => $elementId]);
                $productRow = null;
            }

            $fields = [];
            $currentAmount = $productRow === null ? null : (float)($productRow['QUANTITY'] ?? 0);
            $currentReserved = $productRow === null ? null : (float)($productRow['QUANTITY_RESERVED'] ?? 0);
            if ($currentAmount === null || $currentAmount !== $amount) {
                $fields['QUANTITY'] = $amount;
            }
            if ($currentReserved === null || $currentReserved !== $reserved) {
                $fields['QUANTITY_RESERVED'] = $reserved;
            }
            if (!$fields) {
                return; // общий остаток не изменился
            }
            $result = \Bitrix\Catalog\ProductTable::update($elementId, $fields);
            if (!$result->isSuccess()) {
                Logger::warning(
                    'Ошибка пересчета общего остатка товара',
                    ['element_id' => $elementId, 'error' => $result->getErrorMessages()]
                );
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка пересчета общего остатка товара',
                ['element_id' => $elementId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Системные опции профиля, попавшие в загрузку через ключи SYSTEM_*:
     *  - FACET — инвалидация фасетного индекса инфоблока (пересчитывается lazily);
     *  - META  — сброс кеша наследуемых мета-значений элемента (пересчет при первом обращении).
     *  - SEARCH — индексация элемента для поиска (передается в Add/Update, тут не требуется);
     *  - RESIZE_PICTURES — обработка изображений по настройкам инфоблока (передается в Add/Update);
     *  - REPLACE_DATE — дополнительно не требуется: Update сам обновляет TIMESTAMP_X.
     *
     * @param string $entityId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $system массив SYSTEM из prepareFields
     */
    protected function applySystemOptions(string $entityId, int $elementId, array $system): void
    {
        if (!$system || $entityId <= 0 || $elementId <= 0) {
            return;
        }
        try {
            if(($system['SEARCH_UPDATE'] ?? 'Y') === 'Y'){
                \CIBlockElement::UpdateSearch($elementId, true);
            }
            if (($system['FACET'] ?? '') === 'Y') {
                \Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($entityId, $elementId);
            }
            if (($system['META'] ?? '') === 'Y') {
                $ipropValues = new \Bitrix\Iblock\InheritedProperty\ElementValues($entityId, $elementId);
                $ipropValues->clearValues();
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка применения системных опций элемента',
                ['entity_id' => $entityId, 'element_id' => $elementId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Разделение полей элемента по префиксам ключей:
     *  - PROPERTY_XXX -> PROPERTIES[XXX] (свойства инфоблока);
     *  - SYSTEM_XXX   -> SYSTEM[XXX] (системные данные: отключение обработчиков,
     *    обновление timestamp и другая доп. логика);
     *  - CATALOG_XXX  -> CATALOG[XXX] (данные торгового каталога);
     *  - остальные ключи передаются в поля элемента как есть.
     *
     * @param array $fields собранный элемент (алиас => значение)
     * @param int $elementId ID обновляемого элемента (0 — новый элемент;
     *        используется для проверки неизменности файловых значений)
     * @return array подготовленный массив для \CIBlockElement::Add/Update
     */
    protected function prepareFields(array $fields, int $elementId = 0): array
    {
        $prepared = [];
        $properties = [];
        $binds = [];
        $createEnums = [];
        $delims = [];
        $multiModes = [];
        $sectionCreate = 'Y';
        $sectionLevelDelim = '/';
        $sectionValuesDelim = '';
        $sectionBind = '';
        $sectionParentBind = '';
        foreach ($fields as $key => $value) {
            if ($key === 'PROPERTY_VALUES' && is_array($value)) {
                $properties += $value;
                continue;
            }
            if (preg_match('/^PROPERTY_(.+)_BIND$/', (string)$key, $m)) {
                // тип привязки значения свойства (из настроек сопоставления):
                // PROPERTY_XXX_BIND => id|xml_id|name|code
                $binds[strtoupper((string)$m[1])] = (string)$value;
                continue;
            }
            if (preg_match('/^PROPERTY_(.+)_CREATE_ENUM$/', (string)$key, $m)) {
                // автосоздание отсутствующих значений списка (из настроек
                // сопоставления): PROPERTY_XXX_CREATE_ENUM => Y|N
                $createEnums[strtoupper((string)$m[1])] = (string)$value;
                continue;
            }
            if (preg_match('/^PROPERTY_(.+)_DELIM$/', (string)$key, $m)) {
                // разделитель множественного значения свойства (из настроек
                // сопоставления): PROPERTY_XXX_DELIM (по умолчанию запятая)
                $delims[strtoupper((string)$m[1])] = (string)$value;
                continue;
            }
            if (preg_match('/^PROPERTY_(.+)_MULTI$/', (string)$key, $m)) {
                // режим сохранения множественного свойства (из настроек
                // сопоставления): PROPERTY_XXX_MULTI => ''|append|delete
                $multiModes[strtoupper((string)$m[1])] = (string)$value;
                continue;
            }
            if ($key === 'IBLOCK_SECTION_CREATE') {
                // создание отсутствующих разделов: Y|N (N — только поиск)
                $sectionCreate = (string)$value;
                continue;
            }
            if ($key === 'IBLOCK_SECTION_LEVEL_DELIM') {
                // разделитель уровней в пути раздела (по умолчанию '/')
                $sectionLevelDelim = (string)$value;
                continue;
            }
            if ($key === 'IBLOCK_SECTION_VALUES_DELIM') {
                // разделитель нескольких разделов в одной ячейке
                $sectionValuesDelim = (string)$value;
                continue;
            }
            if ($key === 'IBLOCK_SECTION_BIND') {
                // тип привязки значения раздела (из настроек сопоставления):
                // id|xml_id|name|code
                $sectionBind = (string)$value;
                continue;
            }
            if ($key === 'IBLOCK_SECTION_PARENT_BIND') {
                // тип привязки значения родительского раздела
                $sectionParentBind = (string)$value;
                continue;
            }
            if (str_starts_with((string)$key, 'PROPERTY_')) {
                $properties[substr((string)$key, 9)] = $value;
            } elseif (str_starts_with((string)$key, 'SYSTEM_')) {
                $prepared['SYSTEM'][substr((string)$key, 7)] = $value;
            } elseif (str_starts_with((string)$key, 'CATALOG_')) {
                $prepared['CATALOG'][substr((string)$key, 8)] = $value;
            } else {
                $prepared[$key] = $value;
            }
        }

        if ($properties) {
            $prepared['PROPERTY_VALUES'] = $this->formatProperties((int)($prepared['IBLOCK_ID'] ?? 0), $properties, $binds, $createEnums, $delims, $multiModes, $elementId);
            if ($multiModes) {
                // режимы множественных свойств (append/delete) — используются
                // в updateElement для merge с текущими значениями элемента
                $prepared['PROPERTY_MULTI'] = $multiModes;
            }
        }
        // файловые поля элемента: путь/URL -> CFile::MakeFileArray, 'false' -> удаление
        // при обновлении существующего элемента неизмененное файловое значение
        // (EXTERNAL_ID текущего файла = md5 нового пути) не трогаем — файл
        // не скачивается и поле не обновляется
        foreach (['PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $fileField) {
            if (array_key_exists($fileField, $prepared)) {
                if ($elementId > 0 && is_string($prepared[$fileField])
                    && (string)$prepared[$fileField] !== ''
                    && $this->isElementFileUnchanged((int)($prepared['IBLOCK_ID'] ?? 0), $elementId, $fileField, (string)$prepared[$fileField])
                ) {
                    unset($prepared[$fileField]);
                    continue;
                }
                $prepared[$fileField] = $this->formatFileValue($prepared[$fileField]);
            }
        }
        // типы описаний (PREVIEW_TEXT_TYPE/DETAIL_TEXT_TYPE): если тип не задан
        // в загрузке, определяем его автоматически по содержимому описания —
        // есть html-теги -> 'html', иначе 'text'
        foreach (['PREVIEW_TEXT' => 'PREVIEW_TEXT_TYPE', 'DETAIL_TEXT' => 'DETAIL_TEXT_TYPE'] as $textField => $typeField) {
            if (array_key_exists($textField, $prepared)
                && (!array_key_exists($typeField, $prepared) || trim((string)$prepared[$typeField]) === '')) {
                $prepared[$typeField] = $this->detectTextType($prepared[$textField]);
            }
        }
        // поле родительского раздела: резолвится первым — базовый родитель
        // для раздела(ов) элемента (значение поля «Раздел» разрешается от него)
        $sectionParentId = 0;
        if (array_key_exists('IBLOCK_SECTION_PARENT', $prepared)) {
            $parentIds = $this->resolveSectionField(
                $prepared['IBLOCK_SECTION_PARENT'],
                (int)($prepared['IBLOCK_ID'] ?? 0),
                [
                    'create' => $sectionCreate !== 'N',
                    'level_delim' => $sectionLevelDelim,
                    'values_delim' => $sectionValuesDelim,
                    'bind' => $sectionParentBind,
                ]
            );
            unset($prepared['IBLOCK_SECTION_PARENT']);
            $sectionParentId = $parentIds ? (int)$parentIds[0] : 0;
        }
        // поле раздел(ов): путь/имя/ID/XML_ID/код -> ID раздела(ов),
        // с учетом уровней и родительского раздела
        if (array_key_exists('IBLOCK_SECTION', $prepared)) {
            $sectionIds = $this->resolveSectionField(
                $prepared['IBLOCK_SECTION'],
                (int)($prepared['IBLOCK_ID'] ?? 0),
                [
                    'create' => $sectionCreate !== 'N',
                    'level_delim' => $sectionLevelDelim,
                    'values_delim' => $sectionValuesDelim,
                    'bind' => $sectionBind,
                    'parent_id' => $sectionParentId,
                ]
            );
            if ($sectionIds) {
                // первый раздел — основной (IBLOCK_SECTION), все — в список
                // для привязки множественных разделов (IBLOCK_SECTION_LIST)
                $prepared['IBLOCK_SECTION'] = (int)$sectionIds[0];
                $prepared['IBLOCK_SECTION_LIST'] = $sectionIds;
            } else {
                unset($prepared['IBLOCK_SECTION']);
            }
        } elseif ($sectionParentId > 0) {
            // задан только родительский раздел — он же становится разделом элемента
            $prepared['IBLOCK_SECTION'] = $sectionParentId;
            $prepared['IBLOCK_SECTION_LIST'] = [$sectionParentId];
        }
        // поле CATEGORY_PATH («Путь разделов из categoryId»): значение — путь
        // категорий из XML (вложенность '|', несколько путей через ';') —
        // резолвится по названиям в ID разделов; дубликаты исключаются
        if (array_key_exists('CATEGORY_PATH', $prepared)) {
            $catPathIds = $this->resolveSectionField(
                $prepared['CATEGORY_PATH'],
                (int)($prepared['IBLOCK_ID'] ?? 0),
                [
                    'create' => $sectionCreate !== 'N',
                    'level_delim' => '|',
                    'values_delim' => ';',
                    'bind' => 'name',
                    'parent_id' => $sectionParentId,
                ]
            );
            unset($prepared['CATEGORY_PATH']);
            if ($catPathIds) {
                if (!isset($prepared['IBLOCK_SECTION'])) {
                    $prepared['IBLOCK_SECTION'] = (int)$catPathIds[0];
                }
                $existingList = is_array($prepared['IBLOCK_SECTION_LIST'] ?? null)
                    ? $prepared['IBLOCK_SECTION_LIST'] : [];
                $prepared['IBLOCK_SECTION_LIST'] = array_values(array_unique(
                    array_merge($existingList, $catPathIds)
                ));
            }
        }
        return $prepared;
    }

    /**
     * Определение типа описания по содержимому: есть html-теги -> 'html', иначе 'text'.
     *
     * @param string $text значение описания (PREVIEW_TEXT/DETAIL_TEXT)
     * @return string 'html'|'text'
     */
    protected function detectTextType(string $text): string
    {
        return preg_match('/<[^>]+>/', $text) ? 'html' : 'text';
    }

    /**
     * Форматирование значения файлового поля элемента
     * (PREVIEW_PICTURE / DETAIL_PICTURE / UF_* типа файл):
     *  - 'false' -> ['del'=>'Y'] (удаление файла);
     *  - пустое значение — не трогает файл;
     *  - путь/URL -> CFile::MakeFileArray + EXTERNAL_ID
     *    (md5 пути — для проверки изменения файла при повторных импортах:
     *    старое ядро не перезаливает файл, если EXTERNAL_ID совпал с текущим).
     *
     * @param mixed $value значение из файла импорта
     * @param string $externalId внешний идентификатор файла (пусто — md5 от пути)
     * @return mixed
     */
    protected function formatFileValue($value, string $externalId = '')
    {
        if (is_array($value)) {
            if (isset($value['del'])) {
                return $value; // ['del'=>'Y']
            }
            if (isset($value['name']) || isset($value['tmp_name'])) {
                // готовый MakeFileArray — дописываем EXTERNAL_ID, если задан
                // (SaveFile читает ключ в нижнем регистре, file.php)
                if ($externalId !== '' && !isset($value['external_id'])) {
                    $value['external_id'] = $value['EXTERNAL_ID'] ?? $externalId;
                }
                return $value;
            }
            // список значений для одиночного поля — первое значение
            if ($value === []) {
                return $value;
            }
            $value = reset($value);
            if (is_array($value)) {
                if ($externalId !== '' && !isset($value['EXTERNAL_ID'])) {
                    $value['EXTERNAL_ID'] = $externalId;
                }
                return $value;
            }
        }
        if ($value === false || (string)$value === 'false') {
            return ['del' => 'Y'];
        }
        if ($value === '' || $value === null) {
            return $value;
        }
        $fileArray = \CFile::MakeFileArray((string)$value);
        if (is_array($fileArray)) {
            $fileArray['EXTERNAL_ID'] = $externalId !== '' ? $externalId : md5((string)$value);
            // ключ в нижнем регистре читает CFile::SaveFile (file.php),
            // в верхнем — только наша проверка неизменности
            $fileArray['external_id'] = $fileArray['EXTERNAL_ID'];
        }
        return $fileArray;
    }

    /** @var array кеш EXTERNAL_ID файлов (ID файла => EXTERNAL_ID) */
    private static array $fileExternalIdCache = [];

    /**
     * EXTERNAL_ID файла по ID (с кешем на процесс).
     *
     * @param int $fileId ID файла
     * @return string EXTERNAL_ID (пусто — файла нет)
     */
    protected function getFileExternalId(int $fileId): string
    {
        if ($fileId <= 0) {
            return '';
        }
        if (array_key_exists($fileId, self::$fileExternalIdCache)) {
            return self::$fileExternalIdCache[$fileId];
        }
        $externalId = '';
        $rsFile = \CFile::GetByID($fileId);
        if ($arFile = $rsFile->Fetch()) {
            $externalId = (string)($arFile['EXTERNAL_ID'] ?? '');
        }
        self::$fileExternalIdCache[$fileId] = $externalId;
        return $externalId;
    }

    /**
     * Текущие EXTERNAL_ID файлов свойства (тип F) у элемента.
     *
     * @param int $iblockId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $prop описание свойства
     * @return array список EXTERNAL_ID
     */
    protected function getPropertyFileExternalIds(int $iblockId, int $elementId, array $prop): array
    {
        $result = [];
        $propId = (int)($prop['ID'] ?? 0);
        if ($propId <= 0 || $elementId <= 0 || $iblockId <= 0) {
            return $result;
        }
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, 'sort', 'asc', ['ID' => $propId]);
        while ($row = $rs->Fetch()) {
            $fileId = (int)($row['VALUE'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            $externalId = $this->getFileExternalId($fileId);
            if ($externalId !== '') {
                $result[] = $externalId;
            }
        }
        return $result;
    }

    /**
     * Проверка неизменности файловых значений свойства (тип F):
     * все новые пути уже соответствуют текущим файлам элемента
     * (EXTERNAL_ID текущего файла = md5 пути).
     *
     * @param int $iblockId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $prop описание свойства
     * @param array $values новые значения (пути/URL)
     * @return bool true — значения не изменились, обновлять не нужно
     */
    protected function isPropertyFilesUnchanged(int $iblockId, int $elementId, array $prop, array $values): bool
    {
        $current = $this->getPropertyFileExternalIds($iblockId, $elementId, $prop);
        if (!$current) {
            return false; // у элемента нет файлов — значение считается измененным
        }
        foreach ($values as $value) {
            if (is_array($value)) {
                if (isset($value['del'])) {
                    continue; // удаление проверяем как есть
                }
                if (isset($value['name']) || isset($value['tmp_name'])) {
                    return false; // готовый файл-массив — считаем изменением
                }
                $value = (string)($value['VALUE'] ?? '');
            }
            $value = trim((string)$value);
            if ($value === '' || $value === 'false') {
                continue;
            }
            if (!in_array(md5($value), $current, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Проверка неизменности файлового поля элемента
     * (PREVIEW_PICTURE / DETAIL_PICTURE): EXTERNAL_ID текущего файла
     * совпадает с md5 нового пути/URL.
     *
     * @param int $iblockId ID инфоблока
     * @param int $elementId ID элемента
     * @param string $field имя поля (PREVIEW_PICTURE|DETAIL_PICTURE)
     * @param string $value новый путь/URL
     * @return bool true — значение не изменилось
     */
    protected function isElementFileUnchanged(int $iblockId, int $elementId, string $field, string $value): bool
    {
        $fileId = 0;
        $rs = \CIBlockElement::GetList(
            [],
            ['ID' => $elementId, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', $field]
        );
        if ($row = $rs->Fetch()) {
            $fileId = (int)($row[$field] ?? 0);
        }
        if ($fileId <= 0) {
            return false; // файла нет — значение считается измененным
        }
        return $this->getFileExternalId($fileId) === md5($value);
    }

    /**
     * Текущие файлы свойства (тип F) элемента по README_API_TYPEFILE.md.
     *
     * @param int $iblockId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $prop описание свойства
     * @return array список [['VALUE_ID'=>.., 'FILE_ID'=>.., 'EXTERNAL_ID'=>.., 'DESCRIPTION'=>..], ...]
     */
    protected function getPropertyFiles(int $iblockId, int $elementId, array $prop): array
    {
        $result = [];
        $propId = (int)($prop['ID'] ?? 0);
        if ($propId <= 0 || $elementId <= 0 || $iblockId <= 0) {
            return $result;
        }
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, 'sort', 'asc', ['ID' => $propId]);
        while ($row = $rs->Fetch()) {
            $fileId = (int)($row['VALUE'] ?? 0);
            if ($fileId <= 0) {
                continue;
            }
            $result[] = [
                'VALUE_ID' => (int)$row['PROPERTY_VALUE_ID'],
                'FILE_ID' => $fileId,
                'EXTERNAL_ID' => $this->getFileExternalId($fileId),
                'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Формирование значений множественного свойства типа файл (F)
     * при обновлении элемента (режим replace): существующие файлы
     * передаются под своими PROPERTY_VALUE_ID в формате
     * ['VALUE' => ['VALUE' => FILE_ID]] — вложенный массив-обертка
     * ('VALUE' => int ID файла из b_file) ядро распознает как
     * существующий ID файла (файл не перезаливается, запись
     * сохраняется); голый int в 'VALUE' на текущем ядре вызывает
     * «Cannot use a scalar value as an array» (iblockelement.php).
     * Новые файлы добавляются ключами n0/n1/...
     *
     * Совпадение нового пути с текущим файлом — по EXTERNAL_ID (md5 пути):
     * совпавший файл НЕ скачивается (CFile::MakeFileArray не вызывается).
     * Текущие файлы, не совпавшие ни с одним новым значением, в список
     * не попадают — удаляются (семантика replace).
     *
     * @param int $iblockId ID инфоблока
     * @param int $elementId ID элемента
     * @param array $prop описание свойства
     * @param array $items новые значения (пути/URL или ['VALUE'=>..,'DESCRIPTION'=>..])
     * @return array|null null — значения не изменились, свойство не трогаем
     */
    protected function buildFilePropertyValues(int $iblockId, int $elementId, array $prop, array $items): ?array
    {
        $current = $this->getPropertyFiles($iblockId, $elementId, $prop);
        if (!$current) {
            // у элемента нет файлов — обычное форматирование списка
            return $this->formatFilePropertyList($prop, $items);
        }
        $final = [];
        $usedValueIds = [];
        $newCount = 0;
        $matchedCount = 0;
        $newIdx = 0;
        foreach ($items as $item) {
            $desc = '';
            if (is_array($item)) {
                if (isset($item['del'])) {
                    continue; // точечное удаление без VALUE_ID не определено — пропускаем
                }
                if (isset($item['name']) || isset($item['tmp_name'])) {
                    // готовый файл-массив — добавляется как новый
                    $final['n' . ($newIdx++)] = ['VALUE' => $item, 'DESCRIPTION' => ''];
                    $newCount++;
                    continue;
                }
                if (array_key_exists('VALUE', $item)) {
                    $desc = trim((string)($item['DESCRIPTION'] ?? ''));
                    $item = $item['VALUE'];
                } else {
                    $item = reset($item);
                }
            }
            $path = trim((string)$item);
            if ($path === '' || $path === 'false') {
                continue;
            }
            $md5 = md5($path);
            $matched = null;
            foreach ($current as $c) {
                if (isset($usedValueIds[$c['VALUE_ID']]) || $c['EXTERNAL_ID'] === '') {
                    continue;
                }
                if ($c['EXTERNAL_ID'] === $md5) {
                    $matched = $c;
                    break;
                }
            }
            if ($matched) {
                $usedValueIds[$matched['VALUE_ID']] = true;
                $matchedCount++;
                // существующий файл: 'VALUE' => ['VALUE' => ID файла из b_file]
                $final[$matched['VALUE_ID']] = [
                    'VALUE' => ['VALUE' => $matched['FILE_ID']],
                    'DESCRIPTION' => $desc !== '' ? $desc : (string)$matched['DESCRIPTION'],
                ];
                if ($desc !== '' && $desc !== (string)$matched['DESCRIPTION']) {
                    $newCount++; // изменилось описание — свойство перезаписывается
                }
            } else {
                $fileArray = \CFile::MakeFileArray($path);
                if (is_array($fileArray)) {
                    $fileArray['EXTERNAL_ID'] = $md5;
                    $fileArray['external_id'] = $md5;
                    $final['n' . ($newIdx++)] = ['VALUE' => $fileArray, 'DESCRIPTION' => $desc];
                    $newCount++;
                }
            }
        }
        // без изменений: все новые пути совпали с текущими файлами
        // и все текущие файлы остались в списке (ничего не удалено/добавлено)
        if ($newCount === 0 && $matchedCount === count($current)) {
            return null;
        }
        return $final;
    }

    /**
     * Форматирование списка файловых значений (элемент новый — merge невозможен):
     * каждый путь/URL -> formatFileValue (MakeFileArray + EXTERNAL_ID = md5 пути).
     *
     * @param array $prop описание свойства
     * @param array $items значения (пути/URL или ['VALUE'=>..,'DESCRIPTION'=>..])
     * @return array
     */
    protected function formatFilePropertyList(array $prop, array $items): array
    {
        $result = [];
        $idx = 0;
        foreach ($items as $item) {
            $desc = '';
            if (is_array($item)) {
                if (isset($item['del'])) {
                    continue;
                }
                if (isset($item['name']) || isset($item['tmp_name'])) {
                    $result['n' . ($idx++)] = ['VALUE' => $item, 'DESCRIPTION' => ''];
                    continue;
                }
                if (array_key_exists('VALUE', $item)) {
                    $desc = trim((string)($item['DESCRIPTION'] ?? ''));
                    $item = $item['VALUE'];
                } else {
                    $item = reset($item);
                }
            }
            $path = trim((string)$item);
            if ($path === '' || $path === 'false') {
                continue;
            }
            $fileValue = $this->formatFileValue($path);
            if (is_array($fileValue)) {
                $result['n' . ($idx++)] = ['VALUE' => $fileValue, 'DESCRIPTION' => $desc];
            }
        }
        return $result;
    }

    /**
     * Merge файловых значений множественного свойства (F) в режимах
     * append/delete по README_API_TYPEFILE.md:
     *  - append: текущие файлы сохраняются под своими PROPERTY_VALUE_ID,
     *    новые (с EXTERNAL_ID, не совпадающим с текущими и между собой)
     *    добавляются ключами n0/n1...; новых нет — свойство не трогается (null);
     *  - delete: совпавшие с новыми по EXTERNAL_ID текущие файлы помечаются
     *    ['del'=>'Y'] по VALUE_ID; совпадений нет — свойство не трогается (null).
     *
     * @param int $elementId ID элемента
     * @param int $iblockId ID инфоблока
     * @param array $prop описание свойства
     * @param array $values новые (уже отформатированные) значения
     * @param string $mode append|delete
     * @return array|null null — свойство не трогается
     */
    protected function mergeFileProperties(int $elementId, int $iblockId, array $prop, array $values, string $mode): ?array
    {
        $current = $this->getPropertyFiles($iblockId, $elementId, $prop);
        if ($mode === 'append') {
            $result = [];
            $existingExt = [];
            foreach ($current as $c) {
                $result[$c['VALUE_ID']] = ['VALUE' => $c['FILE_ID'], 'DESCRIPTION' => $c['DESCRIPTION']];
                if ($c['EXTERNAL_ID'] !== '') {
                    $existingExt[] = $c['EXTERNAL_ID'];
                }
            }
            $added = false;
            $idx = 0;
            foreach ($values as $item) {
                if (!is_array($item) || (!isset($item['name']) && !isset($item['tmp_name']))) {
                    continue;
                }
                $ext = (string)($item['EXTERNAL_ID'] ?? '');
                if ($ext !== '' && in_array($ext, $existingExt, true)) {
                    continue; // файл уже привязан к элементу — дубль не добавляется
                }
                if ($ext !== '') {
                    $existingExt[] = $ext;
                }
                $result['n' . ($idx++)] = ['VALUE' => $item, 'DESCRIPTION' => ''];
                $added = true;
            }
            return $added ? $result : null;
        }
        // delete: помечаем ['del'=>'Y'] совпавшие по EXTERNAL_ID текущие файлы
        $newExt = [];
        foreach ($values as $item) {
            if (is_array($item)) {
                if (isset($item['name']) || isset($item['tmp_name'])) {
                    $ext = (string)($item['EXTERNAL_ID'] ?? '');
                    if ($ext !== '') {
                        $newExt[] = $ext;
                    }
                    continue;
                }
                $item = $item['VALUE'] ?? null;
            }
            if (is_string($item) || is_numeric($item)) {
                $ext = trim((string)$item);
                if ($ext !== '' && $ext !== 'false') {
                    $newExt[] = md5($ext);
                }
            }
        }
        if (!$newExt) {
            return null;
        }
        $result = [];
        $found = false;
        foreach ($current as $c) {
            if ($c['EXTERNAL_ID'] !== '' && in_array($c['EXTERNAL_ID'], $newExt, true)) {
                $result[$c['VALUE_ID']] = ['del' => 'Y'];
                $found = true;
            }
        }
        return $found ? $result : null;
    }

    /** @var array кеш путей разделов (iblockId|parentId|NAME => sectionId) */
    private static array $sectionPathCache = [];

    /**
     * Резолв значения поля IBLOCK_SECTION: одиночное значение или список
     * (разделитель values_delim) -> массив ID разделов.
     *
     * @param mixed $value значение из файла импорта
     * @param int $iblockId ID инфоблока
     * @param array $opts create (Y|N), level_delim, values_delim
     * @return array список ID разделов (пустой — ничего не разрешено)
     */
    protected function resolveSectionField($value, int $iblockId, array $opts): array
    {
        if ($iblockId <= 0) {
            return [];
        }
        if (is_array($value)) {
            $values = $value;
        } else {
            $value = trim((string)$value);
            if ($value === '') {
                return [];
            }
            $valuesDelim = (string)($opts['values_delim'] ?? '');
            $values = $valuesDelim !== '' ? explode($valuesDelim, $value) : [$value];
        }
        $ids = [];
        foreach ($values as $v) {
            $id = $this->resolveSectionPath($v, $iblockId, $opts);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Резолв одного значения раздела по типу привязки (opts['bind']):
     *  - id     — только ID раздела (с проверкой существования в инфоблоке);
     *  - xml_id — поиск по XML_ID, при включенном создании — создание с XML_ID;
     *  - code   — поиск по символьному коду, при создании — создание с CODE;
     *  - ''/'name' — авто: числовое значение — ID раздела, иначе путь по
     *    уровням (разделитель level_delim): поиск по названию с учетом
     *    родительского раздела (opts['parent_id']), при включенной опции —
     *    создание отсутствующих уровней.
     *
     * @param mixed $value значение из файла импорта
     * @param int $iblockId ID инфоблока
     * @param array $opts create (bool), level_delim, bind, parent_id
     * @return int ID раздела (0 — не разрешено/не создано)
     */
    protected function resolveSectionPath($value, int $iblockId, array $opts): int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $bind = strtolower(trim((string)($opts['bind'] ?? '')));
        $baseParent = (int)($opts['parent_id'] ?? 0);
        $create = !empty($opts['create']);
        if ($bind === 'id') {
            if (!preg_match('/^\d+$/', $value)) {
                return 0;
            }
            return $this->findSectionById($iblockId, (int)$value);
        }
        if ($bind === 'xml_id') {
            $sectionId = $this->findSectionByField($iblockId, 'XML_ID', $value);
            if ($sectionId <= 0 && $create) {
                $sectionId = $this->createSection($iblockId, $baseParent, $value, ['XML_ID' => $value]);
            }
            return $sectionId;
        }
        if ($bind === 'code') {
            $sectionId = $this->findSectionByField($iblockId, 'CODE', $value);
            if ($sectionId <= 0 && $create) {
                $code = ProfilesHelper::transliterate($value);
                $sectionId = $this->createSection($iblockId, $baseParent, $value, ['CODE' => $code !== '' ? $code : $value]);
            }
            return $sectionId;
        }
        // авто: числовое значение — ID раздела
        if ($bind === '' && preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }
        // name/авто: путь по уровням от родительского раздела
        $levelDelim = (string)($opts['level_delim'] ?? '/');
        if ($levelDelim === '') {
            $levelDelim = '/';
        }
        $parts = array_values(array_filter(
            array_map('trim', explode($levelDelim, $value)),
            function ($part) {
                return $part !== '';
            }
        ));
        if (!$parts) {
            return 0;
        }
        $parentId = $baseParent;
        $sectionId = 0;
        foreach ($parts as $part) {
            $sectionId = $this->findOrCreateSection($iblockId, $part, $parentId, $create);
            if ($sectionId <= 0) {
                return 0;
            }
            $parentId = $sectionId;
        }
        return $sectionId;
    }

    /**
     * Поиск раздела по ID внутри инфоблока (кеш).
     *
     * @param int $iblockId ID инфоблока
     * @param int $sectionId ID раздела
     * @return int ID раздела (0 — не найден)
     */
    protected function findSectionById(int $iblockId, int $sectionId): int
    {
        if ($sectionId <= 0 || !Loader::includeModule('iblock')) {
            return 0;
        }
        $key = $iblockId . '|id|' . $sectionId;
        if (isset(self::$sectionPathCache[$key])) {
            return self::$sectionPathCache[$key];
        }
        $row = \Bitrix\Iblock\SectionTable::getList([
            'select' => ['ID'],
            'filter' => ['=ID' => $sectionId, '=IBLOCK_ID' => $iblockId],
            'limit' => 1,
        ])->fetch();
        $result = $row ? (int)$row['ID'] : 0;
        if ($result > 0) {
            self::$sectionPathCache[$key] = $result;
        }
        return $result;
    }

    /**
     * Поиск раздела по XML_ID или CODE внутри инфоблока (кеш).
     *
     * @param int $iblockId ID инфоблока
     * @param string $field 'XML_ID'|'CODE'
     * @param string $value значение
     * @return int ID раздела (0 — не найден)
     */
    protected function findSectionByField(int $iblockId, string $field, string $value): int
    {
        $field = $field === 'CODE' ? 'CODE' : 'XML_ID';
        $value = trim($value);
        if ($value === '' || !Loader::includeModule('iblock')) {
            return 0;
        }
        $key = $iblockId . '|' . strtolower($field) . '|' . mb_strtoupper($value);
        if (isset(self::$sectionPathCache[$key])) {
            return self::$sectionPathCache[$key];
        }
        $sectionId = 0;
        $rs = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, '=' . $field => $value],
            false,
            ['ID'],
            false
        );
        while ($row = $rs->Fetch()) {
            $sectionId = (int)$row['ID'];
            break;
        }
        if ($sectionId > 0) {
            self::$sectionPathCache[$key] = $sectionId;
        }
        return $sectionId;
    }

    /**
     * Создание раздела (общее для findOrCreateSection и привязок XML_ID/код).
     *
     * @param int $iblockId ID инфоблока
     * @param int $parentId ID родительского раздела (0 — корень)
     * @param string $name название раздела
     * @param array $extra дополнительные поля (XML_ID, CODE)
     * @return int ID раздела (0 — не создан)
     */
    protected function createSection(int $iblockId, int $parentId, string $name, array $extra = []): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }
        $section = new \CIBlockSection();
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $name,
            'IBLOCK_SECTION_ID' => $parentId,
            'ACTIVE' => 'Y',
            'SORT' => 500,
        ];
        if (isset($extra['CODE']) && (string)$extra['CODE'] !== '') {
            $fields['CODE'] = (string)$extra['CODE'];
        } else {
            $code = ProfilesHelper::transliterate($name);
            if ($code !== '') {
                $fields['CODE'] = $code;
            }
        }
        if (isset($extra['XML_ID']) && (string)$extra['XML_ID'] !== '') {
            $fields['XML_ID'] = (string)$extra['XML_ID'];
        }
        $newId = $section->Add($fields);
        if (!$newId) {
            Logger::warning(
                'Не удалось создать раздел',
                ['iblock_id' => $iblockId, 'parent_id' => $parentId, 'name' => $name, 'error' => (string)$section->LAST_ERROR]
            );
            return 0;
        }
        return (int)$newId;
    }

    /**
     * Поиск раздела по названию внутри родителя (кеш) или создание
     * отсутствующего (если $create).
     *
     * @param int $iblockId ID инфоблока
     * @param string $name название уровня
     * @param int $parentId ID родительского раздела (0 — корень)
     * @param bool $create создавать отсутствующий раздел
     * @return int
     */
    protected function findOrCreateSection(int $iblockId, string $name, int $parentId, bool $create): int
    {
        // название раздела всегда нормализуется (без внешних пробелов)
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        if (!Loader::includeModule('iblock')) {
            return 0;
        }
        $key = $iblockId . '|' . $parentId . '|' . mb_strtoupper($name);
        if (isset(self::$sectionPathCache[$key])) {
            return self::$sectionPathCache[$key];
        }
        $sectionId = 0;
        $rs = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $parentId, '=NAME' => trim($name)],
            false,
            ['ID'],
            false
        );
        while ($row = $rs->Fetch()) {
            $sectionId = (int)$row['ID'];
            break;
        }
        if ($sectionId <= 0 && $create) {
            $sectionId = $this->createSection($iblockId, $parentId, $name);
        } elseif ($sectionId <= 0) {
            Logger::warning(
                'Раздел не найден, создание отключено',
                ['iblock_id' => $iblockId, 'parent_id' => $parentId, 'name' => $name]
            );
        }
        if ($sectionId > 0) {
            self::$sectionPathCache[$key] = $sectionId;
        }
        return $sectionId;
    }

    /** @var array кеш свойств инфоблоков (iblockId => [CODE|ID => описание]) */
    private static array $propsCache = [];
    /** @var array кеш значений списков (propertyId => [UPPER(VALUE)|UPPER(XML_ID) => enumId]) */
    private static array $enumCache = [];
    /** @var array кеш элементов связанных инфоблоков (linkIblockId => [VALUE => elementId]) */
    private static array $linkElementCache = [];
    /** @var array кеш разделов (iblockId => [NAME|ID => sectionId]) */
    private static array $sectionCache = [];

    /**
     * Форматирование значений свойств по типу свойства
     *  - N — нормализация числа;
     *  - L — значение/ XML_ID -> ID варианта списка (автосоздание отсутствующего);
     *  - E — значение (ID/XML_ID/CODE/NAME) -> ID элемента связанного инфоблока;
     *  - G — значение (ID/NAME) -> ID раздела;
     *  - F — путь/URL -> CFile::MakeFileArray;
     *  - S и пользовательские типы (video, date, directory, userID, URL) — как есть;
     *    USER_TYPE=HTML (S:HTML) — форматHtmlPropertyValue: JSON {"VALUE","TYPE"} или
     *    строка -> ['VALUE'=>['TEXT'=>..,'TYPE'=>..]], TYPE авто по наличию html-тегов.
     * Множественные свойства: массив значений обрабатывается поэлементно.
     *
     * @param int $iblockId ID инфоблока
     * @param array $properties [CODE|ID => значение]
     * @param array $binds [CODE|ID => тип привязки (id|xml_id|name|code), '' — авто]
     * @param array $createEnums [CODE|ID => 'Y'|'N'] автосоздание значений списка
     * @param array $delims [CODE|ID => разделитель множественного значения]
     * @param array $multiModes [CODE|ID => 'replace'|'append'|'delete'] режим множественного свойства
     */
    protected function formatProperties(int $iblockId, array $properties, array $binds = [], array $createEnums = [], array $delims = [], array $multiModes = [], int $elementId = 0): array
    {
        if ($iblockId <= 0) {
            return $properties;
        }
        $props = $this->getIblockProperties($iblockId);
        $result = [];
        foreach ($properties as $key => $value) {
            $prop = $props[strtoupper((string)$key)] ?? null;
            if (!$prop) {
                // свойство не найдено — значение передается как есть
                $result[$key] = $value;
                continue;
            }
            $bind = $binds[strtoupper((string)$key)] ?? '';
            $createEnum = ($createEnums[strtoupper((string)$key)] ?? 'Y') !== 'N';
            $delim = (string)($delims[strtoupper((string)$key)] ?? ',');
            $result[$key] = $this->formatPropertyValue($prop, $value, $bind, $createEnum, $delim, $multiModes[strtoupper((string)$key)] ?? 'replace', $elementId, $iblockId);
        }
        return $result;
    }

    /**
     * Применение режимов множественных свойств (append/delete) при обновлении:
     * новые значения объединяются с текущими значениями элемента.
     *  - append — текущие значения + новые (без дублей);
     *  - delete — из текущих значений удаляются совпавшие с новыми.
     *
     * @param int $id ID элемента
     * @param int $iblockId ID инфоблока
     * @param array $properties уже отформатированные PROPERTY_VALUES
     * @param array $multiModes [CODE|ID => 'append'|'delete']
     * @return array
     */
    protected function mergeMultiProperties(int $id, int $iblockId, array $properties, array $multiModes): array
    {
        if ($id <= 0 || $iblockId <= 0 || !Loader::includeModule('iblock')) {
            return $properties;
        }
        $propsMap = $this->getIblockProperties($iblockId);
        foreach ($multiModes as $code => $mode) {
            $code = strtoupper((string)$code);
            if ($mode !== 'append' && $mode !== 'delete') {
                continue;
            }
            if (!array_key_exists($code, $properties)) {
                continue;
            }
            $prop = $propsMap[$code] ?? null;
            if (!$prop || ($prop['MULTIPLE'] ?? 'N') !== 'Y') {
                continue;
            }
            // свойства типа файл (F): merge по README_API_TYPEFILE.md —
            // существующие файлы сохраняются под своими PROPERTY_VALUE_ID
            // (VALUE = ID файла, файл не перезаливается), новые добавляются
            // ключами n0/n1..., удаление — ['del'=>'Y'] по VALUE_ID
            if ((string)($prop['PROPERTY_TYPE'] ?? '') === 'F') {
                $properties[$code] = $this->mergeFileProperties((int)$id, (int)$iblockId, $prop, (array)$properties[$code], (string)$mode);
                continue;
            }
            // текущие значения через Handlers::getElementPropertyValue;
            // L — в b_iblock_element_property.VALUE хранится ID значения списка,
            // сравнение со строковым представлением ID
            $lookupCode = (string)($prop['CODE'] ?? '') !== '' ? (string)$prop['CODE'] : $code;
            $raw = Handlers::getElementPropertyValue($id, $lookupCode);
            $cur = [];
            foreach ((array)$raw as $item) {
                if (is_array($item)) continue;
                $item = (string)$item;
                if ($item === '') continue;
                $cur[] = $item;
            }
            $new = [];
            foreach ((array)$properties[$code] as $item) {
                if (is_array($item)) {
                    if (isset($item['name']) || isset($item['tmp_name'])) {
                        // файловые значения не сравниваются с текущими:
                        // append — добавляются, delete — пропускаются
                        if ($mode === 'append') {
                            $new[] = $item;
                        }
                        continue;
                    }
                    $item = $item['VALUE'] ?? null;
                }
                if ($item === null || $item === '' || (string)$item === 'false') {
                    continue;
                }
                $new[] = (string)$item;
            }
            if ($mode === 'append') {
                $result = $cur;
                foreach ($new as $item) {
                    if (is_array($item) || !in_array($item, $result, true)) {
                        $result[] = $item;
                    }
                }
            } else {
                $result = [];
                foreach ($cur as $item) {
                    if (in_array($item, $new, true)) {
                        continue;
                    }
                    $result[] = $item;
                }
            }
            $properties[$code] = $result;
        }
        return $properties;
    }

    /**
     * Проверка реального изменения значения свойства: новое значение
     * сравнивается с текущим значением элемента
     * (Awz\Allxls\Handlers::getElementPropertyValue).
     *
     * Сравнимые типы: S/N (строка/число), L/E/G (ID значения/элемента/раздела),
     * HTML (сравнение по тексту VALUE). Файловые значения (F) и значения
     * пользовательских типов (directory и др.) не сравниваются — считается
     * изменением (безопасный фолбэк: значение перезаписывается).
     *
     * @param int $elementId ID элемента
     * @param string $lookupCode код свойства (CODE или ключ из загрузки)
     * @param array $prop описание свойства
     * @param mixed $newValue новое (уже отформатированное) значение свойства
     * @return bool true — значение не изменилось, обновлять не нужно
     */
    protected function isPropertyValueUnchanged(int $elementId, string $lookupCode, array $prop, $newValue): bool
    {
        $new = $this->normalizePropertyValueForCompare($newValue);
        if ($new === null) {
            return false; // файлы/несравнимые форматы — считаем изменением
        }
        $current = Handlers::getElementPropertyValue($elementId, $lookupCode);
        if ($new === 'del') {
            // удаление файла: менять нечего, если значения и так нет
            return $current === null;
        }
        if ($current === null) {
            return false; // значения нет — новое считается изменением
        }
        if (is_array($new)) {
            $cur = [];
            foreach ((array)$current as $item) {
                if (is_array($item)) continue;
                $cur[] = (string)$item;
            }
            sort($cur);
            $newSorted = array_map('strval', $new);
            sort($newSorted);
            return $cur === $newSorted;
        }
        $cur = is_array($current) ? (string)reset($current) : (string)$current;
        // числовые значения (N, ID в L/E/G) сравниваем как числа
        // ('10.00' в БД == '10' из файла)
        if (is_numeric($cur) && is_numeric($new)) {
            return (float)$cur === (float)$new;
        }
        return trim($cur) === $new;
    }

    /**
     * Нормализация значения свойства для сравнения:
     *  - HTML ['VALUE'=>['TEXT'=>..,'TYPE'=>..]] -> текст;
     *  - файл ['del'=>'Y'] -> 'del';
     *  - файл-массив (name/tmp_name) -> null (несравнимо);
     *  - множественное -> массив строк;
     *  - скаляр -> строка (trim).
     *
     * @param mixed $value значение
     * @return mixed string|array|null (null — значение несравнимо)
     */
    protected function normalizePropertyValueForCompare($value)
    {
        if (is_array($value)) {
            if (array_key_exists('VALUE', $value)
                && !isset($value['name']) && !isset($value['tmp_name']) && !isset($value['del'])
            ) {
                // формат HTML ['VALUE'=>..] (и ['VALUE'=>..,'DESCRIPTION'=>..])
                $inner = $value['VALUE'];
                $text = is_array($inner) ? (string)($inner['TEXT'] ?? '') : (string)$inner;
                return trim($text);
            }
            if (isset($value['del'])) {
                return 'del';
            }
            if (isset($value['name']) || isset($value['tmp_name'])) {
                return null; // готовый файл-массив — несравнимо
            }
            $result = [];
            foreach ($value as $item) {
                $normalized = $this->normalizePropertyValueForCompare($item);
                if ($normalized === null) {
                    return null;
                }
                $result[] = $normalized;
            }
            return $result;
        }
        return trim((string)$value);
    }

    /**
     * Описание свойств инфоблока (кеш): ключи по CODE и ID в верхнем регистре.
     */
    protected function getIblockProperties(int $iblockId): array
    {
        if (isset(self::$propsCache[$iblockId])) {
            return self::$propsCache[$iblockId];
        }
        $map = [];
        $rs = \CIBlockProperty::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId]
        );
        while ($row = $rs->Fetch()) {
            $map[strtoupper((string)$row['CODE'])] = $row;
            $map[(string)$row['ID']] = $row;
        }
        self::$propsCache[$iblockId] = $map;
        return $map;
    }

    /**
     * Форматирование одного значения по типу свойства (с поддержкой множественных).
     *
     * @param array $prop описание свойства
     * @param mixed $value значение
     * @param string $bind тип привязки (id|xml_id|name|code, '' — авто)
     * @param bool $createEnum автосоздание отсутствующих значений списка
     * @param string $delim разделитель множественного значения (по умолчанию запятая)
     * @param string $multi режим множественного свойства: replace|append|delete
     * @param int $elementId ID обновляемого элемента (0 — новый)
     * @param int $iblockId ID инфоблока (для проверки текущих файловых значений)
     */
    protected function formatPropertyValue(array $prop, $value, string $bind = '', bool $createEnum = true, string $delim = ',', string $multi = 'replace', int $elementId = 0, int $iblockId = 0)
    {
        // свойства пользовательского типа HTML (S:HTML): значение приходит
        // как JSON {"VALUE":..,"TYPE":..} (действие build_json) или как есть —
        // тип определяется автоматически по наличию html-тегов
        if ((string)($prop['USER_TYPE'] ?? '') === 'HTML') {
            return $this->formatHtmlPropertyValue($value);
        }
        // множественное свойство: строка с разделителем -> массив значений
        if (($prop['MULTIPLE'] ?? 'N') === 'Y' && is_string($value)) {
            $delim = $delim !== '' ? $delim : ',';
            if (str_contains($value, $delim)) {
                $value = array_values(array_filter(
                    array_map('trim', explode($delim, $value)),
                    function ($part) {
                        return $part !== '';
                    }
                ));
            }
        }
        // пустой массив для множественного свойства:
        // 'replace' — очистка свойства (false);
        // append/delete — пустой список обрабатывается в updateElement
        // через mergeMultiProperties по текущим значениям элемента
        if (($prop['MULTIPLE'] ?? 'N') === 'Y' && is_array($value) && !$value) {
            return $multi === 'replace' ? false : [];
        }
        if (($prop['MULTIPLE'] ?? 'N') === 'Y' && is_array($value)) {
            // тип F при обновлении существующего элемента (replace): merge
            // с текущими файлами свойства по README_API_TYPEFILE.md —
            // совпавшие с текущими (EXTERNAL_ID = md5 пути) файлы сохраняются
            // под своим PROPERTY_VALUE_ID (файл не перезаливается, описание
            // не теряется), новые добавляются ключами n0/n1..., упомянутые
            // текущие с новой описью обновляют DESCRIPTION;
            // если ничего не изменилось — свойство не трогается (null)
            if ((string)($prop['PROPERTY_TYPE'] ?? '') === 'F' && $elementId > 0 && $multi === 'replace') {
                return $this->buildFilePropertyValues($iblockId, $elementId, $prop, $value);
            }
            // тип F, элемент новый (merge с текущими невозможен):
            // replace/append — список файлов как есть, delete — удалять нечего
            if ((string)($prop['PROPERTY_TYPE'] ?? '') === 'F' && $elementId <= 0) {
                return $multi === 'delete' ? [] : $this->formatFilePropertyList($prop, $value);
            }
            $result = [];
            foreach ($value as $k => $item) {
                if (is_array($item) && array_key_exists('VALUE', $item)) {
                    // формат ['VALUE'=>..,'DESCRIPTION'=>..]
                    $item['VALUE'] = $this->formatSinglePropertyValue($prop, $item['VALUE'], '', $createEnum);
                    $result[$k] = $item;
                } else {
                    $result[$k] = $this->formatSinglePropertyValue($prop, $item, $bind, $createEnum);
                }
            }
            return $result;
        }
        return $this->formatSinglePropertyValue($prop, $value, $bind, $createEnum, $elementId, $iblockId);
    }

    /**
     * SetPropertyValuesEx с защитной нормализацией значений:
     * ядро падает «Cannot use a scalar value as an array» на скалярах
     * у множественных свойств — все скаляры/булевы конвертируются
     * в массивы (false = очистка -> [], скаляр -> ['VALUE'=>..]).
     */
    protected function setPropertyValuesSafe(int $id, int $iblockId, array $propsToSet): void
    {
        // глубокая нормализация: ядро пишет в каждое значение как в массив
        // (['VALUE'=>..,'DESCRIPTION'=>..]), поэтому любое значение-скаляр
        // на любом уровне приводит к «Cannot use a scalar value as an array»
        foreach ($propsToSet as $code => $value) {
            $propsToSet[$code] = $this->normalizePropertyValues($value);
        }
        // DEBUG LOG: реальные данные, отправляемые в SetPropertyValuesEx
        Logger::debug('DEBUG SetPropertyValuesEx payload', [
            'element_id' => $id,
            'iblock_id' => $iblockId,
            'props' => $propsToSet,
        ]);
        \CIBlockElement::SetPropertyValuesEx($id, $iblockId, $propsToSet);
    }

    /**
     * Рекурсивная нормализация значений свойства в битриксовый формат:
     * скаляр/null -> ['VALUE'=>..], вложенные скаляры -> ['VALUE'=>..].
     */
    protected function normalizePropertyValues($value)
    {
        if ($value === false) {
            return [];
        }
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return ['VALUE' => $value];
        }
        if (is_array($value)) {
            // готовый файловый массив (MakeFileArray: name/tmp_name/size...)
            // или маркер удаления ['del'=>'Y'] — рекурсивная нормализация
            // завернула бы tmp_name/del в ['VALUE'=>..] и ядро падало
            // «copy(): Argument #1 ($from) must be of type string»
            if (isset($value['name']) || isset($value['tmp_name']) || isset($value['del'])) {
                return $value;
            }
            // битриксовый формат элемента файла ['VALUE'=>..,'DESCRIPTION'=>..]:
            // скалярный VALUE (int ID файла из b_file) оборачивается в
            // ['VALUE' => int] — вложенная обертка распознается ядром
            // как существующий файл (голый int вызывает «Cannot use a
            // scalar value as an array», iblockelement.php)
            if (array_key_exists('VALUE', $value) && array_key_exists('DESCRIPTION', $value)
                && (is_scalar($value['VALUE']) || $value['VALUE'] === null)) {
                $value['VALUE'] = is_scalar($value['VALUE']) ? ['VALUE' => $value['VALUE']] : null;
                return $value;
            }
            // уже битриксовый формат ['VALUE'=>..] — не трогаем
            if (array_key_exists('VALUE', $value) || array_key_exists('TEXT', $value)) {
                return $value;
            }
            foreach ($value as $k => $item) {
                if (is_scalar($item) || $item === null || $item === false) {
                    $value[$k] = is_scalar($item) ? ['VALUE' => $item] : null;
                } elseif (is_array($item)) {
                    $value[$k] = $this->normalizePropertyValues($item);
                }
            }
            return $value;
        }
        return $value;
    }

    /**
     * Форматирование значения свойства типа HTML (USER_TYPE=HTML).
     *
     * Поддерживаемые входные форматы:
     *  - JSON {"VALUE":"текст","TYPE":"text"} или массив таких объектов
     *    (результат действия build_json);
     *  - обычная строка/массив строк (значение как есть);
     *  - уже готовый битриксовый формат ['TEXT'=>..,'TYPE'=>..].
     * Тип (html/text) всегда определяется автоматически по наличию
     * html-тегов в тексте значения.
     *
     * @param mixed $value значение из файла импорта
     * @return mixed значение в формате CIBlockElement ['VALUE'=>['TEXT'=>..,'TYPE'=>..]]
     */
    protected function formatHtmlPropertyValue($value)
    {
        // множественные значения обрабатываются поэлементно
        if (is_array($value) && !isset($value['VALUE']) && !isset($value['TEXT'])) {
            $result = [];
            foreach ($value as $k => $item) {
                if ($item === '' || $item === null) {
                    continue;
                }
                $result[$k] = $this->formatHtmlPropertyValue($item);
            }
            if (!$result) {
                return false; // очистка множественного свойства
            }
            return $result;
        }

        if (is_array($value)) {
            if (isset($value['VALUE'])) {
                $inner = $value['VALUE'];
                if (is_array($inner) && isset($inner['TEXT'])) {
                    $text = (string)$inner['TEXT'];
                } else {
                    $text = (string)$inner;
                }
            } elseif (isset($value['TEXT'])) {
                $text = (string)$value['TEXT'];
            } else {
                return $value;
            }
        } else {
            $raw = (string)$value;
            if ($raw === '') {
                return $value;
            }
            $text = $raw;
            // JSON из действия build_json — извлекаем VALUE
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded) && isset($decoded['VALUE']) && !is_array($decoded['VALUE'])) {
                $text = (string)$decoded['VALUE'];
            }
        }

        // автоопределение типа: есть html-теги — html, иначе text
        $type = preg_match('/<[a-z][^>]*>/i', $text) ? 'html' : 'text';
        return ['VALUE' => ['TEXT' => $text, 'TYPE' => $type]];
    }

    /**
     * Форматирование одиночного значения по типу свойства.
     *
     * @param array $prop описание свойства
     * @param mixed $value значение
     * @param string $bind тип привязки (id|xml_id|name|code, '' — авто)
     * @param bool $createEnum автосоздание отсутствующих значений списка
     * @param int $elementId ID обновляемого элемента (0 — новый)
     * @param int $iblockId ID инфоблока (для проверки текущего файлового значения)
     */
    protected function formatSinglePropertyValue(array $prop, $value, string $bind = '', bool $createEnum = true, int $elementId = 0, int $iblockId = 0)
    {
        // пустые значения пропускаем как есть (пустая строка не затирает свойство)
        if ($value === '' || $value === null) {
            return $value;
        }

        // одиночное свойство со списком значений — берется первое значение
        // (актуально после извлечения из JSON по ключу / разделителя значений)
        if (is_array($value)
            && !isset($value['del'])
            && !isset($value['name'])
            && !isset($value['tmp_name'])
            && !isset($value['VALUE'])
        ) {
            if ($value === []) {
                return null; // пустой список — значение не трогаем
            }
            $value = reset($value);
        }

        switch ((string)($prop['PROPERTY_TYPE'] ?? 'S')) {
            case 'N':
                return is_numeric($value) ? $value + 0 : (float)str_replace([',', ' '], ['.', ''], (string)$value);

            case 'L':
                return $this->resolveEnumId((int)$prop['ID'], $value, $bind, $createEnum);

            case 'E':
                if (is_array($value)) {
                    return $value;
                }
                return $this->resolveLinkElementId((int)($prop['LINK_IBLOCK_ID'] ?? 0), (string)$value, $bind);

            case 'G':
                if (is_array($value)) {
                    return $value;
                }
                return $this->resolveSectionId((int)($prop['LINK_IBLOCK_ID'] ?? 0), (string)$value, $bind);

            case 'F':
                $desc = '';
                if (is_array($value) && array_key_exists('VALUE', $value)
                    && !isset($value['del']) && !isset($value['name']) && !isset($value['tmp_name'])
                ) {
                    // формат ['VALUE'=>путь,'DESCRIPTION'=>..] — описание файла
                    $desc = trim((string)($value['DESCRIPTION'] ?? ''));
                    $value = $value['VALUE'];
                } elseif (is_array($value) && !isset($value['del']) && !isset($value['name']) && !isset($value['tmp_name'])) {
                    // список значений для одиночного свойства — первое значение
                    $first = reset($value);
                    if ($first === false || $first === null) {
                        return ['del' => 'Y'];
                    }
                    $value = $first;
                }
                // форматирование через общий метод файловых значений
                // (MakeFileArray + EXTERNAL_ID = md5 пути);
                // при обновлении существующего элемента неизмененный путь
                // (EXTERNAL_ID текущего файла = md5 пути) не трогаем —
                // файл не скачивается и свойство не перезаписывается
                if ($elementId > 0 && $iblockId > 0
                    && !is_array($value)
                    && (string)$value !== '' && (string)$value !== 'false'
                    && $this->isPropertyFilesUnchanged($iblockId, $elementId, $prop, [(string)$value])
                ) {
                    return null;
                }
                $fileValue = $this->formatFileValue($value);
                if ($desc !== '' && is_array($fileValue) && !isset($fileValue['del'])) {
                    return ['VALUE' => $fileValue, 'DESCRIPTION' => $desc];
                }
                return $fileValue;

            case 'S':
            default:
                $userType = (string)($prop['USER_TYPE'] ?? '');
                // S:ElementXmlID — значение свойства само является XML_ID элемента,
                // резолвить в ID не нужно
                if ($userType === 'ElementXmlID') {
                    return $value;
                }
                // S:directory — значение резолвится в XML_ID записи HL-справочника
                if ($userType === 'directory') {
                    return $this->resolveDirectoryValue($prop, $value, $bind);
                }
                // прочие пользовательские типы (video, date, userID, URL и др.) — строкой
                return $value;
        }
    }

    /** @var array кеш справочников S:directory (TABLE_NAME => ['DATA_CLASS'=>..,'FIELDS'=>..]) */
    private static array $directoryCache = [];
    /** @var array кеш строк справочников (TABLE_NAME => ['ID'=>[], 'XML'=>[], 'COL'=>[UF_CODE=>[]]]) */
    private static array $directoryRowsCache = [];

    /**
     * Справочник S:directory: данные HL-блока (TABLE_NAME из USER_TYPE_SETTINGS
     * свойства -> HL-блок -> компилируемая сущность + пользовательские поля).
     *
     * @param array $prop описание свойства
     * @return array ['DATA_CLASS'=>class, 'FIELDS'=>[UF_CODE=>field], 'TABLE_NAME'=>..] или []
     */
    protected function getDirectoryInfo(array $prop): array
    {
        $settings = $prop['USER_TYPE_SETTINGS'] ?? '';
        if (is_string($settings) && $settings !== '') {
            $settings = (array)(@unserialize($settings, ['allowed_classes' => false]) ?: []);
        }
        if (!is_array($settings)) {
            return [];
        }
        $tableName = (string)($settings['TABLE_NAME'] ?? '');
        if ($tableName === '' || !Loader::includeModule('highloadblock')) {
            return [];
        }
        if (isset(self::$directoryCache[$tableName])) {
            return self::$directoryCache[$tableName];
        }
        try {
            $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
                'select' => ['ID', 'NAME'],
                'filter' => ['=TABLE_NAME' => $tableName],
                'limit' => 1,
            ])->fetch();
            if (!$hl) {
                return [];
            }
            $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hl);
            $info = [
                'DATA_CLASS' => $entity->getDataClass(),
                'FIELDS' => (array)$GLOBALS['USER_FIELD_MANAGER']->GetUserFields('HLBLOCK_' . (int)$hl['ID']),
                'TABLE_NAME' => $tableName,
            ];
            self::$directoryCache[$tableName] = $info;
            return $info;
        } catch (\Throwable $e) {
            Logger::warning(
                'Не удалось получить справочник HL для свойства',
                ['table' => $tableName, 'error' => $e->getMessage()]
            );
            return [];
        }
    }

    /**
     * Загруженные строки справочника с индексами для поиска:
     *  - ID  — по ID записи;
     *  - XML — по UF_XML_ID;
     *  - COL — по значениям скалярных колонок (UF_*).
     *
     * @return array
     */
    protected function getDirectoryRows(string $dataClass, string $tableName, array $fields): array
    {
        if (isset(self::$directoryRowsCache[$tableName])) {
            return self::$directoryRowsCache[$tableName];
        }
        $rows = ['ID' => [], 'XML' => [], 'COL' => []];
        try {
            $rs = $dataClass::getList(['select' => ['*']]);
            while ($row = $rs->fetch()) {
                $xml = (string)($row['UF_XML_ID'] ?? '');
                if ($xml !== '') {
                    $rows['XML'][mb_strtoupper($xml)] = $xml;
                }
                $rows['ID'][(string)(int)($row['ID'] ?? 0)] = $xml;
                foreach ($fields as $code => $field) {
                    $val = $row[$code] ?? null;
                    if (is_scalar($val) && (string)$val !== '') {
                        $rows['COL'][$code][mb_strtoupper(trim((string)$val))] = $xml;
                    }
                }
            }
        } catch (\Throwable $e) {
            Logger::warning(
                'Не удалось загрузить строки справочника HL',
                ['table' => $tableName, 'error' => $e->getMessage()]
            );
        }
        self::$directoryRowsCache[$tableName] = $rows;
        return $rows;
    }

    /**
     * Значение свойства S:directory -> XML_ID записи HL-справочника.
     *
     * Привязка ($bind):
     *  - ''       — авто: UF_XML_ID, затем ID, затем значения колонок;
     *  - 'id'     — только по ID записи;
     *  - 'xml_id' — только по UF_XML_ID;
     *  - 'col_UF_X' — по значению колонки UF_X.
     * Если значение не найдено и является JSON-описанием записи
     * (действие «сформировать новое значение», напр. {"UF_NAME":"test","XML_ID":"test"}) —
     * запись создается, возвращается ее XML_ID.
     *
     * @param array $prop описание свойства
     * @param mixed $value значение из файла импорта
     * @param string $bind тип привязки
     * @return mixed
     */
    protected function resolveDirectoryValue(array $prop, $value, string $bind = '')
    {
        if (is_array($value)) {
            $first = reset($value);
            $value = is_array($first) ? '' : $first;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return $value;
        }
        $info = $this->getDirectoryInfo($prop);
        if (!$info) {
            return $value;
        }
        $rows = $this->getDirectoryRows($info['DATA_CLASS'], (string)$info['TABLE_NAME'], $info['FIELDS']);
        $key = mb_strtoupper($value);
        $xml = '';
        if ($bind === 'id') {
            $xml = $rows['ID'][(string)(int)$value] ?? '';
        } elseif ($bind === 'xml_id') {
            $xml = $rows['XML'][$key] ?? '';
        } elseif (str_starts_with($bind, 'col_')) {
            $code = strtoupper(substr($bind, 4));
            $xml = $rows['COL'][$code][$key] ?? '';
        } else {
            $xml = $rows['XML'][$key] ?? '';
            if ($xml === '' && preg_match('/^\d+$/', $value)) {
                $xml = $rows['ID'][(string)(int)$value] ?? '';
            }
            if ($xml === '') {
                foreach ($rows['COL'] as $map) {
                    if (isset($map[$key])) {
                        $xml = $map[$key];
                        break;
                    }
                }
            }
        }
        if ($xml !== '') {
            return $xml;
        }
        // не найдено — создать новую запись из JSON-описания
        $created = $this->createDirectoryItemFromJson($info, $value);
        if ($created !== '') {
            return $created;
        }
        Logger::warning(
            'Значение справочника не найдено',
            ['table' => (string)$info['TABLE_NAME'], 'value' => $value, 'bind' => $bind]
        );
        return $value;
    }

    /**
     * Создание записи HL-справочника из JSON-описания
     * (результат действия «сформировать новое значение»),
     * напр. {"UF_NAME":"test","XML_ID":"test"}.
     * XML_ID берется из ключей XML_ID/UF_XML_ID (или генерируется), значение — XML_ID.
     *
     * @param array $info данные справочника (getDirectoryInfo)
     * @param string $value JSON-описание записи
     * @return string XML_ID созданной записи ('' — создать не удалось/не JSON)
     */
    protected function createDirectoryItemFromJson(array $info, string $value): string
    {
        $fields = $this->decodeCreateFields($value);
        if ($fields === null) {
            return '';
        }
        // ключ XML_ID из действия маппится в UF_XML_ID
        if (array_key_exists('XML_ID', $fields) && !array_key_exists('UF_XML_ID', $fields)) {
            $fields['UF_XML_ID'] = $fields['XML_ID'];
        }
        $xml = trim((string)($fields['UF_XML_ID'] ?? ''));
        if ($xml === '') {
            $xml = md5(uniqid('', true));
            $fields['UF_XML_ID'] = $xml;
        }
        try {
            $dataClass = $info['DATA_CLASS'];
            $result = $dataClass::add($fields);
            if ($result->isSuccess()) {
                // сбрасываем кеш строк — новая запись должна находиться
                unset(self::$directoryRowsCache[(string)$info['TABLE_NAME']]);
                return $xml;
            }
            Logger::warning(
                'Не удалось создать запись справочника HL',
                ['table' => (string)$info['TABLE_NAME'], 'errors' => $result->getErrorMessages()]
            );
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка создания записи справочника HL',
                ['table' => (string)$info['TABLE_NAME'], 'error' => $e->getMessage()]
            );
        }
        return '';
    }

    /**
     * Разбор JSON-описания создаваемой записи (одиночный объект
     * с текстовыми ключами). Список/не-JSON — null.
     */
    protected function decodeCreateFields($value): ?array
    {
        $data = is_array($value) ? $value : json_decode(trim((string)$value), true);
        if (!is_array($data) || !$data) {
            return null;
        }
        if (array_keys($data) === range(0, count($data) - 1)) {
            return null; // список значений — не описание записи
        }
        return $data;
    }

    /**
     * Создание элемента инфоблока из JSON-описания
     * (результат действия «сформировать новое значение»),
     * напр. {"NAME":"Название","IBLOCK_ID":"5","CODE":"code"}.
     * Вызывается, когда привязка свойства E не найдена.
     *
     * @param string $value JSON-описание элемента
     * @param int $linkIblockId инфоблок свойства E (fallback для IBLOCK_ID)
     * @return int ID созданного элемента (0 — создать не удалось/не JSON)
     */
    protected function createElementFromJson(string $value, int $linkIblockId): int
    {
        $fields = $this->decodeCreateFields($value);
        if ($fields === null) {
            return 0;
        }
        if ((int)($fields['IBLOCK_ID'] ?? 0) <= 0 && $linkIblockId > 0) {
            $fields['IBLOCK_ID'] = $linkIblockId;
        }
        if ((int)($fields['IBLOCK_ID'] ?? 0) <= 0 || trim((string)($fields['NAME'] ?? '')) === '') {
            Logger::warning(
                'Недостаточно данных для создания элемента из JSON',
                ['fields' => array_keys($fields)]
            );
            return 0;
        }
        try {
            $newId = \Awz\Allxls\Handlers::withImportFlag(function () use ($fields) {
                $el = new \CIBlockElement();
                return $el->Add($fields, false, false);
            }, (string)(int)$fields['IBLOCK_ID']);
            return (int)$newId;
        } catch (\Throwable $e) {
            Logger::warning('Ошибка создания элемента из JSON', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Создание раздела инфоблока из JSON-описания
     * (результат действия «сформировать новое значение»),
     * напр. {"NAME":"Раздел","IBLOCK_ID":"5","IBLOCK_SECTION_ID":"0"}.
     * Вызывается, когда привязка свойства G не найдена.
     *
     * @param string $value JSON-описание раздела
     * @param int $linkIblockId инфоблок свойства G (fallback для IBLOCK_ID)
     * @return int ID созданного раздела (0 — создать не удалось/не JSON)
     */
    protected function createSectionFromJson(string $value, int $linkIblockId): int
    {
        $fields = $this->decodeCreateFields($value);
        if ($fields === null) {
            return 0;
        }
        if ((int)($fields['IBLOCK_ID'] ?? 0) <= 0 && $linkIblockId > 0) {
            $fields['IBLOCK_ID'] = $linkIblockId;
        }
        if ((int)($fields['IBLOCK_ID'] ?? 0) <= 0 || trim((string)($fields['NAME'] ?? '')) === '') {
            Logger::warning(
                'Недостаточно данных для создания раздела из JSON',
                ['fields' => array_keys($fields)]
            );
            return 0;
        }
        try {
            $newId = \Awz\Allxls\Handlers::withImportFlag(function () use ($fields) {
                $section = new \CIBlockSection();
                return $section->Add($fields);
            }, (string)(int)$fields['IBLOCK_ID']);
            return (int)$newId;
        } catch (\Throwable $e) {
            Logger::warning('Ошибка создания раздела из JSON', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Значение списка -> ID варианта (по VALUE или XML_ID), с автосозданием.
     *
     * @param string $bind тип привязки: '' — авто, 'id' — только ID,
     *                     'xml_id' — только XML_ID, 'name' — только название
     * @param bool $createMissing автосоздание отсутствующего значения списка
     *                            (N — значение не найдено, вернуть как есть)
     */
    protected function resolveEnumId(int $propertyId, $value, string $bind = '', bool $createMissing = true)
    {
        if ($value === false || $value === 'false') {
            return false; // удаление
        }
        // явная привязка к ID: значение трактуется как ID варианта
        if ($bind === 'id') {
            return is_numeric($value) ? (int)$value : $value;
        }
        $key = strtoupper(trim((string)$value));
        if (!isset(self::$enumCache[$propertyId])) {
            // кеш: VALUE-карта и XML_ID-карта хранятся раздельно, чтобы
            // строгие привязки (name/xml_id) не смешивались с авто-поиском
            self::$enumCache[$propertyId] = ['VALUE' => [], 'XML' => [], 'FLAT' => []];
            $rs = \CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC'],
                ['PROPERTY_ID' => $propertyId]
            );
            while ($row = $rs->Fetch()) {
                $id = (int)$row['ID'];
                self::$enumCache[$propertyId]['VALUE'][strtoupper((string)$row['VALUE'])] = $id;
                if ($row['XML_ID'] !== '') {
                    self::$enumCache[$propertyId]['XML'][strtoupper((string)$row['XML_ID'])] = $id;
                }
                self::$enumCache[$propertyId]['FLAT'][strtoupper((string)$row['VALUE'])] = $id;
                if ($row['XML_ID'] !== '') {
                    self::$enumCache[$propertyId]['FLAT'][strtoupper((string)$row['XML_ID'])] = $id;
                }
            }
        }
        if ($bind === 'xml_id') {
            $enumId = self::$enumCache[$propertyId]['XML'][$key] ?? null;
            if ($enumId) {
                return $enumId;
            }
            Logger::warning(
                'Значение списка не найдено по XML_ID',
                ['property_id' => $propertyId, 'value' => $value]
            );
            return $value;
        }
        if ($bind === 'name') {
            $enumId = self::$enumCache[$propertyId]['VALUE'][$key] ?? null;
            if ($enumId) {
                return $enumId;
            }
            // не найдено — автосоздание по названию (ниже)
        } else {
            // авто: VALUE или XML_ID в одной карте
            $enumId = self::$enumCache[$propertyId]['FLAT'][$key] ?? null;
            if ($enumId) {
                return $enumId;
            }
        }
        // автосоздание отсутствующего значения списка отключено — значение как есть
        if (!$createMissing) {
            Logger::warning(
                'Значение списка не найдено, автосоздание отключено',
                ['property_id' => $propertyId, 'value' => $value, 'bind' => $bind]
            );
            return $value;
        }
        $enum = new \CIBlockPropertyEnum();
        $newId = $enum->Add([
            'PROPERTY_ID' => $propertyId,
            'VALUE' => (string)$value,
            'XML_ID' => md5($propertyId.'|'.$key),
        ]);
        if ($newId) {
            self::$enumCache[$propertyId]['VALUE'][$key] = (int)$newId;
            self::$enumCache[$propertyId]['FLAT'][$key] = (int)$newId;
            return (int)$newId;
        }
        Logger::warning('Не удалось создать значение списка', ['property_id' => $propertyId, 'value' => $value]);
        return $value;
    }

    /**
     * Значение свойства E -> ID элемента связанного инфоблока
     * (поиск по ID / XML_ID / CODE / NAME).
     *
     * @param string $bind тип привязки: '' — авто, 'id' — только ID,
     *                     'xml_id' — только XML_ID, 'name' — только название, 'code' — только код
     */
    protected function resolveLinkElementId(int $linkIblockId, string $value, string $bind = '')
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if ($linkIblockId <= 0) {
            return (int)$value;
        }
        // явная привязка к ID: значение трактуется как ID элемента
        if ($bind === 'id') {
            return preg_match('/^\d+$/', $value) ? (int)$value : 0;
        }
        $cacheKey = $linkIblockId . ':' . ($bind !== '' ? $bind : 'auto');
        if (!isset(self::$linkElementCache[$cacheKey])) {
            self::$linkElementCache[$cacheKey] = [];
        }
        $key = strtoupper($value);
        if (isset(self::$linkElementCache[$cacheKey][$key])) {
            return self::$linkElementCache[$cacheKey][$key];
        }
        $elementId = 0;
        if ($bind !== '' && preg_match('/^\d+$/', $value)) {
            // строгая привязка по полю: числовое значение ищется как значение поля
            $field = ['xml_id' => 'XML_ID', 'name' => 'NAME', 'code' => 'CODE'][$bind] ?? '';
            if ($field !== '') {
                $rs = \CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    ['IBLOCK_ID' => $linkIblockId, '=' . $field => $value],
                    false,
                    ['nTopCount' => 1],
                    ['ID']
                );
                while ($row = $rs->Fetch()) {
                    $elementId = (int)$row['ID'];
                    break;
                }
            }
        } elseif ($bind === 'xml_id' || $bind === 'name' || $bind === 'code') {
            $field = ['xml_id' => 'XML_ID', 'name' => 'NAME', 'code' => 'CODE'][$bind];
            $rs = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $linkIblockId, '=' . $field => $value],
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            while ($row = $rs->Fetch()) {
                $elementId = (int)$row['ID'];
                break;
            }
        } elseif (preg_match('/^\d+$/', $value)) {
            // авто: числовое значение — ID элемента
            $elementId = (int)$value;
        } else {
            $rs = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $linkIblockId,
                    [
                        'LOGIC' => 'OR',
                        '=XML_ID' => $value,
                        '=CODE' => $value,
                        '=NAME' => $value,
                    ],
                ],
                false,
                ['nTopCount' => 1],
                ['ID']
            );
            while ($row = $rs->Fetch()) {
                $elementId = (int)$row['ID'];
                break;
            }
        }
        if ($elementId <= 0) {
            Logger::warning('Элемент связи не найден', ['link_iblock_id' => $linkIblockId, 'value' => $value, 'bind' => $bind]);
            return false;
        }
        self::$linkElementCache[$cacheKey][$key] = $elementId;
        return $elementId;
    }

    /**
     * Значение свойства G -> ID раздела (поиск по ID / XML_ID / NAME / CODE).
     *
     * @param string $bind тип привязки: '' — авто, 'id' — только ID,
     *                     'xml_id' — только XML_ID, 'name' — только название, 'code' — только код
     */
    protected function resolveSectionId(int $linkIblockId, string $value, string $bind = '')
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if ($linkIblockId <= 0) {
            return (int)$value;
        }
        // явная привязка к ID: значение трактуется как ID раздела
        if ($bind === 'id') {
            return preg_match('/^\d+$/', $value) ? (int)$value : 0;
        }
        $cacheKey = $linkIblockId . ':' . ($bind !== '' ? $bind : 'auto');
        if (!isset(self::$sectionCache[$cacheKey])) {
            self::$sectionCache[$cacheKey] = [];
        }
        $key = strtoupper($value);
        if (isset(self::$sectionCache[$cacheKey][$key])) {
            return self::$sectionCache[$cacheKey][$key];
        }
        $sectionId = 0;
        $field = ['xml_id' => 'XML_ID', 'name' => 'NAME', 'code' => 'CODE'][$bind] ?? '';
        if ($field !== '') {
            // строгая привязка: поиск только по одному полю (число — тоже значение поля)
            $rs = \CIBlockSection::GetList(
                ['SORT' => 'ASC'],
                ['IBLOCK_ID' => $linkIblockId, $field => $value],
                false,
                ['ID'],
                false
            );
            while ($row = $rs->Fetch()) {
                $sectionId = (int)$row['ID'];
                break;
            }
        } elseif (preg_match('/^\d+$/', $value)) {
            // авто: числовое значение — ID раздела
            $sectionId = (int)$value;
        } else {
            $rs = \CIBlockSection::GetList(
                ['SORT' => 'ASC'],
                [
                    'IBLOCK_ID' => $linkIblockId,
                    [
                        'LOGIC' => 'OR',
                        'NAME' => $value,
                        'CODE' => $value,
                    ],
                ],
                false,
                ['ID'],
                false
            );
            while ($row = $rs->Fetch()) {
                $sectionId = (int)$row['ID'];
                break;
            }
        }
        if ($sectionId <= 0) {
            Logger::warning('Раздел связи не найден', ['link_iblock_id' => $linkIblockId, 'value' => $value, 'bind' => $bind]);
            return false;
        }
        self::$sectionCache[$cacheKey][$key] = $sectionId;
        return $sectionId;
    }

    /**
     * @inheritDoc
     */
    public function buildFilter(string $entityId, array $item, array $sheetConfig, int $iblockId = 0): array
    {
        $filter = [];

        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        foreach ($columns as $key => $col) {
            if (($col['INCLUDE'] ?? 'Y') === 'N') continue;

            // ключи $item — выбранные поля назначения (MAPS[*]): NAME, PROPERTY_XXX и т.д.
            $maps = is_array($col['MAPS'] ?? null) ? $col['MAPS'] : [];
            $mapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];

            foreach ($maps as $mapIdx => $field) {
                $field = trim((string)$field);
                if ($field === '' || !array_key_exists($field, $item)) continue;

                $settings = is_array($mapSettings[$mapIdx] ?? null) ? $mapSettings[$mapIdx] : [];
                $search = $this->getColumnSearch($entityId, [
                    'SEARCH_USE' => $col['SEARCH_USE'] ?? 'N',
                    'SEARCH_MODE' => $col['SEARCH_MODE'] ?? 'eq',
                    'MAP_SETTINGS' => [$settings],
                ]);
                if ($search === null) continue;

                $value = (string)$item[$field];
                if ($value === '') continue;

                $condition = $this->getFieldCondition($field, $search['MODE']);
                if ($condition === '') continue;

                $filter[$condition] = $this->prepareValue($value, $search['MODE']);
            }
        }
        if (!empty($filter) && $iblockId > 0) {
            $filter['IBLOCK_ID'] = $iblockId;
        }

        return $filter;
    }

    /**
     * Условие фильтра для поля согласно режиму поиска.
     *
     * @param string $field поле (алиас): NAME, CODE, PROPERTY_XXX ...
     * @param string $mode режим поиска (eq/begin/end/contains)
     * @return string ключ условия фильтра ('=NAME', 'NAME', ...)
     */
    protected function getFieldCondition(string $field, string $mode): string
    {
        switch ($mode) {
            case 'eq':
                return '=' . $field;
            case 'begin':
            case 'end':
            case 'contains':
                // поиск по подстроке через старый API: '%FIELD' + маска в значении
                return '%' . $field;
        }
        return '';
    }

    /**
     * Подготовка значения под режим поиска (маска %).
     *
     * @param string $value значение элемента
     * @param string $mode режим поиска
     * @return string
     */
    protected function prepareValue(string $value, string $mode): string
    {
        switch ($mode) {
            case 'begin':
                return $value . '%';
            case 'end':
                return '%' . $value;
            case 'contains':
                return '%' . $value . '%';
            default:
                return $value;
        }
    }

    /** @var array статический кеш результатов getColumnSearch (ключ => результат) */
    private static array $columnSearchCache = [];

    /**
     * @inheritDoc
     */
    public function getColumnSearch(string $entityId, array $col): ?array
    {
        // ключ кеша строится только по данным, влияющим на результат
        $cacheKey = md5(serialize([
            $entityId,
            $col['SEARCH_USE'] ?? 'N',
            $col['SEARCH_MODE'] ?? 'eq',
            is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [],
        ]));

        if (array_key_exists($cacheKey, self::$columnSearchCache)) {
            return self::$columnSearchCache[$cacheKey];
        }

        $result = $this->detectColumnSearch($col);

        self::$columnSearchCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Непосредственное определение параметров поиска колонки (без кеша).
     *
     * @param array $col конфиг колонки
     * @return array|null ['USE'=>bool,'MODE'=>string] или null, если поиск не включен
     */
    private function detectColumnSearch(array $col): ?array
    {
        if (($col['SEARCH_USE'] ?? 'N') === 'Y') {
            return [
                'USE' => true,
                'MODE' => $this->normalizeMode((string)($col['SEARCH_MODE'] ?? 'eq')),
            ];
        }

        $mapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];
        foreach ($mapSettings as $settings) {
            if (!is_array($settings)) continue;
            if (($settings['SEARCH_USE'] ?? 'N') === 'Y') {
                return [
                    'USE' => true,
                    'MODE' => $this->normalizeMode((string)($settings['SEARCH_MODE'] ?? 'eq')),
                ];
            }
        }

        return null;
    }

    /**
     * Список дополнительных макросов для найденного элемента (для правил).
     *
     * По массиву, который вернул findElement, строит макросы вида
     * #ELEMENT_<ПОЛЕ># и #ELEMENT_PROPERTY_<КОД># — значения найденного
     * элемента. Имена нормализуются так же, как #COL_<KEY># в MapRules
     * (все не [a-z0-9] заменяются на _), чтобы макросы совпадали с
     * раскрытием в expandMacros.
     *
     * @param array $element массив, который вернул findElement
     * @param array $settings настройки поля сопоставления (MAP_SETTINGS колонки)
     * @return array макрос => описание
     */
    public function getFoundElementMacros(array $element, array $settings = []): array
    {
        if (empty($element)) {
            return [];
        }
        // поле сопоставления — чтобы пометить главный макрос (поле поиска)
        $mappedField = '';
        $maps = $settings['MAPS'] ?? [];
        if (is_string($maps)) {
            $maps = $maps !== '' ? [$maps] : [];
        }
        if (is_array($maps) && isset($maps[0]) && is_string($maps[0])) {
            $mappedField = $maps[0];
        }
        $result = [];
        foreach ($element as $key => $value) {
            if ($value instanceof \CIBlockResult || is_object($value)) continue;
            if (is_array($value)) {
                $value = implode(',', array_map(
                    static fn($v) => is_scalar($v) ? (string)$v : json_encode($v),
                    $value
                ));
            }
            $macro = '#ELEMENT_' . strtoupper(preg_replace('/[^a-z0-9]/i', '_', (string)$key)) . '#';
            $desc = 'Значение найденного элемента: ' . $key;
            if ($key === $mappedField) {
                $desc .= ' (поле поиска)';
            }
            $result[$macro] = $desc;
        }
        return $result;
    }

    /**
     * HTML блока «опции после завершения импорта» (этап 4).
     * Опции применяются один раз по окончании импорта
     * (Pimport::clearIblockCache при done/finishAction).
     *
     * @param array $settings настройки профиля
     * @return string HTML опций (пустая строка — блок не показывается)
     */
    public function getFinishOptionsHtml(array $settings): string
    {
        $checked = (string)($settings['SYSTEM_IBLOCK_CACHE'] ?? 'N') === 'Y' ? ' checked' : '';
        $label = Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_IBLOCK_CACHE')
            ?: 'Сбрасывать кеш инфоблока';
        $help = trim((string)(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_IBLOCK_CACHE_HELP')
            ?: 'По окончании импорта сбрасывать кеш инфоблока (тегированный кеш и управляемый кеш модуля по этому инфоблоку), чтобы изменения сразу были видны в компонентах с кешированием. По умолчанию выключено.'));
        $helpHtml = $help !== ''
            ? '<span class="awz-help" tabindex="0"><span class="awz-help__popup">'
                .htmlspecialcharsbx($help).'</span>?</span>'
            : '';
        $html = '<div class="awz-opt-row">'
            .'<span class="awz-opt-row__label awz-allxls-switch__label">'
                .htmlspecialcharsbx($label).$helpHtml.'</span>'
            .'<div class="awz-opt-row__control">'
                .'<label class="awz-switch">'
                    .'<input type="checkbox" id="awz_allxls_profile_process_sys_iblock_cache"'.$checked.'>'
                    .'<span></span>'
                .'</label>'
            .'</div>'
        .'</div>';

        // очистка устаревших элементов: удаление или депубликация элементов,
        // не обновлявшихся с момента запуска завершенных профилей
        $mode = (string)($settings['FINISH_CLEAN_MODE'] ?? '');
        $scope = (string)($settings['FINISH_CLEAN_SCOPE'] ?? 'profile');
        $cleanLabel = Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN') ?: 'Устаревшие элементы';
        $cleanHelp = trim((string)(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_HELP') ?: ''));
        $cleanHelpHtml = $cleanHelp !== ''
            ? '<span class="awz-help" tabindex="0"><span class="awz-help__popup">'
                .htmlspecialcharsbx($cleanHelp).'</span>?</span>'
            : '';
        $html .= '<div class="awz-opt-row">'
            .'<span class="awz-opt-row__label">'.htmlspecialcharsbx($cleanLabel).$cleanHelpHtml.'</span>'
            .'<div class="awz-opt-row__control">'
                .'<select id="awz_allxls_profile_process_finish_clean_mode">'
                    .'<option value=""'.($mode === '' ? ' selected' : '').'>'
                        .htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_OFF') ?: 'Выключено').'</option>'
                    .'<option value="deactivate"'.($mode === 'deactivate' ? ' selected' : '').'>'
                        .htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_DEACTIVATE') ?: 'Деактивировать').'</option>'
                    .'<option value="delete"'.($mode === 'delete' ? ' selected' : '').'>'
                        .htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_DELETE') ?: 'Удалять').'</option>'
                .'</select>'
            .'</div>'
        .'</div>';

        $scopeLabel = Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE') ?: 'Охват';
        $scopeHelp = trim((string)(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE_HELP') ?: ''));
        $scopeHelpHtml = $scopeHelp !== ''
            ? '<span class="awz-help" tabindex="0"><span class="awz-help__popup">'
                .htmlspecialcharsbx($scopeHelp).'</span>?</span>'
            : '';
        $html .= '<div class="awz-opt-row">'
            .'<span class="awz-opt-row__label">'.htmlspecialcharsbx($scopeLabel).$scopeHelpHtml.'</span>'
            .'<div class="awz-opt-row__control">'
                .'<select id="awz_allxls_profile_process_finish_clean_scope">'
                    .'<option value="profile"'.($scope !== 'section' ? ' selected' : '').'>'
                        .htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE_PROFILE') ?: 'Текущий профиль').'</option>'
                    .'<option value="section"'.($scope === 'section' ? ' selected' : '').'>'
                        .htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE_SECTION') ?: 'Профили раздела').'</option>'
                .'</select>'
            .'</div>'
        .'</div>';

        // информация о последней очистке (заполняется агентом) — возле опций
        $process = is_array($settings['PROCESS'] ?? null) ? $settings['PROCESS'] : [];
        $info = is_array($process['FINISH_CLEAN_INFO'] ?? null) ? $process['FINISH_CLEAN_INFO'] : [];
        if ($info) {
            $date = (string)($info['DATE'] ?? '');
            $modeInfo = (string)($info['MODE'] ?? '') === 'delete'
                ? (Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_DELETE') ?: 'Удалять')
                : (Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_DEACTIVATE') ?: 'Деактивировать');
            $scopeInfo = (string)($info['SCOPE'] ?? '') === 'section'
                ? (Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE_SECTION') ?: 'Профили раздела')
                : (Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_SCOPE_PROFILE') ?: 'Текущий профиль');
            $line = sprintf(
                '%s: %s, охват «%s», обработано %d, ошибок %d, порог %s%s',
                Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_LAST') ?: 'Последняя очистка',
                $date,
                $scopeInfo,
                (int)($info['PROCESSED'] ?? 0),
                (int)($info['FAIL'] ?? 0),
                (string)($info['THRESHOLD'] ?? ''),
                (string)($info['DONE'] ?? '') === 'N'
                    ? ' ('.(Loc::getMessage('AWZ_ALLXLS_SERVICES_IBLOCK_FINISH_CLEAN_CONT') ?: 'продолжается агентом').')'
                    : ''
            );
            $html .= '<div style="margin-top:10px;font-size:12px;color:#7a8797;">'
                .htmlspecialcharsbx($line).'</div>';
        }

        return $html;
    }

    /**
     * Сохранение итога последней очистки в состояние профиля
     * (PROCESS.FINISH_CLEAN_INFO) — отображается в блоке опций шага 4.
     *
     * @param int $profileId ID профиля
     * @param array $info итог батча очистки
     */
    protected static function saveFinishCleanInfo(int $profileId, array $info): void
    {
        try {
            $profile = ProfilesTable::getById($profileId)->fetch();
            if (!$profile) return;
            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            if (!is_array($settings['PROCESS'] ?? null)) $settings['PROCESS'] = [];
            $settings['PROCESS']['FINISH_CLEAN_INFO'] = $info;
            ProfilesTable::update($profileId, ['SETTINGS' => $settings]);
        } catch (\Throwable $e) {
            Logger::warning(
                'Не сохранен итог очистки устаревших элементов',
                ['profile_id' => $profileId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Агент очистки устаревших элементов инфоблока (опции FINISH_CLEAN_*).
     * Удаляет или деактивирует элементы, не обновлявшиеся с момента запуска
     * (RUN_STARTED) завершенных профилей: охват — текущий профиль или все
     * завершенные импорт-профили раздела с тем же инфоблоком-приемником
     * (порог = минимум RUN_STARTED). Регистрируется однократно при
     * завершении импорта (Pimport::scheduleFinishClean), обрабатывает
     * элементы батчами и перерегистрирует себя, пока есть кандидаты.
     *
     * @param int $profileId ID профиля
     * @return string '' — агент завершен, строка даты — следующий запуск
     */
    public static function finishCleanAgent(int $profileId = 0): string
    {
        if ($profileId <= 0) return '';
        try {
            $profile = ProfilesTable::getById($profileId)->fetch();
            if (!$profile) return '';
            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            if ((string)($settings['DEST_TYPE'] ?? '') !== 'iblock') return '';
            $mode = (string)($settings['FINISH_CLEAN_MODE'] ?? '');
            if (!in_array($mode, ['deactivate', 'delete'], true)) return '';
            $iblockId = (int)($settings['IBLOCK_ID'] ?? 0);
            if ($iblockId <= 0) return '';

            // порог: минимум времени запуска завершенных профилей охвата
            // (профили в обработке порог не меняют — ожидание не требуется)
            $scopeSection = (string)($settings['FINISH_CLEAN_SCOPE'] ?? 'profile') === 'section'
                ? (int)($profile['SECTION_ID'] ?? 0) : null;
            $starts = [];
            $rs = ProfilesTable::getList(['select' => ['ID', 'SECTION_ID', 'SETTINGS'], 'filter' => ['TYPE' => 'import']]);
            while ($row = $rs->fetch()) {
                if ($scopeSection !== null && (int)$row['SECTION_ID'] !== $scopeSection) continue;
                if ($scopeSection === null && (int)$row['ID'] !== $profileId) continue;
                $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
                if ((string)($st['DEST_TYPE'] ?? '') !== 'iblock') continue;
                if ((int)($st['IBLOCK_ID'] ?? 0) !== $iblockId) continue;
                $process = is_array($st['PROCESS'] ?? null) ? $st['PROCESS'] : [];
                if ((string)($process['DONE'] ?? '') !== 'Y') continue;
                $started = (int)($process['RUN_STARTED'] ?? 0);
                if ($started > 0) $starts[] = $started;
            }
            if (!$starts) return '';
            $threshold = \Bitrix\Main\Type\DateTime::createFromTimestamp(min($starts));

            // батч кандидатов: элементы старше порога
            $filter = ['IBLOCK_ID' => $iblockId, '<=TIMESTAMP_X' => $threshold];
            if ($mode === 'deactivate') $filter['ACTIVE'] = 'Y';
            $res = \CIBlockElement::GetList(
                ['ID' => 'ASC'], $filter, false,
                ['nTopCount' => self::FINISH_CLEAN_BATCH], ['ID']
            );
            $ids = [];
            while ($el = $res->Fetch()) $ids[] = (int)$el['ID'];
            if (!$ids) return '';

            $ok = 0;
            $fail = 0;
            $processed = 0;
            $hitTimeLimit = false;
            $deadline = microtime(true) + self::FINISH_CLEAN_TIME_LIMIT;
            foreach ($ids as $elementId) {
                // лимит времени шага агента: по истечении — следующий шаг агентом
                if (microtime(true) >= $deadline && $processed > 0) {
                    $hitTimeLimit = true;
                    break;
                }
                try {
                    if ($mode === 'delete') {
                        // внешнее удаление: событие OnBeforeIBlockElementDelete
                        // очищает хеши элемента (повторный импорт возможен)
                        $ok += \CIBlockElement::Delete($elementId) ? 1 : 0;
                    } else {
                        $el = new \CIBlockElement(false);
                        $ok += $el->Update($elementId, ['ACTIVE' => 'N']) ? 1 : 0;
                    }
                } catch (\Throwable $e) {
                    $fail++;
                }
                $processed++;
            }
            Logger::info(
                'Очистка устаревших элементов после импорта',
                [
                    'profile_id' => $profileId,
                    'iblock_id' => $iblockId,
                    'mode' => $mode,
                    'scope' => $scopeSection !== null ? 'section' : 'profile',
                    'threshold' => $threshold->format('Y-m-d H:i:s'),
                    'batch' => $processed,
                    'ok' => $ok,
                    'fail' => $fail,
                    'time_limit' => $hitTimeLimit,
                ]
            );
            // итог очистки — для показа в блоке «Опции после завершения импорта»
            $moreCandidates = $hitTimeLimit || $processed >= self::FINISH_CLEAN_BATCH;
            self::saveFinishCleanInfo($profileId, [
                'DATE' => date('Y-m-d H:i:s'),
                'MODE' => $mode,
                'SCOPE' => $scopeSection !== null ? 'section' : 'profile',
                'THRESHOLD' => $threshold->format('Y-m-d H:i:s'),
                'PROCESSED' => $processed,
                'OK' => $ok,
                'FAIL' => $fail,
                'DONE' => $moreCandidates ? 'N' : 'Y',
            ]);
            // остались кандидаты — следующий шаг агента
            if ($moreCandidates) {
                return \ConvertTimeStamp(time() + 10, 'FULL');
            }
            return '';
        } catch (\Throwable $e) {
            Logger::error(
                'Ошибка агента очистки устаревших элементов',
                ['profile_id' => $profileId, 'error' => $e->getMessage()]
            );
            return '';
        }
    }

    /**
     * Нормализация режима поиска.
     *
     * @param string $mode
     * @return string
     */
    protected function normalizeMode(string $mode): string
    {
        return in_array($mode, ['eq', 'begin', 'end', 'contains'], true) ? $mode : 'eq';
    }


}
