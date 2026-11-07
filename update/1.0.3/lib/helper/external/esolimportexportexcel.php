<?php
namespace Awz\Allxls\Helper\External;

use Bitrix\Main\Loader;

/**
 * Провайдер профилей модуля esol.importexportexcel (форум kda.importexcel).
 *
 * Модуль хранит профили импорта в \Bitrix\KdaImportexcel\ProfileTable
 * (b_kdaimportexcel_profile) и экспорта в \Bitrix\KdaExportexcel\ProfileTable
 * (b_kdaexportexcel_profile). Классы CKDAImportProfile/CKDAExportProfile
 * работают с публичным ID, ORM-таблицы — с ID БД.
 *
 * Публичный ID профиля везде на 1 меньше ID в таблице БД:
 * DB_ID = PUBLIC_ID + 1. Поскольку ID импортных и экспортных профилей
 * нумеруются независимо (с 1 в каждой таблице), публичный ID составной:
 * 'i<N>' — импорт, 'e<N>' — экспорт.
 */
class EsolImportexportexcel
{
    /** @var string код модуля-провайдера */
    const MODULE = 'esol.importexportexcel';

    /**
     * Модуль-провайдер установлен?
     * @return bool
     */
    public static function available(): bool
    {
        try {
            return Loader::includeModule(self::MODULE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Список профилей провайдера (все, включая неактивные — фильтрует вызывающий код).
     * Формат элемента — контракте события onAwzAllxlsGetExternalProfiles:
     * ['MODULE', 'ID' (публичный, строкой), 'NAME', 'TYPE' => 'import|export', 'DESC', 'ACTIVE', 'SORT'].
     *
     * @return array
     */
    public static function profiles(): array
    {
        $items = [];
        if (!self::available()) return $items;
        $items = array_merge($items, self::profilesByType('import'));
        $items = array_merge($items, self::profilesByType('export'));
        return $items;
    }

    /**
     * Список профилей одного типа (import/export).
     *
     * @param string $type
     * @return array
     */
    protected static function profilesByType(string $type): array
    {
        $items = [];
        try {
            $tableClass = self::tableClass($type);
            if (!$tableClass || !class_exists($tableClass)) return $items;
            $rs = $tableClass::getList([
                'select' => ['ID', 'NAME', 'ACTIVE', 'SORT', 'PARAMS'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                $description = '';
                try {
                    $decodeClass = ('import' === $type) ? '\CKDAImportProfileDB' : '\CKDAExportProfileDB';
                    if (class_exists($decodeClass)) {
                        $params = $decodeClass::DecodeProfileParams($row['PARAMS']);
                        $description = (string)($params['SETTINGS_DEFAULT']['PROFILE_DESCRIPTION'] ?? '');
                    }
                } catch (\Throwable $e) {
                }
                $items[] = [
                    'MODULE' => self::MODULE,
                    'ID' => self::publicId($type, (int)$row['ID']),
                    'NAME' => (string)($row['NAME'] ?? ''),
                    'TYPE' => $type,
                    'DESC' => $description,
                    'ACTIVE' => (string)($row['ACTIVE'] ?? 'Y'),
                    'SORT' => (int)($row['SORT'] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
        }
        return $items;
    }

    /**
     * Детальная информация по профилю провайдера (публичный ID).
     *
     * Возвращает мета-поля (GetFieldsByID) и полное дерево параметров
     * (GetByID: SETTINGS, SETTINGS_DEFAULT, EXTRASETTINGS, FIELDS_LIST...).
     *
     * @param string $publicId публичный ID профиля ('i<N>' — импорт, 'e<N>' — экспорт)
     * @return array|null null — профиль не найден / недоступен
     */
    public static function info(string $publicId): ?array
    {
        if (!self::available()) return null;
        $publicId = trim((string)$publicId);
        $type = self::typeOf($publicId);
        if ($type === '') return null;
        $pid = (int)substr($publicId, 1);
        if ($pid < 0) return null;

        $isImport = ('import' === $type);
        $profileClass = $isImport ? '\CKDAImportProfile' : '\CKDAExportProfile';
        try {
            if (!class_exists($profileClass)) return null;
            $oProfile = new $profileClass();
            if (!$oProfile->ProfileExists($pid)) return null;

            $fields = (array)$oProfile->GetFieldsByID($pid);
            $params = (array)$oProfile->GetByID($pid);
            $default = is_array($params['SETTINGS_DEFAULT'] ?? null) ? $params['SETTINGS_DEFAULT'] : [];

            $info = [
                'MODULE' => self::MODULE,
                'ID' => $publicId,
                'TYPE' => $type,
                'NAME' => (string)($fields['NAME'] ?? ''),
                'ACTIVE' => (string)($fields['ACTIVE'] ?? ''),
                'SORT' => (int)($fields['SORT'] ?? 0),
                'DESC' => (string)($default['PROFILE_DESCRIPTION'] ?? ''),
                'GROUP_ID' => (int)($fields['GROUP_ID'] ?? 0),
                'DATE_START' => (string)(self::dateToString($fields['DATE_START'] ?? '')),
                'DATE_FINISH' => (string)(self::dateToString($fields['DATE_FINISH'] ?? '')),
                // полное дерево параметров (может быть большим)
                'SETTINGS' => is_array($params['SETTINGS'] ?? null) ? $params['SETTINGS'] : [],
                'SETTINGS_DEFAULT' => $default,
                'EXTRASETTINGS' => is_array($params['EXTRASETTINGS'] ?? null) ? $params['EXTRASETTINGS'] : [],
                'FIELDS_LIST' => is_array($params['FIELDS_LIST'] ?? null) ? $params['FIELDS_LIST'] : [],
            ];
            if ($isImport) {
                $info['OWNER_ID'] = (int)($fields['OWNER_ID'] ?? 0);
                $info['FILE_HASH'] = (string)($fields['FILE_HASH'] ?? '');
                $info['DATA_FILE_ID'] = (string)($fields['DATA_FILE_ID'] ?? '');
                $info['SUPPLIER_EMAIL'] = (string)($fields['SUPPLIER_EMAIL'] ?? '');
            }
            return $info;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ORM-класс таблицы профилей по типу.
     *
     * @param string $type import|export
     * @return string пустая строка — тип неизвестен
     */
    protected static function tableClass(string $type): string
    {
        if ('import' === $type) return '\Bitrix\KdaImportexcel\ProfileTable';
        if ('export' === $type) return '\Bitrix\KdaExportexcel\ProfileTable';
        return '';
    }

    /**
     * Составной публичный ID: 'i<N>' (импорт) / 'e<N>' (экспорт).
     *
     * @param string $type
     * @param int $dbId ID в таблице БД
     * @return string
     */
    protected static function publicId(string $type, int $dbId): string
    {
        return ('import' === $type ? 'i' : 'e') . ($dbId - 1);
    }

    /**
     * Тип профиля из составного публичного ID.
     *
     * @param string $publicId
     * @return string import|export|'' (неизвестный формат)
     */
    protected static function typeOf(string $publicId): string
    {
        if (preg_match('/^i\d+$/', $publicId)) return 'import';
        if (preg_match('/^e\d+$/', $publicId)) return 'export';
        return '';
    }

    /**
     * DateTime/строка в строку (без исключений на объектах-не-датax).
     *
     * @param mixed $value
     * @return string
     */
    protected static function dateToString($value): string
    {
        if (is_object($value) && method_exists($value, 'toString')) {
            return (string)$value->toString();
        }
        return (string)$value;
    }
}
