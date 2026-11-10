<?php
namespace Awz\Allxls;

use Awz\Allxls\Tables\ProfilesHashesTable;

/**
 * Обработчики событий модулей (main/iblock).
 *
 * Удаляет сохраненные хеши строк импорта при изменении/удалении
 * элемента инфоблока, чтобы повторный импорт заново обработал данные.
 *
 * Также сбрасывает хеши по событиям модуля catalog (обновление/удаление
 * товара, добавление/обновление/удаление цены). Обновление цен/остатков
 * самим импортом (Services\iblock::applyCatalog) отключает обработчики
 * через disableForEntity/enableForEntity.
 *
 * Обновления элементов, выполненные самим импортом (Pimport),
 * хеши НЕ удаляют — флаг static::$importProcessing и/или отключение
 * обработчиков для текущего инфоблока (static::$disabledEntities).
 */
class Handlers
{
    /** @var bool флаг "идет обработка элемента самим импортом" */
    public static bool $importProcessing = false;

    /** @var array<string,bool> ид инфоблоков (ENTITY_ID), для которых обработчики отключены в рамках текущего импорта */
    protected static array $disabledEntities = [];

    /** @var bool|null кеш значения опции логирования обработчиков */
    protected static ?bool $handlersLog = null;

    /**
     * Логирование обработчиков включено (опция модуля HANDLERS_LOG, по умолчанию N).
     */
    public static function isLoggingEnabled(): bool
    {
        if (self::$handlersLog === null) {
            self::$handlersLog = \Bitrix\Main\Config\Option::get('awz.allxls', 'HANDLERS_LOG', 'N') === 'Y';
        }
        return self::$handlersLog;
    }

    /**
     * Отключить обработчики для инфоблока на время текущего запроса.
     */
    public static function disableForEntity(string $entityId): void
    {
        if ($entityId !== '') {
            self::$disabledEntities[$entityId] = true;
        }
    }

    /**
     * Включить обработчики для инфоблока обратно.
     */
    public static function enableForEntity(string $entityId): void
    {
        unset(self::$disabledEntities[$entityId]);
    }

    /**
     * Выполнить код от имени импорта: на время выполнения $fn
     * включается флаг static::$importProcessing и обработчики
     * отключаются для инфоблока $entityId (если задан).
     * Обновления, выполненные внутри $fn, хеши элементов НЕ сбрасывают.
     *
     * @param callable $fn код, выполняемый в контексте импорта
     * @param string $entityId ид инфоблока (ENTITY_ID) — обработчики
     *        отключаются и для него на время выполнения
     * @return mixed результат $fn
     */
    public static function withImportFlag(callable $fn, string $entityId = '')
    {
        $prevFlag = self::$importProcessing;
        $prevDisabled = self::$disabledEntities;
        self::$importProcessing = true;
        if ($entityId !== '') {
            self::$disabledEntities[$entityId] = true;
        }
        try {
            return $fn();
        } finally {
            self::$importProcessing = $prevFlag;
            if ($entityId !== '') {
                self::$disabledEntities = $prevDisabled;
            }
        }
    }

    /**
     * Проверка: обработчики catalog доступны.
     * Пока элемент/цена обновляется самим импортом (с флагом importProcessing
     * или отключенными сущностями) — обработчики считаются выключенными,
     * внешние обновления сбрасывают хеши.
     */
    protected static function isCatalogHandlersEnabled(): bool
    {
        return !self::$importProcessing && empty(self::$disabledEntities);
    }

    /**
     * Проверка: обработчики для сущности отключены (импорт сам обновляет элементы).
     */
    protected static function isDisabled(string $entityId): bool
    {
        return self::$importProcessing
            || ($entityId !== '' && isset(self::$disabledEntities[$entityId]));
    }

