<?php
namespace Awz\Allxls\Cron;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Api\Pimport;
use Awz\Allxls\Api\Pexport;
use Awz\Allxls\Logger;

Loc::loadMessages(__FILE__);

/**
 * Запуск импортов/экспортов по расписанию из crontab.
 *
 * Вызывается напрямую через CLI-скрипт bitrix/modules/awz.allxls/cron/run.php:
 *   php bitrix/modules/awz.allxls/cron/run.php all    — все профили по расписанию
 *   php bitrix/modules/awz.allxls/cron/run.php 3,7    — конкретные профили
 *
 * Блокировка повторного запуска — в записи профиля в БД
 * (CRON_RUNNING/CRON_START_TIME): одновременно один процесс crontab
 * не может запустить один и тот же профиль.
 */
class Runner
{
    /** @var int устаревшая блокировка снимается автоматически спустя это время, сек */
    public const LOCK_TIMEOUT = 43200; // 12 часов

    /** @var int лимит одного «хита» обработки при cron-запуске, сек (состояние resilient) */
    public const HIT_TIME_LIMIT = 300;

    /** @var int общий лимит работы одного профиля в рамках процесса, сек */
    public const RUN_TIME_LIMIT = 21600; // 6 часов

    /** @var int максимум итераций хитов на один профиль (страховка от зацикливания) */
    public const MAX_ITERATIONS = 5000;

    /** @var int секунд без обновления статистики — cron-процесс считается упавшим */
    public const CRON_DEAD_AFTER = 180;

    /** @var int максимум подряд идущих падений cron-процесса на один профиль */
    public const MAX_CRON_FALLS = 10;

    /** @var int дедлайн всего процесса (time(), 0 — без ограничения) */
    protected int $processDeadline = 0;

    /** @var array результат последнего запуска (для CLI-вывода) */
    protected array $log = [];

    /**
     * Ограничение общего времени работы процесса, сек (0 — без ограничения).
     * Не начатые профили пропускаются, работа текущего профиля ограничивается остатком времени.
     * @param int $seconds
     */
    public function setTimeLimit(int $seconds): void
    {
        $this->processDeadline = ($seconds > 0) ? (time() + $seconds) : 0;
    }

    /**
     * Запуск набора профилей.
     * @param array $ids список ID профилей
     * @return array лог выполнения: [{id, name, status, message, stats}]
     */
    /**
     * Запуск профилей по списку ID.
     * @param array $ids ID профилей
     * @param bool $explicit явный запуск по списку ID — отдельное cron-задание
     *        (`run.php <id>`): требует включенной опции CRON_ACTIVE и способа
     *        запуска 'cron_own' в профиле (проверка опции в runProfile);
     *        накопленное расписание не учитывается (кроме паузы после завершения)
     * @return array лог выполнения
     */
    public function exec(array $ids, bool $explicit = false): array
    {
        self::ensureColumns();
        $this->log = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) return $this->log;

