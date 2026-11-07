<?php
namespace Awz\Allxls\Api\Chain;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Tables\ProfilesHashesTable;
use Awz\Allxls\MapRules;
use Awz\Allxls\Helper\FastXlsReader;
use Awz\Allxls\Helper\FastXmlReader;
use Awz\Allxls\Helper\ProfilesHelper;
use Awz\Allxls\Logger;
use Awz\Allxls\Handlers;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Р‘Р°Р·РѕРІС‹Р№ РєР»Р°СЃСЃ РёРјРїРѕСЂС‚Р°: РЅР°РєРѕРїРёС‚РµР»СЊРЅР°СЏ СЃС‚Р°С‚РёСЃС‚РёРєР° Рё Р·Р°РјРµСЂС‹ РІСЂРµРјРµРЅРё.
 * Р’С‹РЅРµСЃРµРЅРѕ РёР· Pimport РґР»СЏ С‡РёС‚Р°РµРјРѕСЃС‚Рё; Pimport РЅР°СЃР»РµРґСѓРµС‚ РІСЃСЋ С†РµРїРѕС‡РєСѓ.
 */
class Stats extends \Bitrix\Main\Engine\Controller {

    /** @var array накопительная статистика обработки (счетчики) */
    protected array $processStats = [];

    /** @var array замеры времени методов сервиса-приемника (считает импорт-класс, не сервис) */
    protected array $svcTimeStats = [
        'find' => ['time' => 0.0, 'count' => 0],
        'add' => ['time' => 0.0, 'count' => 0],
        'update' => ['time' => 0.0, 'count' => 0],
        'touch' => ['time' => 0.0, 'count' => 0],
        'delete' => ['time' => 0.0, 'count' => 0],
    ];

    /** @var array|null последняя записанная отображаемая статистика (сохраняется между saveState в рамках хита) */
    protected ?array $lastStatsView = null;

    /** @var array контекст замеров последнего построения статистики (для логгера) */
    protected array $lastPerfContext = [];

    /** @var array|null актуальное состояние PROCESS в памяти (ленивое сохранение в профиль) */
    protected ?array $pendingState = null;

    /** @var bool запуск из cron-процесса (CLI) — маркер SOURCE=cron в состоянии профиля для UI */
    protected bool $isCronRun = false;

    /** @var array замеры времени узких мест за запрос: ключ => суммарное время */
    protected static array $perfTimers = [];

    /** @var array замеры времени узких мест за запрос: ключ => число вызовов */
    protected static array $perfCounts = [];

    protected array $profileData = [];


