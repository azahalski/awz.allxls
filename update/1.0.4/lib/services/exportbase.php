<?php
namespace Awz\Allxls\Services;

/**
 * Базовый интерфейс сервиса-источника данных для пошагового экспорта.
 *
 * Отдельный от импортного Services\Base: экспорт читает записи источника
 * (а не создает/обновляет элементы). Сервис получается через ServiceLocator:
 *  \Bitrix\Main\DI\ServiceLocator::getInstance()->get('awz.allxls.<dest_type>export')
 *
 * Регистрация: bitrix/modules/awz.allxls/.settings.php (ключ 'services').
 * Подмена/расширение: событие onAwzAllxlsResolveExportService (Pexport::resolveService).
 */
interface ExportBase
{
    /**
     * Группы полей источника для селектов колонок (шаг 3 экспорта).
     * Формат: [['NAME' => 'заголовок группы', 'ITEMS' => [['ID' => код поля, 'NAME' => название, ...]], ...]]
     *
     * @param string $entityId ID сущности-источника (например, ID инфоблока)
     * @return array
     */
    public function getFields(string $entityId): array;

    /**
     * Количество записей источника по фильтру (для статистики/прогресса).
     *
     * @param string $entityId ID сущности-источника
     * @param array $filter фильтр выборки (в формате сервиса)
     * @return int
     */
    public function countElements(string $entityId, array $filter = []): int;

    /**
     * Порция записей источника (по возрастанию ID, после $lastId).
     *
     * @param string $entityId ID сущности-источника
     * @param string|int $lastId последний обработанный ID (0 — с начала)
     * @param int $limit размер порции
     * @param array $select список выбираемых полей
     * @param array $filter фильтр выборки (в формате сервиса, например JSON-фильтр профиля)
     * @return array список записей (сырые данные источника)
     */
    public function getElements(string $entityId, $lastId, int $limit, array $select, array $filter = []): array;

    /**
     * ID записи (для курсора LAST_ID между хитами).
     *
     * @param array $record запись источника
     * @return string|int
     */
    public function getRecordId(array $record);

    /**
     * Сырое значение поля записи для выгрузки (до настроек TRIM/CASE и правил).
     * Множественные значения склеиваются разделителем.
     *
     * @param array $record запись источника
     * @param string $field код поля
     * @param string $delimiter разделитель множественных значений
     * @return string
     */
    public function extractFieldValue(array $record, string $field, string $delimiter = ', '): string;
}
