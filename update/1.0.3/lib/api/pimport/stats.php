<?php
namespace Awz\Allxls\Api\Pimport;

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
class PimportStats extends \Bitrix\Main\Engine\Controller {

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
    protected function dumpLiveStats(int $id, float $totalTime, float $hitElapsed = 0.0, int $hitRows = 0): void
{
    try {
        $raw = $this->processStats;
        $stats = [
            'Обработано строк' => (int)($raw['Обработано строк'] ?? 0),
            'Добавлено новых' => (int)($raw['Добавлено новых'] ?? 0),
            'Обновлено' => (int)($raw['Обновлено'] ?? 0),
            'Удалено по правилу' => (int)($raw['Удалено по правилу'] ?? 0),
            'Ошибки' => (int)($raw['Ошибки'] ?? 0),
            'Пропущено пустых строк' => (int)($raw['Пропущено пустых строк'] ?? 0),
            'Пропущено по хешу' => (int)($raw['Пропущено по хешу'] ?? 0),
            'Пропущено по правилам' => (int)($raw['Пропущено по правилам'] ?? 0),
            'Пропущено: элемент не найден' => (int)($raw['Пропущено: элемент не найден'] ?? 0),
            'Текущий лист' => (string)($raw['Текущий лист'] ?? ''),
            'Текущая строка' => (int)($raw['Текущая строка'] ?? 0),
            'Прогресс, %' => round((float)($raw['Прогресс файла, %'] ?? 0), 1),
        ];
        $totalSec = (int)round($totalTime);
        $mins = intdiv($totalSec, 60);
        $secs = $totalSec % 60;
        $stats['Общее время работы'] = ($mins > 0 ? $mins . ' мин ' : '') . $secs . ' сек';
        // скорость обработки: средняя за весь импорт и за последний хит
        $processedCount = (int)($raw['Обработано строк'] ?? 0);
        $stats['RPS: среднее'] = ($totalTime > 0 && $processedCount > 0) ? round($processedCount / $totalTime, 1) : 0;
        $stats['RPS: последний хит'] = ($hitElapsed > 0 && $hitRows > 0) ? round($hitRows / $hitElapsed, 1) : 0;
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