    protected static function perfAccum(string $key, float $elapsed): void
{
    $elapsed = max(0.0, $elapsed);
    self::$perfTimers[$key] = (self::$perfTimers[$key] ?? 0.0) + $elapsed;
    self::$perfCounts[$key] = (self::$perfCounts[$key] ?? 0) + 1;
}
    protected function serviceTimeStats(): array
{
    return $this->svcTimeStats;
}
    protected function svcTime(string $key, callable $fn): array
{
    $t0 = microtime(true);
    try {
        return [$fn(), microtime(true) - $t0];
    } finally {
        $this->svcTimeStats[$key]['time'] += microtime(true) - $t0;
        $this->svcTimeStats[$key]['count']++;
    }
}
    protected function topColumns(array $colStats, int $limit = 10): array
{
    uasort($colStats, static fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
    $top = array_slice($colStats, 0, $limit, true);
    $result = [];
    foreach ($top as $alias => $v) {
        $result[] = [
            'alias' => $alias,
            'name' => (string)($v['name'] ?? ''),
            'fields' => (string)($v['fields'] ?? ''),
            'time' => round((float)($v['time'] ?? 0), 4),
            'count' => (int)($v['count'] ?? 0),
        ];
    }
    return $result;
}
    protected function dumpLiveStats(int $id, float $totalTime, float $hitElapsed = 0.0, int $hitRows = 0, float $basePeakMem = 0.0, array $svcBase = []): void
{
    try {
        // полная статистика — та же, что и в конце хита (единый вид
        // на кроне и в браузере: счетчики, время, таймеры, PHP, сервер)
        $stats = $this->buildStatsView($totalTime, $hitElapsed, $hitRows, $basePeakMem, $svcBase);
        $this->lastStatsView = $stats;

        $fresh = ProfilesTable::getById($id)->fetch();
        if (!$fresh) return;
        $st = $fresh['SETTINGS'] ?? null;
        if (!is_array($st)) $st = [];
        if (!is_array($st['PROCESS'] ?? null)) $st['PROCESS'] = [];
        // курсор в памяти актуальнее прочитанного — не затираем его
        if ($this->pendingState !== null && is_array($this->pendingState)) {
            $st['PROCESS'] = $this->pendingState;
        }
        $st['PROCESS']['STATS_VIEW'] = $stats;
        if ($this->isCronRun) {
            // heartbeat cron-процесса (каждые 30 сек — dumpLiveStats)
            $st['PROCESS']['CRON_ALIVE'] = time();
            // старт прогона: пишется в самой ранней записи состояния,
            // чтобы при падении процесса «Общее время работы» не обнулялось
            if ((int)($st['PROCESS']['RUN_STARTED'] ?? 0) <= 0) {
                $st['PROCESS']['RUN_STARTED'] = time();
            }
        }
        ProfilesTable::update($id, ['SETTINGS' => $st]);
    } catch (\Throwable $e) {
        // дамп статистики не должен ломать импорт
    }
}

    /**
     * Единая сборка отображаемой статистики (полный набор показателей):
     * счетчики строк, время работы, RPS, диагностика таймеров, PHP-параметры,
     * нагрузка сервера и замеры методов сервиса-приемника.
     * Используется в конце хита и в периодическом дампе на кроне —
     * статистика всегда одного вида.
     */
    protected function buildStatsView(float $totalTime, float $hitElapsed, int $hitRows, float $basePeakMem = 0.0, array $svcBase = []): array
{
    $raw = $this->processStats;
    $processedCount = (int)($raw['Обработано строк'] ?? 0);

    // RPS: среднее за весь импорт и за последний хит
    $avgRps = ($totalTime > 0 && $processedCount > 0) ? $processedCount / $totalTime : 0.0;
    $hitRps = ($hitElapsed > 0 && $hitRows > 0) ? $hitRows / $hitElapsed : 0.0;

    // пик памяти: максимальное значение за все время импорта (не перезаписываем меньшим)
    $peakNow = round(memory_get_peak_usage(true) / 1048576, 2);
    $peakMax = max($basePeakMem, $peakNow);

    $stats = [];
    $stats['Обработано строк'] = $processedCount;
    $stats['Добавлено новых'] = (int)($raw['Добавлено новых'] ?? 0);
    $stats['Обновлено'] = (int)($raw['Обновлено'] ?? 0);
    $stats['Удалено по правилу'] = (int)($raw['Удалено по правилу'] ?? 0);
    $stats['Ошибки удаления по правилу'] = (int)($raw['Ошибки удаления по правилу'] ?? 0);
    $stats['Пропущено пустых строк'] = (int)($raw['Пропущено пустых строк'] ?? 0);
    $stats['Пропущено по хешу'] = (int)($raw['Пропущено по хешу'] ?? 0);
    $stats['Пропущено по правилу'] = (int)($raw['Пропущено по правилам'] ?? 0);
    $stats['Пропущено: элемент не найден'] = (int)($raw['Пропущено: элемент не найден'] ?? 0);
    $stats['Прогресс, %'] = round((float)($raw['Прогресс файла, %'] ?? 0), 1);
    $totalSec = (int)round($totalTime);
    $mins = intdiv($totalSec, 60);
    $secs = $totalSec % 60;
    $stats['Общее время работы'] = ($mins > 0 ? $mins . ' мин ' : '') . $secs . ' сек';
    $stats['RPS: среднее'] = round($avgRps, 1);
    $stats['RPS: последний хит'] = round($hitRps, 1);

    // диагностика производительности хита
    $perfLabels = [
        'read_row' => 'Чтение строки файла, сек',
        'open_sheet' => 'Открытие листа/потока в хите, сек',
        'csv_skip' => 'Промотка CSV в начале хита, сек',
        'skip_row' => 'Пропуск строки (skipRow, вкл. saveState), сек',
        'hash_check' => 'Проверка хешей (БД), сек',
        'hash_load' => 'Загрузка хешей в память (HASH_MEM_CACHE), сек',
        'total_rows' => 'Подсчет итоговых строк (полный скан), сек',
        'hash_final' => 'Финализация хешей (БД), сек',
        'hash_save' => 'Сохранение хешей (БД), сек',
        'touch_hash' => 'Обновление даты элемента (пропуск по хешу), сек',
        'build_item' => 'Сборка полей (buildItem), сек',
        'process_item' => 'Обработка строки (вкл. find/add/update), сек',
        'save_state' => 'Сохранение состояния (ORM), сек',
    ];
    $this->lastPerfContext = [];
    $perfMeasured = 0.0;
    foreach ($perfLabels as $perfKey => $perfLabel) {
        if (!isset(self::$perfTimers[$perfKey])) continue;
        $stats[$perfLabel] = round(self::$perfTimers[$perfKey], 2)
            . ' (' . (int)(self::$perfCounts[$perfKey] ?? 0) . ')';
        $this->lastPerfContext[$perfKey] = round(self::$perfTimers[$perfKey], 3)
            . '/' . (int)(self::$perfCounts[$perfKey] ?? 0);
        // 'process_item' включает build_item/find/add/update — не суммируем
        if ($perfKey !== 'process_item' && $perfKey !== 'build_item') {
            $perfMeasured += (float)self::$perfTimers[$perfKey];
        }
    }
    $stats['Не учтено таймерами, сек'] = round(max(0.0, $hitElapsed - $perfMeasured), 2)
        . ' из ' . round($hitElapsed, 2);

    $memLimit = ini_get('memory_limit');
    $stats['PHP: лимит памяти'] = ($memLimit !== false && $memLimit !== '') ? $memLimit : '';
    $maxExec = ini_get('max_execution_time');
    $stats['PHP: макс время скрипта, сек'] =
        ($maxExec !== false && $maxExec !== '') ? (int)$maxExec : 0;
    $stats['PHP: пик памяти, Мб'] = $peakMax;
    $stats['Сервер'] = $this->serverLine();

    // накопительные замеры времени методов сервиса-приемника:
    // сохраненная база за весь импорт + время за текущий хит
    $svcTotals = $svcBase;
    foreach ($this->serviceTimeStats() as $key => $v) {
        $svcTotals[$key]['time'] = (float)($svcTotals[$key]['time'] ?? 0) + (float)($v['time'] ?? 0);
        $svcTotals[$key]['count'] = (int)($svcTotals[$key]['count'] ?? 0) + (int)($v['count'] ?? 0);
    }
    $svcLabels = ['find' => 'поиска элемента', 'add' => 'добавления элемента', 'update' => 'обновления элемента', 'touch' => 'обновления даты', 'delete' => 'удаления элемента'];
    foreach ($svcTotals as $key => $v) {
        if ((int)($v['count'] ?? 0) <= 0) continue;
        $label = $svcLabels[$key] ?? $key;
        // доля времени метода от общего времени работы импорта
        $percent = $totalTime > 0 ? round((float)($v['time'] ?? 0) / $totalTime * 100, 1) : 0;
        $stats['Время ' . $label . ', сек'] = round((float)($v['time'] ?? 0), 2) . ' (' . $v['count'] . ') — ' . $percent . '%';
    }

    // распределение времени ПОСЛЕДНЕГО хита по методам сервиса
    // (% от длительности хита, а не от общего времени импорта)
    $hitSvcTotal = 0.0;
    foreach ($this->serviceTimeStats() as $v) {
        $hitSvcTotal += (float)($v['time'] ?? 0);
    }
    if ($hitSvcTotal > 0 && $hitElapsed > 0) {
        foreach ($this->serviceTimeStats() as $key => $v) {
            if ((int)($v['count'] ?? 0) <= 0) continue;
            $label = $svcLabels[$key] ?? $key;
            $percent = round((float)($v['time'] ?? 0) / $hitElapsed * 100, 1);
            $stats['Хит: время ' . $label . ', сек'] =
                round((float)($v['time'] ?? 0), 2) . ' (' . (int)$v['count'] . ') — ' . $percent . '% хита';
        }
        $stats['Хит: время методов сервиса, сек'] =
            round($hitSvcTotal, 2) . ' — ' . round($hitSvcTotal / $hitElapsed * 100, 1) . '% хита';
    }

    return $stats;
}
    protected function resolveService(string $destType): \Awz\Allxls\Services\Base
{
    $service = ServiceLocator::getInstance()->get('awz.allxls.'.$destType);
    $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsResolveService', [
        'dest_type' => $destType,
        'service' => $service,
        'profile' => $this->profileData,
    ]);
    $event->send();
    foreach ($event->getResults() as $result) {
        $params = $result->getParameters();
        if (isset($params['service']) && $params['service'] instanceof \Awz\Allxls\Services\Base) {
            $service = $params['service'];
        }
    }
    return $service;
}
}
