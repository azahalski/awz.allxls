<?php
namespace Awz\Allxls\Services;

use Bitrix\Main\Loader;

/**
 * Сервис работы с инфоблоками для пошагового импорта.
 *
 * Инкапсулирует операции поиска/создания/обновления элементов инфоблока
 * и сборку фильтра отбора. Получается через ServiceLocator:
 *  \Bitrix\Main\DI\ServiceLocator::getInstance()->get('awz.allxls.IblockService')
 *
 * Регистрация: bitrix/modules/awz.allxls/.settings.php (ключ 'services').
 */
interface Base
{
    /**
     * Поиск существующего элемента инфоблока по фильтру.
     * @param array $filter фильтр
     * @param array $select список выбираемых полей
     * @return object|null _CIBElement / CIBlockElement::GetList результат или null
     */
    public function findElement(string $entityId, array $filter, array $select = ['ID']);

    /**
     * Создание нового элемента инфоблока.
     * @param array $fields поля элемента (IBLOCK_ID обязателен)
     * @return int ID нового элемента
     * @throws \RuntimeException при ошибке добавления
     */
    public function addElement(string $entityId, array $fields): int;

    /**
     * Обновление существующего элемента инфоблока.
     * @param int $id ID элемента
     * @param array $fields поля элемента
     * @return bool
     * @throws \RuntimeException при ошибке обновления
     */
    public function updateElement(string $entityId, string $id, array $fields): bool;

    /**
     * Легкое обновление даты изменения элемента (без событий/реиндексов).
     * Используется опцией SYSTEM_REPLACE_DATE для неизмененных (пропущенных
     * по хешу) элементов: ELEMENT_ID берется из хеша, без поиска по фильтру.
     * @param int $id ID элемента
     * @return bool
     */
    public function touchElement(string $entityId, int $id): bool;

    /**
     * Удаление элемента (правило импорта TYPE=delete).
     * @param int $id ID элемента
     * @return bool
     */
    public function deleteElement(string $entityId, int $id): bool;

    /**
     * Сборка фильтра поиска элемента из собранного элемента и настроек листа.
     * @param array $item готовый элемент строки (алиас => значение)
     * @param array $sheetConfig настройки листа (COLUMNS с MAP_SETTINGS)
     * @param int $iblockId ID инфоблока
     * @return array фильтр для getList
     */
    public function buildFilter(string $entityId, array $item, array $sheetConfig): array;

    /**
     * Параметры поиска колонки (SEARCH_USE / SEARCH_MODE).
     * @param array $col конфиг колонки
     * @return array|null ['USE'=>bool,'MODE'=>string] или null
     */
    public function getColumnSearch(string $entityId, array $col): ?array;

    /**
     * Список дополнительных макросов для найденного элемента (для правил).
     * @param array $element массив, который вернул findElement
     * @param array $settings настройки поля сопоставления (MAP_SETTINGS колонки)
     * @return array макрос => описание
     */
    public function getFoundElementMacros(array $element, array $settings = []): array;
}