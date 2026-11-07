<?php
namespace Awz\Allxls\Tables;

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * ORM сущность кеша хешей строк профилей
 * Хранит соответствие: профиль | хеш настроек | хеш строки
 * При изменении хеша настроек профиля все хеши профиля сбрасываются
 */
class ProfilesHashesTable extends DataManager
{
    /** @var array кеш SETTINGS_HASH профилей в рамках процесса (импорт пишет/читает хеши часто) */
    private static array $settingsHashCache = [];
    /** @var bool|null готовность UPSERT-записи хешей (одна проверка уникального индекса за процесс) */
    private static ?bool $upsertReady = null;

    /**
     * Готовность быстрой записи хешей одним UPSERT-запросом (MySQL):
     * нужен UNIQUE-индекс (PROFILE_ID, ROW_HASH). При первом обращении
     * дедуплицирует дубли и пытается создать индекс; при неудаче
     * используется медленный путь (SELECT + INSERT/UPDATE).
     */
    private static function ensureUpsert(): bool
    {
        if (self::$upsertReady !== null) {
            return self::$upsertReady;
        }
        self::$upsertReady = false;
        try {
            $conn = Application::getInstance()->getConnection();
            if (!$conn instanceof MysqlCommonConnection) {
                return false;
            }
            $exists = (int)$conn->queryScalar(
                "SELECT COUNT(*) FROM information_schema.STATISTICS"
                . " WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = '" . self::getTableName() . "'"
                . " AND INDEX_NAME = 'UK_AWZ_ALLXLS_HASHES_ROW'"
            );
            self::$upsertReady = $exists > 0;
        } catch (\Throwable $e) {
            self::$upsertReady = false;
        }
        return self::$upsertReady;
    }

    public static function getTableName(): string
    {
        return 'b_awz_allxls_profiles_hashes';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true),

            (new IntegerField('PROFILE_ID'))
                ->configureRequired(true)
                ->configureTitle('ID профиля'),

            (new StringField('SETTINGS_HASH'))
                ->configureRequired(true)
                ->configureSize(32)
                ->configureTitle('Хеш настроек профиля'),

            (new StringField('ROW_HASH'))
                ->configureRequired(true)
                ->configureSize(32)
                ->configureTitle('Хеш строки'),

            (new StringField('ELEMENT_ID'))
                ->configureRequired(true)
                ->configureDefaultValue('')
                ->configureSize(50)
                ->configureTitle('Идентификатор элемента сущности-приёмника'),

            (new StringField('ENTITY_ID'))
                ->configureRequired(true)
                ->configureDefaultValue('')
                ->configureSize(50)
                ->configureTitle('Идентификатор сущности (ID инфоблока)'),