    /**
     * iblock::OnAfterIBlockElementUpdate — элемент изменен внешним кодом.
     * Удаляем хеши строк, связанные с элементом.
     */
    public static function OnAfterIBlockElementUpdate(array $fields): void
    {
        $elementId = (string)($fields['ID'] ?? '');
        $entityId = (string)($fields['IBLOCK_ID'] ?? '');
        if ($entityId === '') {
            $entityId = self::getElementIblockId((int)$elementId);
        }
        if (self::isDisabled($entityId)) {
            return;
        }
        self::clearHashes($elementId, $entityId);
    }

    /**
     * iblock::OnBeforeIBlockElementDelete — элемент удален внешним кодом.
     * В $ID приходит ид элемента; IBLOCK_ID определяем по элементу.
     * Удаляем хеши строк, связанные с элементом.
     */
    public static function OnBeforeIBlockElementDelete(int $ID): void
    {
        $entityId = self::getElementIblockId($ID);
        if (self::isDisabled($entityId)) {
            return;
        }
        self::clearHashes((string)$ID, $entityId);
    }

    /**
     * catalog::OnProductUpdate / OnProductAdd — товар изменен внешним кодом
     * (количество, вес, тип товара и др. поля b_catalog_product).
     * Удаляем хеши строк, связанные с элементом.
     */
    public static function OnProductUpdate(int $ID): void
    {
        self::clearCatalogElementHashes($ID);
    }

    /**
     * catalog::OnBeforeProductDelete — товар удален внешним кодом.
     */
    public static function OnBeforeProductDelete(int $ID): void
    {
        self::clearCatalogElementHashes($ID);
    }

    /**
     * catalog::OnPriceAdd — добавлена цена внешним кодом.
     */
    public static function OnPriceAdd(int $ID, array $fields): void
    {
        $elementId = (int)($fields['PRODUCT_ID'] ?? 0);
        if ($elementId <= 0) {
            $elementId = self::getPriceProductId($ID);
        }
        if ($elementId > 0) {
            self::clearCatalogElementHashes($elementId);
        }
    }

    /**
     * catalog::OnPriceUpdate — изменена цена внешним кодом.
     */
    public static function OnPriceUpdate(int $ID, array $fields): void
    {
        $elementId = (int)($fields['PRODUCT_ID'] ?? 0);
        if ($elementId <= 0) {
            $elementId = self::getPriceProductId($ID);
        }
        if ($elementId > 0) {
            self::clearCatalogElementHashes($elementId);
        }
    }

    /**
     * catalog::OnBeforePriceDelete — удалена цена внешним кодом
     * ($ID — ид цены, $arFields может содержать PRODUCT_ID).
     */
    public static function OnBeforePriceDelete(int $ID, array $fields = []): void
    {
        $elementId = (int)($fields['PRODUCT_ID'] ?? 0);
        if ($elementId <= 0) {
            $elementId = self::getPriceProductId($ID);
        }
        if ($elementId > 0) {
            self::clearCatalogElementHashes($elementId);
        }
    }

    /**
     * D7-события товара (Bitrix\Catalog\ProductTable::onBeforeAdd/
     * onBeforeUpdate/onBeforeDelete) — товар изменен внешним кодом
     * через ORM D7 (остатки, вес и др. поля каталога).
     * ID товара = ID элемента — берем из primary события.
     *
     * @param \Bitrix\Main\ORM\Event $event
     */
    public static function onProductD7(\Bitrix\Main\ORM\Event $event): void
    {
        $primary = (array)$event->getParameter('primary');
        $elementId = (int)($primary['ID'] ?? 0);
        if ($elementId > 0) {
            self::clearCatalogElementHashes($elementId);
        }
    }

