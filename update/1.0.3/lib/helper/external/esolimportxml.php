<?php
namespace Awz\Allxls\Helper\External;

use Bitrix\Main\Loader;

/**
 * Провайдер профилей модуля esol.importxml (Импорт из XML/CSV).
 *
 * Модуль хранит профили в \Bitrix\EsolImportxml\ProfileTable
 * (b_esolimportxml_profile, инфоблоки) и \Bitrix\EsolImportxml\ProfileHlTable
 * (highload-блоки). Класс \Bitrix\EsolImportxml\Profile работает
 * с публичным ID, ORM-таблицы — с ID БД.
 *
 * Публичный ID профиля на 1 меньше ID в таблице БД:
 * DB_ID = PUBLIC_ID + 1. Поскольку профили инфоблоков и highload-блоков
 * нумеруются независимо, публичный ID составной:
 * 'i<N>' — инфоблоки, 'h<N>' — highload-блоки.
 */
class EsolImportxml
{
    /** @var string код модуля-провайдера */
    const MODULE = 'esol.importxml';

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
        $items = array_merge($items, self::profilesByTable('i', '\Bitrix\EsolImportxml\ProfileTable'));
        $items = array_merge($items, self::profilesByTable('h', '\Bitrix\EsolImportxml\ProfileHlTable'));
        return $items;
    }

    /**
     * Список профилей одной ORM-таблицы.
     *
     * @param string $prefix префикс публичного ID ('i' — инфоблоки, 'h' — highload)
     * @param string $tableClass ORM-класс таблицы профилей
     * @return array
     */
    protected static function profilesByTable(string $prefix, string $tableClass): array
    {
        $items = [];
        if (!class_exists($tableClass)) return $items;
        try {
            $rs = $tableClass::getList([
                'select' => ['ID', 'NAME', 'ACTIVE', 'SORT', 'PARAMS'],
                'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            ]);
            while ($row = $rs->fetch()) {
                $description = '';
                try {
                    $params = \Bitrix\EsolImportxml\Profile::DecodeProfileParams((string)$row['PARAMS']);
                    $description = (string)($params['SETTINGS_DEFAULT']['PROFILE_DESCRIPTION'] ?? '');
                } catch (\Throwable $e) {
                }
                $items[] = [
                    'MODULE' => self::MODULE,
                    'ID' => self::publicId($prefix, (int)$row['ID']),
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
     * (GetByID: SETTINGS, SETTINGS_DEFAULT, EXTRASETTINGS, FIELDS_LIST...).
     *
     * @param string $publicId публичный ID профиля ('i<N>' — инфоблоки, 'h<N>' — highload)
     * @return array|null null — профиль не найден / недоступен
     */
    public static function info(string $publicId): ?array
    {
        if (!self::available()) return null;
        $publicId = trim((string)$publicId);
        $prefix = self::prefixOf($publicId);
        if ($prefix === '') return null;
        $pid = (int)substr($publicId, 1);
        if ($pid < 0) return null;

        $tableClass = ('h' === $prefix)
            ? '\Bitrix\EsolImportxml\ProfileHlTable'
            : '\Bitrix\EsolImportxml\ProfileTable';
        if (!class_exists($tableClass)) return null;

        $dbId = $pid + 1;
        try {
            $row = $tableClass::getList(['filter' => ['=ID' => $dbId]])->fetch();
            if (empty($row)) return null;

            $params = is_string($row['PARAMS'] ?? null) && strlen($row['PARAMS']) > 0
                ? \Bitrix\EsolImportxml\Profile::DecodeProfileParams($row['PARAMS'])
                : [];
            $default = is_array($params['SETTINGS_DEFAULT'] ?? null) ? $params['SETTINGS_DEFAULT'] : [];

            $info = [
                'MODULE' => self::MODULE,
                'ID' => $publicId,
                'TYPE' => 'import',
                'NAME' => (string)($row['NAME'] ?? ''),
                'ACTIVE' => (string)($row['ACTIVE'] ?? 'Y'),
                'SORT' => (int)($row['SORT'] ?? 0),
                'DESC' => (string)($default['PROFILE_DESCRIPTION'] ?? ''),
                'GROUP_ID' => (int)($row['GROUP_ID'] ?? 0),
                'DATE_START' => (string)self::dateToString($row['DATE_START'] ?? ''),
                'DATE_FINISH' => (string)self::dateToString($row['DATE_FINISH'] ?? ''),
                'FILE_HASH' => (string)($row['FILE_HASH'] ?? ''),
                // полное дерево параметров импорта (может быть большим)
                'SETTINGS' => is_array($params['SETTINGS'] ?? null) ? $params['SETTINGS'] : [],
                'SETTINGS_DEFAULT' => $default,
                'EXTRASETTINGS' => is_array($params['EXTRASETTINGS'] ?? null) ? $params['EXTRASETTINGS'] : [],
                'FIELDS_LIST' => is_array($params['FIELDS_LIST'] ?? null) ? $params['FIELDS_LIST'] : [],
            ];
            if ('i' === $prefix) {
                $info['DATA_FILE_ID'] = (string)($default['DATA_FILE'] ?? '');
            }
            return $info;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Составной публичный ID: '<префикс><N>' (DB_ID = PUBLIC_ID + 1).
     *
     * @param string $prefix 'i' — инфоблоки, 'h' — highload
     * @param int $dbId ID в таблице БД
     * @return string
     */
    protected static function publicId(string $prefix, int $dbId): string
    {
        return $prefix . ($dbId - 1);
    }

    /**
     * Префикс типа профиля из составного публичного ID.
     *
     * @param string $publicId
     * @return string 'i'|'h'|'' (неизвестный формат)
     */
    protected static function prefixOf(string $publicId): string
    {
        if (preg_match('/^i\d+$/', $publicId)) return 'i';
        if (preg_match('/^h\d+$/', $publicId)) return 'h';
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