        foreach ($ids as $id) {
            if ($this->processDeadline > 0 && time() >= $this->processDeadline) {
                $this->log[] = [
                    'id' => $id,
                    'name' => '',
                    'status' => 'skip',
                    'message' => Loc::getMessage('AWZ_ALLXLS_CRON_RUN_PROCESS_TIME_LIMIT'),
                    'stats' => [],
                ];
                continue;
            }
            $this->log[] = $this->runProfile($id, $explicit);
        }
        return $this->log;
    }

    /**
     * Запуск всех профилей, для которых наступило время по расписанию.
     * @param string $mode способ запуска профилей: 'cron' — только общее cron-задание
     *        (`run.php all`), 'agent' — только агент Bitrix, '' — без фильтра
     *        (обратная совместимость). Способ задается per-profile в
     *        SETTINGS['CRON_MODE'] (cronsave), значение по умолчанию для старых
     *        профилей — 'agent'. Профили в режиме 'cron_own' (отдельное cron-задание)
     *        общим заданием не обрабатываются — только явным запуском по ID
     * @return array лог выполнения
     */
    public function execAll(string $mode = ''): array
    {
        $ids = [];
        $rs = ProfilesTable::getList([
            'select' => ['ID', 'SETTINGS'],
            'filter' => ['=ACTIVE' => 'Y', '=CRON_ACTIVE' => 'Y'],
            'order' => ['ID' => 'ASC'],
        ]);
        while ($row = $rs->fetch()) {
            if ($mode !== '') {
                $settings = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
                $profileMode = (string)($settings['CRON_MODE'] ?? 'agent');
                if ($profileMode !== $mode) continue;
            }
            $ids[] = (int)$row['ID'];
        }
        return $this->exec($ids);
    }

    /**
     * Профиль сейчас обрабатывается cron-процессом (активная блокировка в БД).
     * @param int $id
     * @return bool
     */
    public static function isProfileLocked(int $id): bool
    {
        self::ensureColumns();
        if ($id <= 0) return false;
        $row = ProfilesTable::getRow([
            'select' => ['CRON_RUNNING', 'CRON_START_TIME'],
            'filter' => ['=ID' => $id],
        ]);
        if (!$row || $row['CRON_RUNNING'] !== 'Y') return false;
        // устаревшая блокировка (процесс умер без снятия) — не считается активной
        $start = $row['CRON_START_TIME'];
        if ($start instanceof DateTime) {
            if ((time() - $start->getTimestamp()) > self::LOCK_TIMEOUT) return false;
        }
        return true;
    }

    /**
     * Принудительное снятие блокировки профиля.
     * @param int $id
     * @return bool
     */
    public static function releaseLock(int $id): bool
    {
        if ($id <= 0) return false;
        $result = ProfilesTable::update($id, [
            'CRON_RUNNING' => 'N',
            'CRON_START_TIME' => null,
        ]);
        return $result->isSuccess();
    }

    /**
     * Время последнего обновления статистики cron-процессом (heartbeat):
     * PROCESS/PROCESS_EXPORT.CRON_ALIVE (unix ts, обновляется каждые 30 сек
     * во время работы cron) либо, для старых запусков, CRON_START_TIME.
     * @param int $id
     * @return int 0 — данных нет
     */
    public static function cronHeartbeat(int $id): int
    {
        if ($id <= 0) return 0;
        try {
            $row = ProfilesTable::getRow([
                'select' => ['SETTINGS', 'TYPE', 'CRON_START_TIME'],
                'filter' => ['=ID' => $id],
            ]);
            if (!$row) return 0;
            $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
            $procKey = (($row['TYPE'] ?? 'import') === 'export') ? 'PROCESS_EXPORT' : 'PROCESS';
            $alive = (int)($st[$procKey]['CRON_ALIVE'] ?? 0);
            if ($alive <= 0 && $row['CRON_START_TIME'] instanceof DateTime) {
                $alive = $row['CRON_START_TIME']->getTimestamp();
            }
            return $alive;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Cron-процесс сейчас активен: блокировка стоит И статистика обновлялась
     * не позднее CRON_DEAD_AFTER секунд назад.
     * @param int $id
     * @return bool
     */
    public static function isCronAlive(int $id): bool
    {
        if (!self::isProfileLocked($id)) return false;
        $alive = self::cronHeartbeat($id);
        return ($alive > 0 && (time() - $alive) <= self::CRON_DEAD_AFTER);
    }

    /**
     * Сброс счетчика падений cron-процесса (успешный прогон или ручное вмешательство).
     * @param int $id
     * @return bool
     */
    public static function resetCronFalls(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            return ProfilesTable::update($id, ['CRON_FALLS' => 0])->isSuccess();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Регистрация падения cron-процесса (счетчик в колонке CRON_FALLS).
     * @param int $id
     * @return int новое значение счетчика
     */
    public static function registerCronFall(int $id): int
    {
        $current = 0;
        try {
            $row = ProfilesTable::getRow([
                'select' => ['CRON_FALLS'],
                'filter' => ['=ID' => $id],
            ]);
            $current = (int)($row['CRON_FALLS'] ?? 0);
        } catch (\Throwable $e) {
        }
        $next = $current + 1;
        try {
            ProfilesTable::update($id, ['CRON_FALLS' => $next]);
        } catch (\Throwable $e) {
        }
        return $next;
    }

    /**
     * Ленивая миграция: колонки cron-запуска для существующих установок.
     */
    public static function ensureColumns(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $conn = Application::getConnection();
            if (!$conn->isTableExists(ProfilesTable::getTableName())) return;
            $columns = $conn->getTableFields(ProfilesTable::getTableName());
            $pg = ($conn->getType() === 'pgsql');
            $toAdd = [
                'CRON_ACTIVE' => "char(1) NOT NULL DEFAULT 'N'",
                'CRON_SCHEDULE' => 'varchar(50) DEFAULT NULL',
                'CRON_HIT_TIME' => ($pg ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
                'CRON_PAUSE' => ($pg ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
                'CRON_FALLS' => ($pg ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
                'CRON_RUNNING' => "char(1) NOT NULL DEFAULT 'N'",
                'CRON_START_TIME' => ($pg ? 'timestamp' : 'datetime').' DEFAULT NULL',
                'CRON_LAST_ERR' => 'varchar(255) DEFAULT NULL',
            ];
            foreach ($toAdd as $name => $sqlType) {
                if (isset($columns[$name])) continue;
                $conn->queryExecute(
                    "ALTER TABLE ".$conn->getSqlHelper()->quote(ProfilesTable::getTableName()).
                    " ADD COLUMN ".$conn->getSqlHelper()->quote($name)." ".$sqlType
                );
            }
        } catch (\Throwable $e) {
            // игнорируем: колонки могли быть добавлены штатной установкой
        }
    }

    /**
     * Запуск одного профиля по расписанию.
     * @param int $id
     * @return array {id, name, status: run|skip|done|error, message, stats}
     */
    public function runProfile(int $id, bool $explicit = false): array
    {
        $res = [
            'id' => $id,
            'name' => '',
            'status' => 'skip',
            'message' => '',
            'stats' => [],
        ];

        if ($id <= 0) {
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_PROFILE_NOT_FOUND');
            return $res;
        }

        $profile = ProfilesTable::getById($id)->fetch();
        if (!$profile) {
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_PROFILE_NOT_FOUND');
            return $res;
        }
        $res['name'] = (string)$profile['NAME'];

        // активная блокировка: профиль уже выполняется другим процессом.
        // Если статистика не обновлялась дольше CRON_DEAD_AFTER секунд —
        // cron-процесс считается упавшим: счетчик падений, продолжение с места обрыва
        if (self::isProfileLocked($id)) {
            if (self::isCronAlive($id)) {
                $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_LOCKED');
                return $res;
            }
            $falls = self::registerCronFall($id);
            if ($falls > self::MAX_CRON_FALLS) {
                self::releaseLock($id);
                $res['status'] = 'error';
                $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_FALLS_LIMIT');
                try {
                    ProfilesTable::update($id, ['CRON_LAST_ERR' => mb_substr((string)$res['message'], 0, 250)]);
                } catch (\Throwable $e) {
                }
                return $res;
            }
            self::releaseLock($id);
            Logger::info(
                'Cron-процесс не отвечает — блокировка снята, продолжение с места обрыва',
                ['profile_id' => $id, 'falls' => $falls]
            );
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_PROFILE_NOT_FOUND');
                return $res;
            }
        }

        // снятие устаревшей блокировки (процесс умер без снятия)
        $start = $profile['CRON_START_TIME'];
        if ($profile['CRON_RUNNING'] === 'Y' && $start instanceof DateTime
            && (time() - $start->getTimestamp()) > self::LOCK_TIMEOUT) {
            self::releaseLock($id);
            $profile = ProfilesTable::getById($id)->fetch();
        }

        // активный профиль с включенным расписанием — обязательное условие
        // для любого запуска по расписанию (по умолчанию cron выключен)
        if ($profile['ACTIVE'] !== 'Y' || $profile['CRON_ACTIVE'] !== 'Y') {
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_NOT_ACTIVE');
            return $res;
        }

        // окно времени запуска (SETTINGS: CRON_TIME_FROM/CRON_TIME_TO, HH:MM):
        // запуск разрешен только внутри интервала; интервал через полночь
        // поддерживается (например, 22:00–06:00)
        $runSettings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
        $timeFrom = (string)($runSettings['CRON_TIME_FROM'] ?? '');
        $timeTo = (string)($runSettings['CRON_TIME_TO'] ?? '');
        if ($timeFrom !== '' && $timeTo !== ''
            && preg_match('/^(\d{2}):(\d{2})$/', $timeFrom) && preg_match('/^(\d{2}):(\d{2})$/', $timeTo)) {
            $now = (int)date('Hi');
            $from = (int)str_replace(':', '', $timeFrom);
            $to = (int)str_replace(':', '', $timeTo);
            $inWindow = ($from <= $to) ? ($now >= $from && $now <= $to) : ($now >= $from || $now <= $to);
            if (!$inWindow) {
                $res['message'] = str_replace(
                    ['#FROM#', '#TO#'],
                    [$timeFrom, $timeTo],
                    Loc::getMessage('AWZ_ALLXLS_CRON_RUN_OUTSIDE_WINDOW')
                );
                return $res;
            }
        }

        // отдельное cron-задание (`run.php <id>`): скрипт проверяет опцию
        // способа запуска в параметрах профиля — обрабатываются только профили
        // в режиме 'cron_own'; общее задание (`run.php all`) обрабатывает
        // только профили в режиме 'cron' (фильтр в execAll)
        if ($explicit) {
            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            if ((string)($settings['CRON_MODE'] ?? 'agent') !== 'cron_own') {
                $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_WRONG_MODE');
                return $res;
            }
        }

        // расписание и пауза после завершения (CRON_PAUSE): если следующий запуск
        // еще не наступил — профиль пропускается; при явном запуске по списку ID
        // проверка действует только если задана пауза (иначе — вне очереди)
        $nextRun = $profile['DATE_NEXT_RUN'];
        $pause = max(0, (int)($profile['CRON_PAUSE'] ?? 0));
        if ($nextRun instanceof DateTime && $nextRun->getTimestamp() > time()) {
            if (!$explicit || $pause > 0) {
                $res['message'] = (!$explicit)
                    ? Loc::getMessage('AWZ_ALLXLS_CRON_RUN_NOT_DUE')
                    : str_replace('#DATE#', $nextRun->format('d.m.Y H:i:s'), Loc::getMessage('AWZ_ALLXLS_CRON_RUN_PAUSE_WAIT'));
                return $res;
            }
        }

        // атомарный захват блокировки: только один процесс может установить CRON_RUNNING
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        try {
            $connection->queryExecute(
                "UPDATE ".$helper->quote(ProfilesTable::getTableName()).
                " SET CRON_RUNNING='Y', CRON_START_TIME=".$helper->getCurrentDateTimeFunction().
                " WHERE ID=".(int)$id." AND CRON_RUNNING<>'Y'"
            );
        } catch (\Throwable $e) {
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_LOCKED');
            return $res;
        }
        // подтверждение захвата: время старта установлено этим процессом
        $check = ProfilesTable::getRow([
            'select' => ['CRON_RUNNING', 'CRON_START_TIME'],
            'filter' => ['=ID' => $id],
        ]);
        if (!$check || $check['CRON_RUNNING'] !== 'Y'
            || !($check['CRON_START_TIME'] instanceof DateTime)
            || (time() - $check['CRON_START_TIME']->getTimestamp()) > 10) {
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_LOCKED');
            return $res;
        }

        // дата последнего запуска — время старта
        ProfilesTable::update($id, ['DATE_LAST_RUN' => new DateTime()]);

        // пошаговый режим: CRON_HIT_TIME>0 — за один вызов обрабатываем не более
        // указанного числа секунд (состояние в PROCESS продолжает ход на следующем вызове)
        $stepTime = max(0, (int)($profile['CRON_HIT_TIME'] ?? 0));
        $maxHits = ($stepTime > 0) ? 1 : 0;

        $type = ($profile['TYPE'] ?? 'import') === 'export' ? 'export' : 'import';
        // остаток общего времени процесса для этого профиля (0 — без ограничения)
        $runTimeLimit = 0;
        if ($this->processDeadline > 0) {
            $runTimeLimit = max(0, $this->processDeadline - time());
        }
        // жесткий лимит PHP (max_execution_time) на хостинге: если set_time_limit
        // не работает, процесс убивается посреди хита — блокировка остается,
        // финальное сохранение не выполняется. Ограничиваем весь прогон с запасом,
        // чтобы хит корректно завершился и снял блокировку
        $iniMax = (int)ini_get('max_execution_time');
        if ($iniMax > 0) {
            $hardCap = max(60, $iniMax - 15);
            if ($runTimeLimit <= 0 || $runTimeLimit > $hardCap) {
                $runTimeLimit = $hardCap;
            }
        }
        try {
            if ($type === 'export') {
                $runResult = $this->runExport($id, $stepTime, $maxHits, $runTimeLimit);
            } else {
                $runResult = $this->runImport($id, $stepTime, $maxHits, $runTimeLimit);
            }
            $res['status'] = $runResult['status'];
            $res['message'] = $runResult['message'];
            $res['stats'] = $runResult['stats'];
        } catch (\Throwable $e) {
            $res['status'] = 'error';
            $res['message'] = $e->getMessage();
        }

        // снятие блокировки + метрики завершения
        $fields = [
            'CRON_RUNNING' => 'N',
            'CRON_START_TIME' => null,
        ];
        // RUN_COUNT и дата завершения — только по факту завершения обработки;
        // любой успешный прогон (в т.ч. пошаговый) сбрасывает счетчик падений
        // cron-процесса — иначе лимит падений блокирует профиль навсегда
        if ($res['status'] === 'done') {
            $fields['DATE_LAST_END'] = new DateTime();
            $fields['RUN_COUNT'] = (int)($profile['RUN_COUNT'] ?? 0) + 1;
        }
        if ($res['status'] === 'done' || $res['status'] === 'step') {
            $fields['CRON_FALLS'] = 0;
        }
        $fields['CRON_LAST_ERR'] = ($res['status'] === 'error') ? mb_substr((string)$res['message'], 0, 250) : '';
        // следующий запуск — по расписанию; в пошаговом режиме незавершенный
        // профайл продолжает обрабатываться на следующем вызове (DATE_NEXT_RUN в прошлом)
        $schedule = (string)($profile['CRON_SCHEDULE'] ?? '');
        if ($schedule !== '' && Schedule::validate($schedule) && $res['status'] === 'done') {
            $fields['DATE_NEXT_RUN'] = Schedule::getNextRun($schedule);
        }
        // пауза после завершения (с момента done): DATE_NEXT_RUN не ранее, чем
        // через CRON_PAUSE секунд; берется максимум из расписания и паузы
        $pause = max(0, (int)($profile['CRON_PAUSE'] ?? 0));
        if ($pause > 0 && $res['status'] === 'done') {
            $pauseUntil = new DateTime();
            $pauseUntil->add('+'.$pause.' seconds');
            if (empty($fields['DATE_NEXT_RUN']) || !($fields['DATE_NEXT_RUN'] instanceof DateTime)
                || $fields['DATE_NEXT_RUN']->getTimestamp() < $pauseUntil->getTimestamp()) {
                $fields['DATE_NEXT_RUN'] = $pauseUntil;
            }
        }
        try {
            ProfilesTable::update($id, $fields);
        } catch (\Throwable $e) {
            // снятие блокировки обязательно: fallback прямым SQL, иначе
            // «мертвая» блокировка останется и профиль зависнет
            try {
                $err = $helper->forSql(mb_substr((string)$res['message'], 0, 250));
                $connection->queryExecute(
                    "UPDATE ".$helper->quote(ProfilesTable::getTableName()).
                    " SET CRON_RUNNING='N', CRON_START_TIME=NULL, CRON_LAST_ERR='".$err."'".
                    " WHERE ID=".(int)$id
                );
            } catch (\Throwable $e2) {
            }
            $res['status'] = 'error';
            $res['message'] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_UNLOCK_FAIL').': '.$e->getMessage();
        }

        return $res;
    }

    /**
     * Полный прогон импорта профиля: хиты по HIT_TIME_LIMIT до завершения файла.
     * @param int $id
     * @param int $stepTime время одного шага, сек (0 — до завершения)
     * @param int $maxHits максимум хитов за один вызов (0 — без ограничения)
     * @param int $runTimeLimit лимит времени работы профиля в процессе, сек (0 — константа RUN_TIME_LIMIT)
     * @return array {status, message, stats}
     */
    protected function runImport(int $id, int $stepTime = 0, int $maxHits = 0, int $runTimeLimit = 0): array
    {
        Loader::includeModule('awz.allxls');
        $hit = ($stepTime > 0) ? $stepTime : self::HIT_TIME_LIMIT;
        // хит не длиннее лимита процесса — иначе процесс убивается до
        // корректного завершения хита и снятия блокировки
        if ($runTimeLimit > 0 && $hit > $runTimeLimit) {
            $hit = $runTimeLimit;
        }
        $controller = new Pimport();
        return $this->runController(
            $controller,
            'import',
            $id,
            // сброс состояния — только для нового прогона: незавершенный
            // импорт (есть курсор, DONE=N) продолжается с места остановки.
            // Раньше каждый cron-вызов делал reset='Y' и перечитывал файл
            // с начала, теряя накопленное время и прогресс
            function () use ($controller, $id, $hit) {
                $reset = 'Y';
                try {
                    $row = ProfilesTable::getById($id)->fetch();
                    $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
                    $proc = is_array($st['PROCESS'] ?? null) ? $st['PROCESS'] : [];
                    if ((string)($proc['DONE'] ?? 'Y') !== 'Y' && (int)($proc['PROCESSED'] ?? 0) > 0) {
                        $reset = 'N';
                    }
                } catch (\Throwable $e) {
                }
                return $controller->importAction($id, $hit, 0, $reset, 'Y', 'Y');
            },
            fn() => $controller->importAction($id, $hit, 0, 'N', 'Y', 'Y'),
            $maxHits,
            $runTimeLimit
        );
    }

    /**
     * Полный прогон экспорта профиля.
     * @param int $id
     * @param int $stepTime время одного шага, сек (0 — до завершения)
     * @param int $maxHits максимум хитов за один вызов (0 — без ограничения)
     * @param int $runTimeLimit лимит времени работы профиля в процессе, сек (0 — константа RUN_TIME_LIMIT)
     * @return array {status, message, stats}
     */
    protected function runExport(int $id, int $stepTime = 0, int $maxHits = 0, int $runTimeLimit = 0): array
    {
        Loader::includeModule('awz.allxls');
        $hit = ($stepTime > 0) ? $stepTime : self::HIT_TIME_LIMIT;
        // хит не длиннее лимита процесса (см. runImport)
        if ($runTimeLimit > 0 && $hit > $runTimeLimit) {
            $hit = $runTimeLimit;
        }
        $controller = new Pexport();
        return $this->runController(
            $controller,
            'export',
            $id,
            // сброс состояния — только для нового прогона (см. runImport)
            function () use ($controller, $id, $hit) {
                $reset = 'Y';
                try {
                    $row = ProfilesTable::getById($id)->fetch();
                    $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
                    $proc = is_array($st['PROCESS_EXPORT'] ?? null) ? $st['PROCESS_EXPORT'] : [];
                    if ((string)($proc['DONE'] ?? 'Y') !== 'Y' && (int)($proc['PROCESSED'] ?? 0) > 0) {
                        $reset = 'N';
                    }
                } catch (\Throwable $e) {
                }
                return $controller->exportAction($id, $hit, 0, $reset, 'Y', 'Y');
            },
            function () use ($controller, $id, $hit) {
                $r = $controller->exportAction($id, $hit, 0, 'N', 'Y', 'Y');
                if (is_array($r) && !empty($r['done'])) {
                    // финализация файла выгрузки (URL)
                    $controller->finishAction($id);
                }
                return $r;
            },
            $maxHits,
            $runTimeLimit
        );
    }

    /**
     * Общий цикл прогонов контроллера до завершения.
     *
     * @param \Bitrix\Main\Engine\Controller $controller контроллер импорта/экспорта
     * @param string $type import|export
     * @param int $id ID профиля
     * @param callable $first первый вызов (reset='Y')
     * @param callable $next последующие вызовы
     * @param int $maxHits максимум хитов за один вызов (0 — до завершения)
     * @param int $runTimeLimit лимит времени работы профиля, сек (0 — константа RUN_TIME_LIMIT)
     * @return array {status: done|error, message, stats}
     */
    protected function runController(
        \Bitrix\Main\Engine\Controller $controller,
        string $type,
        int $id,
        callable $first,
        callable $next,
        int $maxHits = 0,
        int $runTimeLimit = 0
    ): array {
        $timeLimit = ($runTimeLimit > 0) ? $runTimeLimit : self::RUN_TIME_LIMIT;
        $startTs = time();
        $iteration = 0;
        $last = null;
        $errors = [];

        while (true) {
            $iteration++;
            if ($maxHits > 0 && $iteration > $maxHits) {
                break;
            }
            if ($iteration > self::MAX_ITERATIONS) {
                $errors[] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_MAX_ITER');
                break;
            }
            if ((time() - $startTs) > $timeLimit) {
                $errors[] = Loc::getMessage('AWZ_ALLXLS_CRON_RUN_TIME_LIMIT');
                break;
            }

            $result = ($iteration === 1) ? $first() : $next();
            if ($result === null) {
                // ошибка шага — собираем ошибки контроллера
                foreach ($controller->getErrors() as $error) {
                    $errors[] = $error->getMessage().' ['.$error->getCode().']';
                }
                break;
            }
            $last = $result;
            if (!empty($result['done'])) break;
        }

        if (!empty($errors)) {
            return [
                'status' => 'error',
                'message' => implode('; ', array_unique($errors)),
                'stats' => is_array($last) ? $this->statsFromResult($last) : [],
            ];
        }

        // пошаговый режим: обработка не завершена — продолжится на следующем вызове
        $done = is_array($last) && !empty($last['done']);
        return [
            'status' => $done ? 'done' : 'step',
            'message' => $done ? '' : Loc::getMessage('AWZ_ALLXLS_CRON_RUN_STEP_CONT'),
            'stats' => is_array($last) ? $this->statsFromResult($last) : [],
        ];
    }

    /**
     * Итоговая статистика из ответа последнего хита.
     * @param array $result
     * @return array
     */
    protected function statsFromResult(array $result): array
    {
        $stats = [];
        if (isset($result['stats']) && is_array($result['stats'])) {
            foreach ($result['stats'] as $key => $value) {
                $stats[(string)$key] = $value;
            }
        }
        return $stats;
    }
}
