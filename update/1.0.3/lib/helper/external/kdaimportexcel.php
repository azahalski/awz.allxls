<?php
namespace Awz\Allxls\Helper\External;

use Bitrix\Main\Loader;

/**
 * Провайдер профилей модуля kda.importexcel (первый сторонний модуль).
 *
 * Публичный ID профиля везде на 1 меньше ID в таблице БД:
 * DB_ID = PUBLIC_ID + 1. Классы \CKDAImportProfile/\CKDAImportProfileDB
 * работают с публичным ID, \Bitrix\KdaImportexcel\ProfileTable — с ID БД.
 */
class KdaImportexcel
{
    /** @var string код модуля-провайдера */
    const MODULE = 'kda.importexcel';

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
     * ['MODULE', 'ID' (публичный, строкой), 'NAME', 'TYPE' => 'import', 'DESC', 'ACTIVE', 'SORT'].
     *
     * @return array
     */
    public static function profiles(): array
    {
        $items = [];
        if (!self::available()) return $items;
        try {
            $rs = \Bitrix\KdaImportexcel\ProfileTable::getList([
                'select' => ['ID', 'NAME', 'ACTIVE', 'SORT', 'PARAMS'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                $description = '';
                try {
                    $params = \CKDAImportProfileDB::DecodeProfileParams($row['PARAMS']);
                    $description = (string)($params['SETTINGS_DEFAULT']['PROFILE_DESCRIPTION'] ?? '');
                } catch (\Throwable $e) {
                }
                $items[] = [
                    'MODULE' => self::MODULE,
                    'ID' => (string)((int)$row['ID'] - 1), // сдвиг: публичный ID = DB ID - 1
                    'NAME' => (string)($row['NAME'] ?? ''),
                    'TYPE' => 'import',
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
     * импорта (GetByID: SETTINGS, SETTINGS_DEFAULT, EXTRASETTINGS, FIELDS_LIST...).
     *
     * @param string $publicId публичный ID профиля
     * @return array|null null — профиль не найден / недоступен
     */
    public static function info(string $publicId): ?array
    {
        if (!self::available()) return null;
        $pid = (int)$publicId;
        if ($pid < 0) return null;
        try {
            if (!class_exists('\CKDAImportProfile')) return null;
            $oProfile = new \CKDAImportProfile();
            if (!$oProfile->ProfileExists($pid)) return null;

            $fields = (array)$oProfile->GetFieldsByID($pid);
            $params = (array)$oProfile->GetByID($pid);
            $default = is_array($params['SETTINGS_DEFAULT'] ?? null) ? $params['SETTINGS_DEFAULT'] : [];

            return [
                'MODULE' => self::MODULE,
                'ID' => $pid,
                'NAME' => (string)($fields['NAME'] ?? ''),
                'ACTIVE' => (string)($fields['ACTIVE'] ?? ''),
                'SORT' => (int)($fields['SORT'] ?? 0),
                'DESC' => (string)($default['PROFILE_DESCRIPTION'] ?? ''),
                'OWNER_ID' => (int)($fields['OWNER_ID'] ?? 0),
                'GROUP_ID' => (int)($fields['GROUP_ID'] ?? 0),
                'DATE_START' => (string)($fields['DATE_START'] ?? ''),
                'DATE_FINISH' => (string)($fields['DATE_FINISH'] ?? ''),
                'FILE_HASH' => (string)($fields['FILE_HASH'] ?? ''),
                'DATA_FILE_ID' => (string)($fields['DATA_FILE_ID'] ?? ''),
                'SUPPLIER_EMAIL' => (string)($fields['SUPPLIER_EMAIL'] ?? ''),
                // полное дерево параметров импорта (может быть большим)
                'SETTINGS' => is_array($params['SETTINGS'] ?? null) ? $params['SETTINGS'] : [],
                'SETTINGS_DEFAULT' => $default,
                'EXTRASETTINGS' => is_array($params['EXTRASETTINGS'] ?? null) ? $params['EXTRASETTINGS'] : [],
                'FIELDS_LIST' => is_array($params['FIELDS_LIST'] ?? null) ? $params['FIELDS_LIST'] : [],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