    /**
     * D7-события цены (Bitrix\Catalog\PriceTable::onBeforeAdd/onBeforeUpdate/
     * onBeforeDelete) — цена изменена внешним кодом через ORM D7.
     * Сбрасываем хеши элемента: PRODUCT_ID берется из полей события,
     * при отсутствии — по ид цены (primary).
     *
     * @param \Bitrix\Main\ORM\Event $event
     */
    public static function onPriceD7(\Bitrix\Main\ORM\Event $event): void
    {
        $fields = (array)$event->getParameter('fields');
        $primary = (array)$event->getParameter('primary');
        $elementId = (int)($fields['PRODUCT_ID'] ?? 0);
        if ($elementId <= 0) {
            $elementId = self::getPriceProductId((int)($primary['ID'] ?? 0));
        }
        if ($elementId > 0) {
            self::clearCatalogElementHashes($elementId);
        }
    }

    /**
     * Сброс хешей элемента по событиям модуля catalog.
     * IBLOCK_ID определяется по элементу: если элемента уже нет
     * (удаление) — хеши сбрасываются без привязки к сущности.
     */
    protected static function clearCatalogElementHashes(int $elementId): void
    {
        if ($elementId <= 0 || !self::isCatalogHandlersEnabled()) {
            return;
        }
        $entityId = self::getElementIblockId($elementId);
        if (self::isDisabled($entityId)) {
            return;
        }
        self::clearHashes((string)$elementId, $entityId);
    }

    /**
     * PRODUCT_ID цены по ид цены (b_catalog_price) — для событий,
     * в которых товар не передан.
     */
    protected static function getPriceProductId(int $priceId): int
    {
        if ($priceId <= 0) {
            return 0;
        }
        try {
            $row = \Bitrix\Catalog\PriceTable::getRow([
                'select' => ['PRODUCT_ID'],
                'filter' => ['=ID' => $priceId],
            ]);
            if ($row) {
                return (int)$row['PRODUCT_ID'];
            }
        } catch (\Throwable $e) {
            // нет цены/модуля — пусто
        }
        return 0;
    }

    /**
     * Получить IBLOCK_ID элемента (для универсальности — D7 с фолбэком на старое ядро).
     */
    protected static function getElementIblockId(int $elementId): string
    {
        if ($elementId <= 0) {
            return '';
        }
        try {
            $row = \Bitrix\Iblock\ElementTable::getRow([
                'select' => ['IBLOCK_ID'],
                'filter' => ['=ID' => $elementId],
            ]);
            if ($row) {
                return (string)$row['IBLOCK_ID'];
            }
        } catch (\Throwable $e) {
            // фолбэк на старое ядро ниже
        }
        try {
            $res = \CIBlockElement::GetList(
                [],
                ['ID' => $elementId],
                false,
                false,
                ['ID', 'IBLOCK_ID']
            );
            $row = $res->Fetch();
            if ($row && !empty($row['IBLOCK_ID'])) {
                return (string)$row['IBLOCK_ID'];
            }
        } catch (\Throwable $e) {
            // нет элемента/iblock — пусто
        }
        return '';
    }

