<?php
/**
 * CLI-запуск импортов/экспортов по расписанию (crontab).
 *
 * Примеры:
 *   php bitrix/modules/awz.allxls/cron/run.php all      — общее cron задание: все профили
 *                                                         с опцией «общее cron задание»
 *   php bitrix/modules/awz.allxls/cron/run.php 3        — отдельное cron задание: один профиль
 *   php bitrix/modules/awz.allxls/cron/run.php 3,7,12   — несколько профилей
 *
 * Опция -t (--time) — таймаут работы скрипта в секундах (0 или не задан — без ограничения).
 * Не начатые профили пропускаются, работа текущего профиля ограничивается остатком времени.
 * Альтернатива — переменная окружения AWZ_ALLXLS_TIME_LIMIT.
 * Пример: php cron/run.php -t 600 all
 *
 * Рекомендуемое задание crontab (каждые 5 минут):
 *   каждые 5 минут: cd /путь/к/сайту && /usr/bin/php bitrix/modules/awz.allxls/cron/run.php all >/dev/null 2>&1
 *
 * Запуск возможен только из командной строки (не по URL).
 */

if (PHP_SAPI !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

$timeLimit = 0;

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', '1');
if (function_exists('set_time_limit')) @set_time_limit(0);
if (function_exists('ini_set')) @ini_set('memory_limit', '1024M');

$_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__)."/../../../..");
$DOCUMENT_ROOT = $_SERVER["DOCUMENT_ROOT"];

// DOCUMENT_ROOT — на 3 уровня выше скрипта (bitrix/modules/<module>/cron/run.php)
$docRoot = $DOCUMENT_ROOT;
$argvRaw = $argv;
array_shift($argvRaw);
foreach ($argvRaw as $key => $arg) {
    if ($arg === '-t' && isset($argvRaw[$key + 1])) {
        $timeLimit = (int)$argvRaw[$key + 1];
        unset($argvRaw[$key], $argvRaw[$key + 1]);
    }
    if (preg_match('/^(--time=)(\d+)$/', $arg, $m)) {
        $timeLimit = (int)$m[2];
        unset($argvRaw[$key]);
    }
}
if ($timeLimit < 0) $timeLimit = 0;
if ($timeLimit === 0 && getenv('AWZ_ALLXLS_TIME_LIMIT') !== false) {
    $timeLimit = max(0, (int)getenv('AWZ_ALLXLS_TIME_LIMIT'));
}
if (!is_file($docRoot.'/bitrix/modules/main/include/prolog_before.php')) {
    fwrite(STDERR, "Не удалось определить DOCUMENT_ROOT (скрипт должен лежать в bitrix/modules/awz.allxls/cron/)\n");
    exit(1);
}
$docRoot = str_replace('\\', '/', $docRoot);
$_SERVER['DOCUMENT_ROOT'] = $docRoot;

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('STOP_STATISTICS', true);
define('BX_CRONTAB_SUPPORT', true);

require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

// аргументы: all | список ID через запятую
$args = array_values(array_filter(array_map('trim', $argvRaw), function ($v) { return $v !== ''; }));
$profileArg = strtolower((string)($args[0] ?? 'all'));

if (!\Bitrix\Main\Loader::includeModule('awz.allxls')) {
    fwrite(STDERR, "Модуль awz.allxls не установлен\n");
    exit(1);
}

// скачивание/актуализация файлов внешних источников профилей
// (кеш с TTL из SETTINGS[FILE_CACHE_LIFETIME]) — до запуска прогонов,
// чтобы импорт работал по свежему файлу
$extLog = [];
$explicitIds = (preg_match('/^[\d,\s]+$/', $profileArg) && $profileArg !== 'all')
    ? array_filter(array_map('intval', explode(',', $profileArg))) : [];
try {
    $extLog = \Awz\Allxls\Helper\ProfilesHelper::refreshExternalProfiles(
        $profileArg === 'all' ? 'cron' : 'cron_own',
        $explicitIds
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка актуализации внешних файлов: '.$e->getMessage()."\n");
}
foreach ($extLog as $item) {
    echo sprintf(
        "[%s] #%d %s — внешний файл: %s%s\n",
        date('Y-m-d H:i:s'),
        $item['id'],
        $item['name'],
        $item['status'],
        $item['message'] !== '' ? ' ('.$item['message'].')' : ''
    );
}

$runner = new \Awz\Allxls\Cron\Runner();
$runner->setTimeLimit($timeLimit);

try {
    if ($profileArg === 'all') {
        // общее cron задание: обрабатываются только профили в режиме «общее
        // cron задание» (SETTINGS[CRON_MODE]='cron'); профили в режимах
        // «отдельное cron задание» и «агент Bitrix» не затрагиваются
        $log = $runner->execAll('cron');
    } elseif (preg_match('/^[\d,\s]+$/', $profileArg)) {
        $ids = array_filter(array_map('intval', explode(',', $profileArg)));
        // отдельное cron задание: скрипт проверяет опцию в настройках профиля —
        // запускаются только профили в режиме «отдельное cron задание»
        // (SETTINGS[CRON_MODE]='cron_own') с включенным расписанием
        $log = $runner->exec($ids, true);
    } else {
        fwrite(STDERR, "Аргументы: all | список ID профилей через запятую [-t секунды]\n");
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Ошибка: '.$e->getMessage()."\n");
    exit(1);
}

foreach ($log as $item) {
    $line = sprintf(
        "[%s] #%d %s — %s%s\n",
        date('Y-m-d H:i:s'),
        $item['id'],
        $item['name'],
        $item['status'],
        $item['message'] !== '' ? ': '.$item['message'] : ''
    );
    echo $line;
}

// диагностика: фактическое состояние обработки в базе (курсор, статистика, heartbeat)
try {
    foreach ($ids ?? [] as $diagId) {
        if ($profileArg === 'all') break;
        $row = \Awz\Allxls\Tables\ProfilesTable::getById((int)$diagId)->fetch();
        if (!$row) continue;
        $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
        $procKey = (($row['TYPE'] ?? 'import') === 'export') ? 'PROCESS_EXPORT' : 'PROCESS';
        $proc = is_array($st[$procKey] ?? null) ? $st[$procKey] : [];
        echo sprintf(
            "[diag] #%d лист=%s строка=%s обработано=%d updated=%s alive=%s started=%s stats_view=%s\n",
            $diagId,
            (string)($proc['SHEET'] ?? ''),
            (int)($proc['ROW'] ?? 0),
            (int)($proc['PROCESSED'] ?? 0),
            (string)($proc['UPDATED'] ?? ''),
            (string)($proc['CRON_ALIVE'] ?? ''),
            (string)($proc['RUN_STARTED'] ?? ''),
            isset($proc['STATS_VIEW']['Обработано строк']) ? (string)$proc['STATS_VIEW']['Обработано строк'] : 'нет'
        );
    }
} catch (\Throwable $e) {
    echo '[diag] ошибка: '.$e->getMessage()."\n";
}

exit(0);