            (new Reference('PROFILE', ProfilesTable::class, Join::on('this.PROFILE_ID', 'ref.ID')))
                ->configureJoinType('left'),
        ];
    }

    /**
     * Очистить все хеши профиля
     */
    public static function clearProfileHashes(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }
        unset(self::$settingsHashCache[$profileId]);
        $existing = self::getList([
            'select' => ['ID'],
            'filter' => ['=PROFILE_ID' => $profileId],
        ]);
        while ($item = $existing->fetch()) {
            self::delete($item['ID']);
        }
    }

    /**
     * Получить хеш настроек профиля (вычисляется из ProfilesTable)
     * С кешем на процесс: импорт обращается к нему несколько раз на каждую строку.
     */
    public static function getProfileSettingsHash(int $profileId): string
    {
        if (array_key_exists($profileId, self::$settingsHashCache)) {
            return self::$settingsHashCache[$profileId];
        }
        $profile = ProfilesTable::getRow([
            'select' => ['SETTINGS_HASH'],
            'filter' => ['=ID' => $profileId],
        ]);
        $hash = $profile ? (string)$profile['SETTINGS_HASH'] : '';
        self::$settingsHashCache[$profileId] = $hash;
        return $hash;
    }

    /**
     * Проверить актуальность хеша строки для профиля
     * Если хеш настроек профиля изменился - хеши профиля сбрасываются
     */
    public static function checkRowHash(int $profileId, string $rowHash): bool
    {
        $settingsHash = self::getProfileSettingsHash($profileId);
        if ($settingsHash === '') {
            return false;
        }

        $row = self::getRow([
            'filter' => [
                '=PROFILE_ID' => $profileId,
                '=SETTINGS_HASH' => $settingsHash,
                '=ROW_HASH' => $rowHash,
            ],
        ]);

        return !empty($row);
    }

    /**
     * ELEMENT_ID, связанный с хешем строки профиля (актуального SETTINGS_HASH).
     * Используется опцией SYSTEM_REPLACE_DATE: ID неизмененного элемента
     * берется из хеша — без поиска элемента по фильтру.
     *
     * @param int $profileId ID профиля
     * @param string $rowHash хеш строки
     * @return string ID элемента ('' — не найден)
     */
    public static function getRowElementId(int $profileId, string $rowHash): string
    {
        if ($profileId <= 0 || $rowHash === '') {
            return '';
        }
        $settingsHash = self::getProfileSettingsHash($profileId);
        if ($settingsHash === '') {
            return '';
        }
        $row = self::getRow([
            'select' => ['ELEMENT_ID'],
            'filter' => [
                '=PROFILE_ID' => $profileId,
                '=SETTINGS_HASH' => $settingsHash,
                '=ROW_HASH' => $rowHash,
            ],
        ]);
        return $row ? (string)$row['ELEMENT_ID'] : '';
    }

    /**
     * Загрузить все хеши строк профиля (актуального SETTINGS_HASH) одним запросом.
     * Используется опцией импорта HASH_MEM_CACHE=Y: набор в памяти вместо
     * запроса на каждую строку.
     *
     * @param int $profileId ID профиля
     * @return array карта ROW_HASH => ELEMENT_ID
     */
    public static function loadRowHashes(int $profileId): array
    {
        if ($profileId <= 0) {
            return [];
        }
        $settingsHash = self::getProfileSettingsHash($profileId);
        if ($settingsHash === '') {
            return [];
        }
        $result = [];
        $res = self::getList([
            'select' => ['ROW_HASH', 'ELEMENT_ID'],
            'filter' => [
                '=PROFILE_ID' => $profileId,
                '=SETTINGS_HASH' => $settingsHash,
            ],
        ]);
        while ($row = $res->fetch()) {
            $result[(string)$row['ROW_HASH']] = (string)($row['ELEMENT_ID'] ?? '');
        }
        return $result;
    }

    /**
     * Сохранить хеш строки для профиля
     *
     * @param int $profileId ID профиля
     * @param string $rowHash хеш строки
     * @param string $elementId идентификатор элемента сущности-приёмника ('' - неизвестен)
     * @param string $entityId идентификатор сущности (ID инфоблока)
     */
    public static function saveRowHash(int $profileId, string $rowHash, string $elementId = '', string $entityId = ''): void
    {
        $settingsHash = self::getProfileSettingsHash($profileId);
        if ($settingsHash === '' || $rowHash === '') {
            return;
        }

        // быстрый путь: одна UPSERT-запись вместо SELECT + INSERT/UPDATE.
        // уже финализированный ELEMENT_ID ('' — резерв) не перезаписывается.
        if (self::ensureUpsert()) {
            try {
                $conn = Application::getInstance()->getConnection();
                $helper = $conn->getSqlHelper();
                $table = $helper->quote(self::getTableName());
                $conn->queryExecute(
                    "INSERT INTO {$table} (PROFILE_ID, SETTINGS_HASH, ROW_HASH, ELEMENT_ID, ENTITY_ID)"
                    . " VALUES ({$profileId}, '{$helper->forSql($settingsHash)}', '{$helper->forSql($rowHash)}',"
                    . " '{$helper->forSql($elementId)}', '{$helper->forSql($entityId)}')"
                    . " ON DUPLICATE KEY UPDATE"
                    . " SETTINGS_HASH = VALUES(SETTINGS_HASH),"
                    . " ELEMENT_ID = IF(VALUES(ELEMENT_ID) = '', ELEMENT_ID, VALUES(ELEMENT_ID)),"
                    . " ENTITY_ID = IF(VALUES(ELEMENT_ID) = '', ENTITY_ID, VALUES(ENTITY_ID))"
                );
                return;
            } catch (\Throwable $e) {
                // ошибка UPSERT — переход на медленный путь ниже
                self::$upsertReady = false;
            }
        }

        $existing = self::getRow([
            'select' => ['ID', 'SETTINGS_HASH'],
            'filter' => [
                '=PROFILE_ID' => $profileId,
                '=ROW_HASH' => $rowHash,
            ],
        ]);

        if ($existing) {
            $update = [];
            if ($existing['SETTINGS_HASH'] !== $settingsHash) {
                $update['SETTINGS_HASH'] = $settingsHash;
            }
            if ($elementId !== '' || $entityId !== '') {
                $update['ELEMENT_ID'] = $elementId;
                $update['ENTITY_ID'] = $entityId;
            }
            if ($update) {
                self::update($existing['ID'], $update);
            }
            return;
        }

        self::add([
            'PROFILE_ID' => $profileId,
            'SETTINGS_HASH' => $settingsHash,
            'ROW_HASH' => $rowHash,
            'ELEMENT_ID' => $elementId,
            'ENTITY_ID' => $entityId,
        ]);
    }

    /**
     * Удалить хеш строки профиля (снятие резерва при ошибке обработки).
     */
    public static function deleteRowHash(int $profileId, string $rowHash): void
    {
        if ($rowHash === '') {
            return;
        }
        $existing = self::getList([
            'select' => ['ID'],
            'filter' => [
                '=PROFILE_ID' => $profileId,
                '=ROW_HASH' => $rowHash,
            ],
        ]);
        while ($item = $existing->fetch()) {
            self::delete($item['ID']);
        }
    }

    /**
     * Удалить все хеши строк, связанные с элементом сущности.
     * Вызывается обработчиками событий изменения/удаления элемента,
     * чтобы повторный импорт заново обработал данные.
     *
     * @param string $elementId идентификатор элемента
     * @param string $entityId идентификатор сущности (ID инфоблока)
     */
    public static function clearElementHashes(string $elementId, string $entityId = ''): void
    {
        if ($elementId === '') {
            return;
        }
        $filter = ['=ELEMENT_ID' => $elementId];
        if ($entityId !== '') {
            $filter['=ENTITY_ID'] = $entityId;
        }
        $existing = self::getList([
            'select' => ['ID'],
            'filter' => $filter,
        ]);
        while ($item = $existing->fetch()) {
            self::delete($item['ID']);
        }
    }
}