    /**
     * Удаление хешей элемента (без исключений — не мешаем основному потоку).
     */
    protected static function clearHashes(string $elementId, string $entityId): void
    {
        try {
            // фиксируем факт сброса: так видно, какие элементы были
            // изменены/удалены внешним кодом и попадут в повторный импорт
            Logger::info(
                'Хеши элемента сброшены (внешнее изменение/удаление)',
                ['element_id' => $elementId, 'entity_id' => $entityId]
            );
            ProfilesHashesTable::clearElementHashes($elementId, $entityId);
        } catch (\Throwable $e) {
            Logger::error(
                'Ошибка удаления хешей элемента',
                ['element_id' => $elementId, 'entity_id' => $entityId, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * awz.allxls::onAwzAllxlsExpandMacros — расчет макросов полей
     * найденного при поиске элемента: #ENTITY_<ПОЛЕ>#
     * (#ENTITY_ID#, #ENTITY_NAME#, #ENTITY_PROPERTY_CODE#, #ENTITY_CATALOG_PRICE_1#...).
     * Данные элемента передаются вызывающим кодом (Pimport::processItem после
     * поиска) в options['entity_data']; в полной сборке (до поиска) их нет —
     * макрос раскрывается в пустую строку. Свойства в данных элемента
     * хранятся как PROPERTY_XXX_VALUE — поддерживаются оба варианта ключа.
     *
     * @param \Bitrix\Main\Event $event
     */
    public static function onAwzAllxlsExpandMacros(\Bitrix\Main\Event $event): void
    {
        $str = (string)$event->getParameter('str');
        if ($str === '' || mb_strpos($str, '#ENTITY_') === false) {
            return;
        }
        $options = (array)$event->getParameter('options');
        if (!is_array($options['entity_data'] ?? null)) {
            return;
        }
        $entityData = $options['entity_data'];
        $elementId = (int)($entityData['ID'] ?? 0);
        $str = preg_replace_callback('/#ENTITY_([A-Z0-9_]+)#/', function ($m) use ($entityData, $elementId) {
            $code = $m[1];
            $val = null;
            if (array_key_exists($code, $entityData)) {
                $val = $entityData[$code];
            } elseif (str_starts_with($code, 'PROPERTY_')
                && array_key_exists($code . '_VALUE', $entityData)) {
                $val = $entityData[$code . '_VALUE'];
            } elseif ($elementId > 0 && str_starts_with($code, 'PROPERTY_')) {
                // значения в entity_data нет — получаем значение свойства с элемента ИБ
                $val = self::getElementPropertyValue($elementId, substr($code, strlen('PROPERTY_')));
            } elseif ($elementId > 0 && str_starts_with($code, 'CATALOG_')) {
                // значения в entity_data нет — получаем каталожное значение с элемента
                // (цены CATALOG_PRICE_N / CATALOG_PRICE_N_CURRENCY, остатки складов
                // CATALOG_STORE_N, поля товара CATALOG_QUANTITY / CATALOG_WEIGHT...)
                $val = self::getEntityCatalogValue($elementId, substr($code, strlen('CATALOG_')));
            }
            if ($val === null) return '';
            return is_array($val) ? implode(', ', array_map('strval', $val)) : (string)$val;
        }, $str);
        $event->setParameter('str', $str);
        $event->addResult(new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::SUCCESS,
            ['str' => $str]
        ));
    }

    /**
     * awz.allxls::onAwzAllxlsGetMacros — добавление макросов #ENTITY_<КОД>#
     * по конкретным полям назначения (группы полей из getMacros) в список
     * макросов правил. Без модуля catalog макросы каталога (CATALOG_*)
     * в список не добавляются.
     *
     * @param \Bitrix\Main\Event $event
     */
    public static function onAwzAllxlsGetMacros(\Bitrix\Main\Event $event): void
    {
        $groups = (array)$event->getParameter('groups');
        if (!$groups) {
            return;
        }
        $catalogLoaded = \Bitrix\Main\Loader::includeModule('catalog');
        // служебные поля, не имеющие значения у найденного элемента:
        // в макросы #ENTITY_*# не включаются
        $excluded = ['IBLOCK_SECTION_PARENT' => true, 'CATEGORY_PATH' => true];
        $macros = [];
        $codes = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_array($group['ITEMS'] ?? null)) continue;
            foreach ($group['ITEMS'] as $item) {
                $code = trim((string)($item['ID'] ?? ''));
                if ($code === '' || isset($codes[$code]) || isset($excluded[$code])) continue;
                if (!$catalogLoaded && str_starts_with($code, 'CATALOG_')) continue;
                $codes[$code] = true;
                $macros['#ENTITY_' . strtoupper(preg_replace('/[^a-z0-9_]/i', '', $code)) . '#'] =
                    'Элемент: ' . (string)($item['NAME'] ?? $code);
            }
        }
        if (!$macros) {
            return;
        }
        $event->addResult(new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::SUCCESS,
            ['macros' => $macros]
        ));
    }

    /**
     * Значение свойства элемента инфоблока по коду свойства
     * (Bitrix\Iblock\ElementPropertyTable). Множественные значения
     * возвращаются массивом. Свойства нет / ошибка — null.
     *
     * @param int $elementId ID элемента инфоблока
     * @param string $propertyCode код свойства (без префикса PROPERTY_)
     * @return mixed|null
     */
    public static function getElementPropertyValue(int $elementId, string $propertyCode)
    {
        if ($elementId <= 0 || $propertyCode === '') {
            return null;
        }
        try {
            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                return null;
            }
            $rs = \Bitrix\Iblock\ElementPropertyTable::getList([
                'select' => ['VALUE'],
                'filter' => [
                    '=IBLOCK_ELEMENT_ID' => $elementId,
                    '=PROP.CODE' => $propertyCode,
                ],
                'runtime' => [
                    'PROP' => [
                        'data_type' => \Bitrix\Iblock\PropertyTable::class,
                        'reference' => ['=this.IBLOCK_PROPERTY_ID' => 'ref.ID'],
                    ],
                ],
            ]);
            $values = [];
            while ($property = $rs->fetch()) {
                $values[] = $property['VALUE'];
            }
            if (!$values) {
                return null;
            }
            return count($values) === 1 ? $values[0] : $values;
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка получения значения свойства элемента',
                ['element_id' => $elementId, 'property_code' => $propertyCode, 'error' => $e->getMessage()]
            );
            return null;
        }
    }

    /**
     * Каталожное значение элемента для макроса #ENTITY_CATALOG_XXX#:
     *  - PRICE_<тип цен>        — цена по типу цен (без диапазонов количества);
     *  - PRICE_<тип цен>_CURRENCY — валюта цены;
     *  - STORE_<склад>          — остаток на складе (b_catalog_store_product.AMOUNT);
     *  - прочие                 — поле товара из b_catalog_product
     *                             (QUANTITY, QUANTITY_RESERVED, WEIGHT, MEASURE...).
     * Значения нет / модуль catalog недоступен / ошибка — null.
     *
     * @param int $elementId ID элемента
     * @param string $code код без префикса CATALOG_ (верхний регистр)
     * @return mixed|null
     */
    protected static function getEntityCatalogValue(int $elementId, string $code)
    {
        if ($elementId <= 0 || $code === '') {
            return null;
        }
        try {
            if (!\Bitrix\Main\Loader::includeModule('catalog')) {
                return null;
            }
            // цена по типу цен / валюта цены
            if (preg_match('/^PRICE_(\d+?)(_CURRENCY)?$/', $code, $m)) {
                $row = \Bitrix\Catalog\PriceTable::getList([
                    'select' => ['PRICE', 'CURRENCY'],
                    'filter' => [
                        '=PRODUCT_ID' => $elementId,
                        '=CATALOG_GROUP_ID' => (int)$m[1],
                        '==QUANTITY_FROM' => null,
                        '==QUANTITY_TO' => null,
                    ],
                    'limit' => 1,
                ])->fetch();
                if (!$row) {
                    return null;
                }
                return ($m[2] ?? '') !== '' ? $row['CURRENCY'] : $row['PRICE'];
            }
            // остаток на складе
            if (preg_match('/^STORE_(\d+)$/', $code, $m)) {
                $row = \Bitrix\Catalog\StoreProductTable::getList([
                    'select' => ['AMOUNT'],
                    'filter' => [
                        '=PRODUCT_ID' => $elementId,
                        '=STORE_ID' => (int)$m[1],
                    ],
                    'limit' => 1,
                ])->fetch();
                return $row ? $row['AMOUNT'] : null;
            }
            // поле товара (b_catalog_product)
            $row = \Bitrix\Catalog\ProductTable::getByPrimary($elementId, [
                'select' => ['*'],
            ])->fetch();
            if (!$row || !array_key_exists($code, $row)) {
                return null;
            }
            return $row[$code];
        } catch (\Throwable $e) {
            Logger::warning(
                'Ошибка получения каталожного значения элемента',
                ['element_id' => $elementId, 'code' => $code, 'error' => $e->getMessage()]
            );
            return null;
        }
    }
}