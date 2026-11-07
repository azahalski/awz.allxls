<?php
namespace Awz\Allxls\Api;

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
use Awz\Allxls\Helper\FastJsonReader;
use Awz\Allxls\Helper\ProfilesHelper;
use Awz\Allxls\Logger;
use Awz\Allxls\Handlers;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/classes/general/csv_data.php');

/**
 * Сигнал пропуска строки из processItem (например, опция «пропускать строку,
 * если элемент не найден»): не считается ошибкой обработки.
 */
class SkipRowSignal extends \RuntimeException
{
    /** ключ статистики для skipRow (иначе — «Пропущено: элемент не найден») */
    public string $statKey = '';

    public function __construct(string $message = '', string $statKey = '')
    {
        parent::__construct($message);
        $this->statKey = $statKey;
    }
}

/**
 * Контроллер пошаговой обработки профиля (этап 4).
 * За один запрос обрабатывает одну строку файла источника
 * и возвращает текущий прогресс + JSON обрабатываемого элемента.
 *
 * Файл читается побайтово, без загрузки всего файла в память:
 *  - CSV: штатный \CCSVData (bitrix/modules/main/classes/general/csv_data.php)
 *    с сохранением байтовой позиции курсора между запросами;
 *  - XLSX/ODS: ReadFilter, загружающий только текущую строку активного листа.
 *
 * В статистику возвращается потребление памяти.
 *
 * Экшен: awz:allxls.api.pimport.import
 */
class Pimport extends Chain\Rules {



    /** @var string тип проверки хешей: '' — без проверки, 'row' — по хешу строки файла, 'item' — по хешу вычисленных значений */
    protected string $hashType = 'row';



    /** @var bool пропускать строку, если существующий элемент не найден (не добавлять новые) */
    protected bool $skipIfNotFound = false;



    /** @var float время последней записи состояния в профиль */
    protected float $lastStateSave = 0.0;
    /** @var float минимальный интервал записи состояния в базу, сек (в cron — 30, в браузере — 1) */
    protected float $stateSaveInterval = 1.0;
    /** @var float время последнего «живого» дампа статистики (для наблюдения прогресса cron-запуска в UI) */
    protected float $lastStatsDump = 0.0;

    /** @var FastXlsReader|null пошаговый читатель XLSX (живет в рамках одного хита) */
    protected ?FastXlsReader $fastReader = null;

    /** @var FastXmlReader|null пошаговый читатель XML/YML (живет в рамках одного хита) */
    protected ?FastXmlReader $fastXmlReader = null;

    /** @var FastJsonReader|null пошаговый читатель JSON (живет в рамках одного хита) */
    protected ?FastJsonReader $fastJsonReader = null;
    /** @var array карта дерева категорий профиля (SETTINGS.CAT_TREE.MAP) */
    protected array $catTree = [];
    /** @var string путь листа категорий (SETTINGS.CAT_TREE.PATH) */
    protected string $catTreePath = '';
    /** @var string путь(и) категорий текущей строки для item.categoryPath */
    protected string $rowCategoryPath = '';
    /** @var array кеш путей категорий (id => путь '|' за хит) */
    protected array $catPathCache = [];
    /** @var bool внешний источник уже актуализирован в рамках хита (TTL-кеш) */
    protected bool $sourceRefreshed = false;
    /** @var string ключ листа профиля, на котором открыт fastReader ('' — не открыт) */
    protected string $fastSheetKey = '';

    /** @var array байтовая позиция курсора XML-ридера по листам (коммится в PROCESS.XML_POS при сдвиге курсора строки) */
    protected array $xmlPosMap = [];

    /** @var int байтовая позиция после последнего прочитанного элемента (не закоммичена) */
    protected int $xmlPosPending = 0;

    /** @var string лист, к которому относится xmlPosPending */
    protected string $xmlPosSheet = '';
    /** @var resource|null CSV-хэндл файла (живет в рамках одного хита, не переоткрывается на каждую строку) */
    protected $csvHandle = null;
    /** @var bool заголовок и ранее обработанные записи CSV еще не пропущены в рамках хита */
    protected bool $csvSkipPending = true;
    /** @var array|null последняя прочитанная строка файла (CSV/XLSX) — буфер ретрая ошибочной строки в рамках хита */
    protected ?array $csvPendingRow = null;
    /** @var array|null данные профиля, закешированные на хит (не перечитываются в каждом importStep) */
    protected ?array $profileHitCache = null;
    /** @var int ID профиля, для которого закеширован profileHitCache */
    protected int $profileHitCacheId = 0;


    /** @var bool ошибка загрузки хешей в память — дедуп работает запросами в БД */
    protected bool $hashMemFailed = false;


    /** @var bool хеш строки только по задействованным колонкам (INCLUDE=Y и есть сопоставления) */
    protected bool $hashUsedColumns = false;

    /** @var string хеш финальных значений полей элемента: вычисляется в processItem
     * после поиска элемента и сборки загрузки (режим new/update, макросы #ENTITY_*#,
     * отсечение неизмененных полей) — то есть по значениям, которые реально
     * останутся в элементе после обработки строки */
    protected string $finalItemHash = '';






    /**
     * Накопление замера времени узкого места (сек, за вызов).
     * Метрики накапливаются за запрос (хит); сводка выводится в
     * «Хит импорта завершен» и в статистику шага 4.
     */

/**
     * Constructor Controller.
     * @param Request|null $request
     */
    public function __construct(Request $request = null)
    {
        \Bitrix\Main\Loader::includeModule('awz.allxls');
        parent::__construct($request);
    }

    protected function init()
    {
        // Включаем readonly-режим для сессии ДО инициализации родительского контроллера
        if (!defined('BX_SECURITY_SESSION_READONLY')) {
            define('BX_SECURITY_SESSION_READONLY', true);
        }

        parent::init();
    }

    public function configureActions()
    {
        return [
            'import' => [
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ]
        ];
    }

    /**
     * Обработка строк файла профиля.
     *
     * За один хит обрабатывается столько строк, сколько укладывается
     * в лимит времени $hitTimeLimit (сек). Состояние (позиция курсора)
     * сохраняется после каждой строки, поэтому при падении/прерывании
     * обработка продолжается с места остановки.
     *
     * @param int $id ID профиля
     * @param int $hitTimeLimit лимит времени выполнения хита, сек (0 - одна строка за запрос)
     * @param int $maxExecutionTime максимальное время работы скрипта, сек (0 - не менять)
     * @param string $reset Y - сбросить состояние и статистику (новый запуск импорта)
     * @param string $logErrors Y - писать ошибки в журнал Битрикса
     * @return array|null
     */
    public function importAction(int $id = 0, int $hitTimeLimit = 20, int $maxExecutionTime = 0, string $reset = 'N', string $logErrors = 'N', string $fromCron = 'N')
    {
        $hitTimeLimit = max(0, $hitTimeLimit);
        $maxExecutionTime = max(0, $maxExecutionTime);
        $reset = ($reset === 'Y');
        $logErrors = ($logErrors === 'Y');
        // CLI/cron-запуск: в состоянии профиля ставится маркер SOURCE=cron,
        // интерфейс по нему показывает «обработка запущена на crontab».
        // Запись состояния в базу — раз в 30 сек (ресурсы БД), курсор при
        // обрыве восстановится с потерей максимум 30 сек (строки докатываются
        // по дедупу хешей)
        $this->isCronRun = ($fromCron === 'Y');
        if ($this->isCronRun) {
            $this->stateSaveInterval = 30.0;
        }

        // профиль, выполняемый cron-процессом, может обрабатывать только сам cron
        if (!$this->cronRunGuard($id, $fromCron === 'Y')) return null;

        // новый запуск: обнуляем состояние и накопленную статистику импорта
        if ($reset) {
            $this->resetState($id);
            // заодно чистим временные копии источников (3 последних на профиль)
            $this->cleanupTmpDir();
        }

        // если max_execution_time хостинга жесткий (set_time_limit не работает),
        // хит не должен превышать его — иначе процесс убивается до сохранения
        // состояния; cap применяется ДО set_time_limit
        $iniMax = (int)ini_get('max_execution_time');
        if ($iniMax > 0) {
            $hitTimeLimit = min($hitTimeLimit, max(10, $iniMax - 15));
        }

        // максимальное время работы скрипта: с запасом поверх лимита хита,
        // чтобы успеть корректно сохранить состояние
        $scriptLimit = $maxExecutionTime > 0 ? $maxExecutionTime : ($hitTimeLimit > 0 ? $hitTimeLimit + 10 : 0);
        if ($scriptLimit > 0) {
            if (function_exists('set_time_limit')) @set_time_limit($scriptLimit);
            if (function_exists('ini_set')) @ini_set('max_execution_time', (string)$scriptLimit);
        }

        $hitStart = microtime(true);
        $last = null;
        $this->lastStatsDump = microtime(true);

        // накопленное общее время работы импорта (продолжается после обрыва)
        $baseTime = 0.0;
        // сохраненный максимум пиковой памяти (не перезаписываем меньшим)
        $basePeakMem = 0.0;
        // количество строк, обработанных до текущего хита (для расчета RPS хита)
        $startProcessed = 0;
        // накопленное время методов сервиса (поиск/добавление/обновление) за весь импорт
        $baseSvcTimes = [];
        $profileRow = ProfilesTable::getById($id)->fetch();
        $this->profileData = $profileRow;
        if ($profileRow) {
            // режим «по одному элементу»: каждая строка файла — отдельный
            // запрос, импорт останавливается после каждого элемента
            $this->stepByStep = (string)(($profileRow['SETTINGS'] ?? [])['STEP_BY_STEP'] ?? 'N') === 'Y';
            $st = $profileRow['SETTINGS'] ?? null;
            if (is_array($st) && is_array($st['PROCESS'] ?? null)) {
                $baseTime = (float)($st['PROCESS']['TOTAL_TIME'] ?? 0);
                $basePeakMem = (float)($st['PROCESS']['PEAK_MEM'] ?? 0);
                $startProcessed = (int)($st['PROCESS']['PROCESSED'] ?? 0);
                $baseSvcTimes = is_array($st['PROCESS']['SVC_TIME'] ?? null) ? $st['PROCESS']['SVC_TIME'] : [];
            }
        }

        while (true) {
            // защитный запас: не начинаем обработку строки, если до конца хита
            // осталось слишком мало времени — иначе обрыв посреди строки
            // даст элемент без хеша (дубль на следующем прогоне)
            $margin = 2.0;
            if ($hitTimeLimit > 0 && (microtime(true) - $hitStart) >= ($hitTimeLimit - $margin)) break;
            $step = $this->importStep($id, $logErrors);
            if ($step === null) {
                // ошибка шага (addError уже вызвана)
                return null;
            }
            $last = $step;
            if (!empty($step['done'])) break;
            // периодический дамп статистики: при долгом cron-хите (CRON_HIT_TIME=0
            // — обработка до завершения за один вызов) прогресс виден в интерфейсе
            if ((microtime(true) - $this->lastStatsDump) >= 30) {
                $this->lastStatsDump = microtime(true);
                $this->lastStateSave = microtime(true); // дамп включает состояние — без двойной записи
                $elapsedNow = microtime(true) - $hitStart;
                $this->dumpLiveStats(
                    $id,
                    $baseTime + $elapsedNow,
                    $elapsedNow,
                    max(0, (int)$last['processed'] - $startProcessed),
                    $basePeakMem,
                    $baseSvcTimes
                );
            }
            // режим «по одному элементу»: одна строка за запрос,
            // следующий элемент — по кнопке клиента
            if ($this->stepByStep) break;
            if ($hitTimeLimit > 0 && (microtime(true) - $hitStart) >= $hitTimeLimit) break;
        }

        if ($last !== null) {
            // параметры нагрузки сервера + скорость обработки за хит
            $elapsed = microtime(true) - $hitStart;
            $totalTime = $baseTime + $elapsed;
            $rawStats = (array)($last['stats'] ?? []);
            $rowsInHit = max(0, (int)$last['processed'] - $startProcessed);

            // финальная статистика: единая сборка полного набора показателей
            // (та же, что в периодическом дампе на кроне — вид всегда один)
            $stats = $this->buildStatsView($totalTime, $elapsed, $rowsInHit, $basePeakMem, $baseSvcTimes);

            // пик памяти: максимальное значение за все время импорта (не перезаписываем меньшим)
            $peakNow = round(memory_get_peak_usage(true) / 1048576, 2);
            $peakMax = max($basePeakMem, $peakNow);
            $processedCount = (int)($rawStats['Обработано строк'] ?? 0);

            Logger::info(
                'Хит импорта завершен',
                [
                    'profile_id' => $id,
                    'rows_in_hit' => $rowsInHit,
                    'elapsed_sec' => round($elapsed, 2),
                    'hit_rps' => round(($elapsed > 0 && $rowsInHit > 0) ? $rowsInHit / $elapsed : 0.0, 2),
                    'memory_peak_mb' => $peakMax,
                    'done' => !empty($last['done']),
                    'perf' => $this->lastPerfContext,
                ]
            );

            // накопительные замеры времени методов сервиса-приемника:
            // сохраненная база за весь импорт + время за текущий хит
            $svcTotals = $baseSvcTimes;
            foreach ($this->serviceTimeStats() as $key => $v) {
                $svcTotals[$key]['time'] = (float)($svcTotals[$key]['time'] ?? 0) + (float)($v['time'] ?? 0);
                $svcTotals[$key]['count'] = (int)($svcTotals[$key]['count'] ?? 0) + (int)($v['count'] ?? 0);
            }

            $last['stats'] = $stats;
            $last['totalTime'] = round($totalTime, 2);

            // сохраняем накопленное время работы и пик памяти в состоянии профиля
            // (перечитываем профиль заново, чтобы не затереть курсор,
            // обновленный внутри хита методом saveState)
            $freshRow = ProfilesTable::getById($id)->fetch();
            if ($freshRow) {
                $st = $freshRow['SETTINGS'] ?? null;
                if (!is_array($st)) $st = [];
                if (!is_array($st['PROCESS'] ?? null)) $st['PROCESS'] = [];
                // финальный сброс курсора хита: последняя порция строк могла
                // не попасть в БД из-за троттлинга записи в saveState (раз в
                // секунду) — тогда следующий хит начинал с устаревшего курсора
                // и заново пропускал уже обработанные строки по хешу
                if ($this->pendingState !== null && is_array($this->pendingState)) {
                    $st['PROCESS'] = $this->pendingState;
                }
                $st['PROCESS']['TOTAL_TIME'] = $totalTime;
                if ($this->isCronRun) {
                    // heartbeat в конце cron-хита (для детекта падения cron)
                    $st['PROCESS']['CRON_ALIVE'] = time();
                }
                // время старта текущего прогона: UI показывает общее время
                // (в т.ч. паузы между cron-вызовами) как elapsed от этой метки
                $runStarted = (int)($st['PROCESS']['RUN_STARTED'] ?? 0);
                if ($reset || $runStarted <= 0) {
                    $st['PROCESS']['RUN_STARTED'] = time();
                }
                $st['PROCESS']['PEAK_MEM'] = $peakMax;
                // накопленное время методов сервиса — общее время всего импорта
                $st['PROCESS']['SVC_TIME'] = $svcTotals;
                // отображаемая статистика (для показа при загрузке страницы этапа 4)
                $st['PROCESS']['STATS_VIEW'] = $stats;
                $this->lastStatsView = $stats;
                // импорт завершен — рабочая копия файла источника удаляется
                if (!empty($last['done'])) {
                    $this->deleteSourceCopy((string)($st['PROCESS']['TMP_FILE'] ?? ''));
                    unset($st['PROCESS']['TMP_FILE']);
                }
                ProfilesTable::update($id, [
                    'SETTINGS' => $st
                ]);
            }
        }

        return $last;
    }

    /**
     * Получение сервиса-приёмника по типу DEST_TYPE с возможностью подмены:
     * событие D7 onAwzAllxlsResolveService (модуль awz.allxls) — обработчик
     * меняет параметр 'service' (объект, реализующий Awz\Allxls\Services\Base)
     * и добавляет EventResult. Так можно подменить штатный сервис iblock/orm
     * или дать обработку своему DEST_TYPE, добавленному через
     * onAwzAllxlsGetDestTypes (шаг 2, селект «Куда грузим»).
     *
     * @param string $destType код типа приёмника
     * @return \Awz\Allxls\Services\Base
     */

/**
     * Накопительные замеры времени методов сервиса-приемника
     * (findElement/addElement/updateElement/touchElement).
     *
     * @return array [key => ['time'=>float,'count'=>int]]
     */

/**
     * Замер времени вызова метода сервиса-приемника (учет в импорт-классе:
     * заранее известно, какой метод вызывает фабрика — обновление или добавление).
     *
     * @param string $key find|add|update|touch|delete
     * @param callable $fn вызов метода сервиса
     * @return array [результат вызова, время выполнения (сек)]
     */

/**
     * Параметры сервера одной строкой: нагрузка, ядра, свободное место.
     * @return string
     */
    protected function serverLine(): string
    {
        $parts = [];

        // средняя нагрузка (Linux)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load) && count($load) >= 3) {
                $parts[] = 'нагрузка ' . implode(' / ', array_map(
                    static fn($v) => round((float)$v, 2), $load
                ));
            }
        }

        // количество ядер CPU
        $cores = 1;
        if (is_callable('shell_exec')) {
            $out = @shell_exec('nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null');
            $cores = (int)trim((string)$out) ?: 1;
        }
        $parts[] = 'ядер ' . $cores;

        // свободное место на диске с сайтом
        $docRoot = \Bitrix\Main\Application::getDocumentRoot();
        $free = @disk_free_space($docRoot);
        if ($free !== false && $free > 0) {
            $parts[] = 'свободно ' . round($free / 1073741824, 2) . ' Гб';
        }

        return implode(', ', $parts);
    }

    /**
     * Обработка одной строки файла профиля.
     *
     * @param int $id ID профиля
     * @return array|null
     */
    protected function importStep(int $id = 0, bool $logErrors = false)
    {
        $id = (int)$id;
        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                'NOT_FOUND'
            ));
            return null;
        }

        try {
            // профиль читается один раз за хит; в каждом importStep перечитывать
            // его дорого (распаковка gzcompress SETTINGS на каждую строку).
            // Актуальное состояние PROCESS подставляется ниже из pendingState.
            if ($this->profileHitCache === null || $this->profileHitCacheId !== $id) {
                $profile = ProfilesTable::getById($id)->fetch();
                if (!$profile) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                        'NOT_FOUND'
                    ));
                    return null;
                }
                $this->profileHitCache = $profile;
                $this->profileHitCacheId = $id;
            } else {
                $profile = $this->profileHitCache;
            }

            $settings = $profile['SETTINGS'] ?? null;
            $filePath = (string)($settings['FILE_PATH'] ?? '');
            if ($filePath === '') {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_EMPTY'),
                    'FILE_EMPTY'
                ));
                return null;
            }

            $entityId = (int)($settings['IBLOCK_ID'] ?? 0);
            // хеш строки только по задействованным колонкам: INCLUDE=Y и есть сопоставления
            $this->hashUsedColumns = (string)($settings['HASH_USED_COLUMNS'] ?? 'N') === 'Y';
            // пропускать строку, если существующий элемент не найден (не добавлять новые)
            $this->skipIfNotFound = (string)($settings['SKIP_IF_NOT_FOUND'] ?? 'N') === 'Y';

            if (ProfilesHelper::isExternalPath($filePath)) {
                // внешний источник: локальный кеш-файл (качается на cron/агенте).
                // Ручной запуск тоже актуализирует файл с учетом TTL кеша
                if (!$this->sourceRefreshed) {
                    $this->sourceRefreshed = true;
                    ProfilesHelper::refreshProfileSource($profile);
                }
                $absPath = ProfilesHelper::sourceAbsPath($id, $settings);
            } else {
                $absPath = \Bitrix\Main\Application::getDocumentRoot().$filePath;
            }
            if (!file_exists($absPath) || !is_file($absPath)) {
                $this->addError(new \Bitrix\Main\Error(
                    ProfilesHelper::isExternalPath($filePath)
                        ? (Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_CACHE_PENDING') ?: 'Файл по внешней ссылке еще не скачан — дождитесь cron/агента')
                        : Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_NOT_EXISTS'),
                    'FILE_NOT_EXISTS'
                ));
                return null;
            }

            // активные листы профиля
            $profileSheets = is_array($settings['SHEETS'] ?? null) ? $settings['SHEETS'] : [];
            $activeSheets = [];
            foreach ($profileSheets as $sheetKey => $sheet) {
                if (($sheet['ACTIVE'] ?? 'Y') !== 'N') {
                    $sheet['NAME'] = (string)($sheet['NAME'] ?? $sheetKey);
                    $activeSheets[$sheetKey] = $sheet;
                }
            }
            if (!$activeSheets) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PIMPORT_NO_SHEETS'),
                    'NO_SHEETS'
                ));
                return null;
            }

            // тип проверки хешей из настроек профиля
            $this->hashType = (string)($settings['HASH_TYPE'] ?? 'row');

            // состояние обработки (позиция курсора) хранится в настройках профиля
            // ленивое сохранение: если в рамках хита состояние уже в памяти — берем его
            $settings = $profile['SETTINGS'] ?? null;
            if ($this->pendingState !== null) {
                $settings['PROCESS'] = $this->pendingState;
            }
            $state = is_array($settings['PROCESS'] ?? null) ? $settings['PROCESS'] : [];
            // карта байтовых курсоров XML восстанавливается из состояния:
            // свойство пустое на новом хите — без инициализации saveState
            // затирал сохраненные в БД позиции и XML перечитывался с начала
            if (!$this->xmlPosMap && !empty($state['XML_POS']) && is_array($state['XML_POS'])) {
                $this->xmlPosMap = $state['XML_POS'];
            }
            $sheetName = (string)($state['SHEET'] ?? '');
            $rowNum = (int)($state['ROW'] ?? 0);
            $offset = (int)($state['OFFSET'] ?? 0);
            $processed = (int)($state['PROCESSED'] ?? 0);
            // накопительная статистика, ошибки — защищенные свойства объекта
            $this->processStats = is_array($state['STATS'] ?? null) ? $state['STATS'] : [];
            $this->processErrors = is_array($state['ERRORS'] ?? null) ? $state['ERRORS'] : [];
            // накопительная статистика по колонкам (время обработки)
            $colStats = is_array($state['COL_STATS'] ?? null) ? $state['COL_STATS'] : [];

            // 1. Сохраняем оригинальный путь до файла-источника
            $srcPath = $absPath;

            $tmpFileInState = (string)($state['TMP_FILE'] ?? '');

            // Используем File из ядра Битрикс для проверки существования копии
            if ($tmpFileInState !== '' && (new \Bitrix\Main\IO\File($tmpFileInState))->isExists()) {
                // Копия уже создана на предыдущем хите и она на месте
                $absPath = $tmpFileInState;
            } else {
                // 2. Если в состоянии был путь, но файла физически нет — зачищаем старую запись
                if ($tmpFileInState !== '') {
                    $this->deleteSourceCopy($tmpFileInState);
                    $state['TMP_FILE'] = '';
                }

                // 3. Создаем копию, всегда передавая ОРИГИНАЛЬНЫЙ путь ($srcPath)
                $newTmp = $this->ensureSourceCopy($id, $srcPath);
                if ($newTmp === '') {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PIMPORT_FILE_OPEN_ERROR') ?: 'Не удалось создать рабочую копию файла',
                        'FILE_OPEN_ERROR'
                    ));
                    return null;
                }

                // 4. Запоминаем новую копию в состоянии и перенаправляем $absPath на неё
                $state['TMP_FILE'] = $newTmp;
                $absPath = $newTmp;

                // Путь копии должен дожить до следующего importStep и хита:
                // saveState перезаписывает PROCESS и восстанавливает TMP_FILE
                // именно из $settings['PROCESS'] (keepKey) — без синхронизации
                // здесь на каждую строку создавалась новая копия файла
                $settings['PROCESS'] = $state;
                $this->pendingState = $state;

                // создается новая копия — заодно чистим старые (3 последних на профиль)
                $this->cleanupTmpDir();

                Logger::info(
                    'Создана рабочая копия файла импорта',
                    ['profile_id' => $id, 'src' => $srcPath, 'tmp' => $newTmp]
                );
            }

            // XML/YML/JSON: лист = путь до повторяющегося элемента, HEADER_ROW не используется,
            // первый элемент листа — первая строка данных (rowNum = 1)
            // тип файла: явный FILE_EXT (ссылка/FTP без расширения) либо расширение пути
            $fileExt = ProfilesHelper::fileExtOf($absPath, $settings);
            $isXmlFile = in_array($fileExt, ['xml', 'yml'], true);
            $isJsonFile = $fileExt === 'json';
            $isPathsFile = $isXmlFile || $isJsonFile;

            // дерево категорий: при старте импорта (PROCESS.CAT_TREE_BUILT пуст —
            // resetState обнуляет PROCESS) пересобирается ВСЕГДА, т.к. файл
            // мог измениться. Хранится в SETTINGS.CAT_TREE (летучий ключ —
            // на SETTINGS_HASH не влияет)
            $this->catTree = [];
            if ($isXmlFile) {
                $catTreeBuilt = (string)($state['CAT_TREE_BUILT'] ?? '');
                $catTreeOld = is_array($settings['CAT_TREE'] ?? null) ? $settings['CAT_TREE'] : [];
                if ($catTreeBuilt === '' || empty($catTreeOld['MAP'])) {
                    // путь в настройках не важен: <category id/parentId> сканируются
                    // по всему файлу, независимо от XML_PATHS
                    $catPath = 'categories->category';
                    $tree = ProfilesHelper::buildCategoryTree($absPath, $catPath);
                    if (!empty($tree['map'])) {
                        $settings['CAT_TREE'] = [
                            'PATH' => $catPath,
                            'MAP' => $tree['map'],
                            'BUILT' => date('Y-m-d H:i:s'),
                        ];
                    }
                    // маркер выставляется ВСЕГДА (даже если категорий в файле нет):
                    // иначе buildCategoryTree (полный скан файла) выполнялся
                    // заново на каждую импортируемую строку
                    $state['CAT_TREE_BUILT'] = date('Y-m-d H:i:s');
                    $settings['PROCESS'] = $state;
                    $this->pendingState = $state;
                    // кеш профиля обновляется на месте (сброс null приводил
                    // к повторному чтению/распаковке SETTINGS на каждой строке)
                    $this->profileHitCache = $profile;
                    $this->profileHitCache['SETTINGS'] = $settings;
                    $this->profileHitCacheId = $id;
                    try {
                        \Awz\Allxls\Tables\ProfilesTable::update($id, ['SETTINGS' => $settings]);
                    } catch (\Throwable $e) {
                        Logger::warning('Не сохранено дерево категорий профиля', ['profile_id' => $id, 'error' => $e->getMessage()]);
                    }
                }
                $catTree = is_array($settings['CAT_TREE'] ?? null) ? $settings['CAT_TREE'] : [];
                $this->catTree = is_array($catTree['MAP'] ?? null) ? $catTree['MAP'] : [];
                $this->catTreePath = (string)($catTree['PATH'] ?? '');
            }

            if ($sheetName === '' || !isset($activeSheets[$sheetName])) {
                $sheetName = (string)array_key_first($activeSheets);
                $rowNum = $isPathsFile ? 1 : max(1, (int)($activeSheets[$sheetName]['HEADER_ROW'] ?? 1)) + 1;
                $offset = 0;
            }

            $delimiter = (string)($settings['CSV_DELIMITER'] ?? ',');
            $isCsv = $fileExt === 'csv';

            // опция «загрузить все хеши в память» (HASH_MEM_CACHE=Y): набор
            // ROW_HASH профиля берется одним запросом на хит вместо запроса
            // на каждую строку — хеши, сохраненные в рамках хита, добавляются
            // в набор методом saveHashSafe
            if ($this->hashType !== 'no'
                && (string)($settings['HASH_MEM_CACHE'] ?? 'N') === 'Y'
                && $this->hashMemSet === null && !$this->hashMemFailed) {
                try {
                    $__perfT0 = microtime(true);
                    $this->hashMemSet = ProfilesHashesTable::loadRowHashes($id);
                    self::perfAccum('hash_load', microtime(true) - $__perfT0);
                    Logger::info(
                        'Хеши профиля загружены в память',
                        ['profile_id' => $id, 'count' => count($this->hashMemSet)]
                    );
                } catch (\Throwable $e) {
                    $this->hashMemFailed = true;
                    Logger::error(
                        'Не удалось загрузить хеши профиля в память — дедуп работает запросами в БД',
                        ['profile_id' => $id, 'error' => $e->getMessage()]
                    );
                }
            }

            // статистика памяти
            $memStart = memory_get_usage(false);
            $memPeakStart = memory_get_peak_usage(false);
            $bytesRead = 0;

            // общий размер файла источника (для расчета прогресса в байтах)
            $fileSize = (int)(@filesize($absPath) ?: 0);
            // текущая позиция чтения (байты для CSV, 0 для XLSX)
            $readPos = 0;
            // прогресс обработки файла в процентах (по счетчику байтов или строк листа)
            $percent = 0.0;
            // известные итоговые количества строк по листам (XLSX/ODS)
            $sheetTotals = is_array($state['SHEET_TOTALS'] ?? null) ? $state['SHEET_TOTALS'] : [];

            $done = false;
            $item = null;

            if ($isCsv) {
                // === CSV: запись-ориентированное чтение через fgetcsv ===
                // Байтовые смещения не используются: CCSVData::SetPos может попасть
                // в середину записи (переводы строк внутри кавычек) — строка читается
                // частично, ее хеш меняется от прохода к проходу, дедуп не работает.
                // Позиционирование — по номеру записи (ROW): fgetcsv корректно
                // обрабатывает многострочные поля в кавычках.

                // миграция: состояние могло быть сохранено старым байтовым ридером
                // (CCSVData считал физические строки, многострочные записи резались).
                // Номера записей нового ридера не совпадают — начинаем заново по записям.
                // Хеши при этом НЕ чистятся: нормально прочитанные строки будут
                // пропущены по хешу, сломанные многострочные записи импортируются заново.
                if ($offset > 0) {
                    Logger::info(
                        'Состояние CSV от старого байтового ридера — чтение начато заново по записям',
                        ['profile_id' => $id, 'old_row' => $rowNum, 'old_offset' => $offset]
                    );
                    $rowNum = max(1, (int)($activeSheets[$sheetName]['HEADER_ROW'] ?? 1)) + 1;
                    $offset = 0;
                }

                // хэндл и промотка живут весь хит: переоткрытие файла и пропуск
                // уже обработанных записей на каждую строку давали O(n²) —
                // к концу файла каждый шаг проматывал весь файл заново
                $headerRows = max(1, (int)($activeSheets[$sheetName]['HEADER_ROW'] ?? 1));
                $firstDataRow = $headerRows + 1;
                if ($this->csvHandle === null) {
                    $handle = @fopen($absPath, 'rb');
                    if (!$handle) {
                        $this->addError(new \Bitrix\Main\Error(
                            Loc::getMessage('AWZ_ALLXLS_API_PIMPORT_FILE_OPEN_ERROR') ?: 'Не удалось открыть файл',
                            'FILE_OPEN_ERROR'
                        ));
                        return null;
                    }
                    $this->csvHandle = $handle;
                    $this->csvSkipPending = true;
                }
                $handle = $this->csvHandle;
                if ($this->csvSkipPending) {
                    $__perfT0 = microtime(true);
                    // пропуск заголовка и обработанных записей — один раз за хит
                    for ($i = 0; $i < $headerRows; $i++) {
                        fgetcsv($handle, 0, $delimiter);
                    }
                    $skipRecords = max(0, $rowNum - $firstDataRow);
                    for ($i = 0; $i < $skipRecords; $i++) {
                        if (fgetcsv($handle, 0, $delimiter) === false) break;
                    }
                    unset($skipRecords);
                    $this->csvSkipPending = false;
                    self::perfAccum('csv_skip', microtime(true) - $__perfT0);
                }

                // ретрай ошибочной строки: она уже прочитана в предыдущем шаге
                // хита — берем ее из буфера, а не из файла (курсор уже ушел вперед)
                if ((int)($state['RETRY'] ?? 0) > 0 && $this->csvPendingRow !== null) {
                    $row = $this->csvPendingRow;
                } else {
                    $__perfT0 = microtime(true);
                    $row = fgetcsv($handle, 0, $delimiter);
                    self::perfAccum('read_row', microtime(true) - $__perfT0);
                    $this->csvPendingRow = is_array($row) ? $row : null;
                }
                $bytesRead = (int)@ftell($handle);
                $readPos = $bytesRead;
                if ($fileSize > 0) {
                    $percent = min(100.0, $readPos / $fileSize * 100);
                }
                if ($row === false || $row === null) {
                    // файл дочитан до конца
                    fclose($handle);
                    $this->csvHandle = null;
                    $this->csvSkipPending = true;
                    $this->csvPendingRow = null;
                    $done = true;
                    $percent = 100.0;
                }
                if ($row !== false && $row !== null) {
                    if ($this->isEmptyRow($row)) {
                        // пустая строка — вообще не обрабатываем, только сдвигаем курсор
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено пустых строк', null,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }
                    $row = $this->toUtf8Row((array)$row);
                    // техническая информация строки: значения колонок из файла (режим «по одному элементу»)
                    if ($this->stepByStep) {
                        $this->rowDebug = ['raw' => $this->rowDebugRaw((array)$row, $activeSheets[$sheetName])];
                    }
                    // хеш сырой строки файла — для проверки и сохранения (действует в любом режиме);
                    // опция HASH_USED_COLUMNS: только задействованные колонки строки
                    $rowHashRaw = $this->hashUsedColumns
                        ? $this->usedRowHash((array)$row, $activeSheets[$sheetName])
                        : $this->rowHash((array)$row);
                    // проверка по хешу строки файла — в момент загрузки строки
                    if ($this->hashType !== 'no'
                        && $this->checkHashSafe($id, $rowHashRaw)
                        && $this->isHashFinal($id, $rowHashRaw)) {
                        $this->touchHashElement($id, $rowHashRaw, $settings);
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено по хешу', null,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }

                    $this->rowSkipRules = false;
                    $this->rowDeleteRules = false;
                    MapRules::$lastUnset = false;
                    // #ELEMENT_FOUND# не должен тянуться с предыдущей строки:
                    // в полной сборке он всегда «не найден», реальное значение
                    // станет известно в processItem после поиска элемента
                    $this->itemElementFound = 'N';
                    // данные найденного элемента сбрасываются: макросы #ENTITY_*#
                    // до поиска — пусто, реальное значение появится в processItem
                    $this->itemEntityData = null;
                    $this->rowSkipRuleInfo = null;
                    MapRules::$lastSkip = false;
                    MapRules::$lastSkipRule = null;
                    MapRules::$lastSkipValue = null;
                    MapRules::$lastDelete = false;
                    MapRules::$lastApplied = [];
                    $item = $this->buildItem($entityId, (array)$row, $activeSheets[$sheetName], $colStats);
                    if ($this->stepByStep) {
                        $this->rowDebug['rules'] = MapRules::$lastApplied;
                        $this->rowDebug['skip_rule'] = $this->rowSkipRuleInfo;
                        $this->rowDebug['skip_value'] = $this->rowSkipRuleValue;
                    }
                    // правило удаления элемента проверяем ПЕРВЫМ: правило
                    // TYPE=delete тоже выставляет флаг пропуска строки
                    // (MapRules::processRules), поэтому проверка rowSkipRules
                    // раньше делала удаление недостижимым
                    if ($this->rowDeleteRules) {
                        // проверка по хешу вычисленных значений — после применения настроек правил
                        $itemHash = $this->rowHash($item);
                        return $this->deleteRowByRule(
                            $id, $settings, $sheetName, $rowNum, $processed,
                            $item, $rowHashRaw, $itemHash, $entityId,
                            (array)$row, $activeSheets[$sheetName], $colStats, $sheetTotals,
                            $memStart, $memPeakStart, $bytesRead, $percent, $fileSize
                        );
                    }
                    if ($this->rowSkipRules) {
                        // строка пропущена по правилам сопоставления — не обрабатываем;
                        // маркер HASH_SKIP_MARK отличает такой пропуск от «висячего»
                        // резерва хеша прерванной обработки (ELEMENT_ID='')
                        self::saveHashSafe($id, $rowHashRaw, self::HASH_SKIP_MARK, (string)$entityId);
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено по правилам', $item,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }
                    // хеш вычисленных значений строки (до поиска элемента) —
                    // используется как резерв/маркер в правилах; проверка по
                    // хешу финальных значений выполняется в processItem после
                    // сборки загрузки (поле может не применяться, а макросы
                    // #ENTITY_*# раскрываются только после поиска элемента)
                    $itemHash = $this->rowHash($item);
                    // резерв хеша ДО обработки: обрыв хита по таймауту после
                    // создания элемента не приведет к повторной обработке строки
                    self::saveHashSafe($id, $rowHashRaw, '', (string)$entityId);
                    try {
                        $item = $this->processItemSafe($item, $activeSheets[$sheetName], (int)($settings['IBLOCK_ID'] ?? 0), $logErrors, $id, $rowNum, $row, $colStats, $rowHashRaw, $itemHash);
                    } catch (SkipRowSignal $e) {
                        // управляемый пропуск: SKIP_IF_NOT_FOUND, правила skip/delete
                        // с макросом #ELEMENT_FOUND# (оцениваются только после поиска);
                        // хеши — с маркером skip, строка больше не обрабатывается
                        self::saveHashSafe($id, $rowHashRaw, self::HASH_SKIP_MARK, (string)$entityId);
                        // финальный хеш (после поиска) приоритетнее — значения полей
                        // могли измениться при раскрытии макросов #ENTITY_*#
                        $skipItemHash = $this->finalItemHash !== '' ? $this->finalItemHash : $itemHash;
                        if ($skipItemHash !== '') {
                            self::saveHashSafe($id, $skipItemHash, self::HASH_SKIP_MARK, (string)$entityId);
                        }
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            $e->statKey !== '' ? $e->statKey : 'Пропущено: элемент не найден', $item,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }
                    if ($item !== null) {
                        // хеши сохраняются только при успешной обработке строки
                        $elementId = (string)($item['element_id'] ?? '');
                        self::saveHashSafe($id, ($this->finalItemHash !== '' ? $this->finalItemHash : $itemHash), $elementId, (string)$entityId);
                        self::saveHashSafe($id, $rowHashRaw, $elementId, (string)$entityId);
                    } else {
                        // ошибка обработки: снимаем резерв хеша
                        self::deleteHashSafe($id, $rowHashRaw);
                        // ретрай: до 3 попыток на строку, затем пропускаем с сохранением курсора
                        $retry = (int)($state['RETRY'] ?? 0) + 1;
                        if ($retry >= 3) {
                            Logger::error('Строка пропущена после 3 неудачных попыток', ['profile_id' => $id, 'row_num' => $rowNum, 'item' => $item, 'raw_row' => $row]);
                            return $this->skipRow(
                                $id, $settings, $sheetName, $rowNum, 0, $processed,
                                'Ошибки обработки', null,
                                $memStart, $memPeakStart, $bytesRead, $percent,
                                $fileSize, 0, $colStats, $sheetTotals
                            );
                        }
                        // курсор не сдвигаем — строка будет повторена в следующем хите
                        $this->processStats['Текущий лист'] = $sheetName;
                        $this->processStats['Текущая строка'] = $rowNum;
                        $this->processStats['Обработано строк'] = $processed;
                        $this->saveState(
                            $id, $settings, $sheetName, $rowNum, 0,
                            $processed, $this->processStats, $this->processErrors, false, $colStats, $sheetTotals, $retry
                        );
                        return $this->buildResponse($processed, false, null, $this->processErrors, $this->processStats, $memStart, $memPeakStart, $bytesRead, $percent, $fileSize, 0, $memEnd ?? 0, $memPeakEnd ?? 0);
                    }
                }
            } else {
                // === XLSX/ODS: пошаговое чтение текущей строки активного листа ===
                // XLSX — через FastXlsReader (курсор живет в рамках хита, между
                // запросами позиция восстанавливается из состояния профиля),
                // ODS и битые файлы — через ReadFilter (readSheetRow)
                $fast = null;
                $sheetIndex = -1;
                // тип файла: явный FILE_EXT либо расширение пути (baseExt —
                // расширение БЕЗ учета FILE_EXT, для .gz-снимков кеша)
                $ext = ProfilesHelper::fileExtOf($absPath, $settings);
                $baseExt = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $isXml = in_array($ext, ['xml', 'yml'], true);
                $isJson = $ext === 'json';
                if ($ext === 'xlsx') {
                    try {
                        $fast = $this->getFastReader($absPath);
                        // идентификация листа строго по коду (SHEET1/SHEET2/...), имя файла не используется
                        $sheetIndex = $this->getSheetIndexByCode($sheetName);
                        if ($sheetIndex < 0 || $sheetIndex >= count($fast->getSheets())) $fast = null;
                    } catch (\Throwable $e) {
                        $fast = null; // битый/нестандартный файл — фолбэк на ReadFilter
                    }
                } elseif ($isXml || $isJson) {
                    // XML/YML/JSON: листы — пути до повторяющихся элементов из настроек профиля
                    try {
                        $xmlPaths = [];
                        foreach ((array)($settings['XML_PATHS'] ?? []) as $xmlPath) {
                            if (is_array($xmlPath) && !empty($xmlPath['PATH'])) {
                                $xmlPaths[] = (string)$xmlPath['PATH'];
                            } elseif (is_string($xmlPath) && $xmlPath !== '') {
                                $xmlPaths[] = $xmlPath;
                            }
                        }
                        $xmlFast = $isJson
                            ? $this->getFastJsonReader($absPath, $xmlPaths)
                            : $this->getFastXmlReader($absPath, $xmlPaths);
                        $sheetIndex = $this->getSheetIndexByCode($sheetName);
                        if ($sheetIndex < 0 || $sheetIndex >= count($xmlFast->getSheets())) {
                            $fast = null;
                        } else {
                            $fast = $xmlFast;
                        }
                    } catch (\Throwable $e) {
                        $fast = null;
                    }
                }

                while (isset($activeSheets[$sheetName])) {
                    $sheetRealName = (string)($activeSheets[$sheetName]['NAME'] ?? $sheetName);
                    $data = null;
                    $actualRow = $rowNum;
                    $totalRows = 0;

                    if ($fast) {
                        // (пере)открытие листа с сохраненной позиции (при смене листа)
                        if ($this->fastSheetKey !== $sheetName) {
                            // XML-ридер открывается на байтовой позиции курсора
                            // (PROCESS.XML_POS — байт после закрывающего тега
                            // последнего обработанного элемента листа)
                            $fast->setState([
                                'sheetIndex' => $sheetIndex,
                                'lastRow' => $rowNum - 1,
                                'bytePos' => (int)($state['XML_POS'][$sheetName] ?? 0),
                                'done' => false,
                            ]);
                            $__perfOpen0 = microtime(true);
                            if (!$fast->openSheet($sheetIndex)) {
                                $fast = null;
                            } else {
                                // выравнивание строк под порядок колонок листа —
                                // один раз при открытии листа, не на каждую строку
                                if ($fast instanceof FastXmlReader || $fast instanceof FastJsonReader) {
                                    $fast->setKeys($this->sheetXmlKeys($activeSheets[$sheetName]));
                                }
                                $this->fastSheetKey = $sheetName;
                            }
                            self::perfAccum('open_sheet', microtime(true) - $__perfOpen0);
                        }
                    }

                    if ((int)($state['RETRY'] ?? 0) > 0 && $this->csvPendingRow !== null) {
                        // ретрай ошибочной строки: читатель уже ушел на следующую
                        // строку — повторно обрабатываем буферизованную строку
                        $data = $this->csvPendingRow;
                        $actualRow = $rowNum;
                        $totalRows = (int)($sheetTotals[$sheetName] ?? 0);
                    } elseif ($fast) {
                        // пошаговое чтение следующей строки открытого листа
                        $__perfT0 = microtime(true);
                        $next = $fast->readNextRow();
                        self::perfAccum('read_row', microtime(true) - $__perfT0);
                        $actualRow = (int)$next['row'];
                        $data = $next['data'];
                        // байтовая позиция после последнего прочитанного элемента
                        // (коммитится в XML_POS только при сдвиге курсора строки)
                        $this->xmlPosPending = (int)($fast->getState()['bytePos'] ?? 0);
                        $this->xmlPosSheet = $sheetName;
                        // итоговое количество строк листа: известное значение из
                        // состояния (SHEET_TOTALS, файл — снимок, не меняется)
                        // не пересчитывается — getTotalRows делает полный скан
                        // файла на каждый хит (на больших XML время хита уходит
                        // на скан, а не на обработку строк)
                        $totalRows = (int)($sheetTotals[$sheetName] ?? 0);
                        if ($totalRows <= 0) {
                            $__perfT0 = microtime(true);
                            $totalRows = $fast->getTotalRows($sheetIndex);
                            self::perfAccum('total_rows', microtime(true) - $__perfT0);
                        }
                        $bytesRead += $fileSize;
                    } elseif ($isXml || $isJson) {
                        // XML/YML/JSON-ридер не открылся (лист дочитан/пуст) — фолбэк
                        // на PhpSpreadsheet не имеет смысла: считаем лист дочитанным
                        $data = null;
                        $actualRow = $rowNum;
                        $totalRows = 0;
                    } else {
                        $__perfT0 = microtime(true);
                        $row = $this->readSheetRow($absPath, $this->getSheetIndexByCode($sheetName), $rowNum);
                        self::perfAccum('read_row', microtime(true) - $__perfT0);
                        $bytesRead += (int)($row['bytes'] ?? 0);
                        $data = $row['data'];
                        $actualRow = $rowNum;
                        $totalRows = (int)($row['totalRows'] ?? 0);
                    }

                    // известное итоговое количество строк листа — для расчета процентов
                    if ($totalRows > 0) {
                        $sheetTotals[$sheetName] = $totalRows;
                    }
                    $percent = $this->progressPercent($activeSheets, $sheetName, $actualRow, $sheetTotals);

                    if ($data === null) {
                        // строки закончились — следующий лист
                        $nextName = $this->nextSheetName($activeSheets, $sheetName);
                        if ($nextName === '') {
                            $done = true;
                            $this->csvPendingRow = null;
                            break;
                        }
                        $sheetName = $nextName;
                        $rowNum = $isPathsFile
                            ? 1
                            : max(1, (int)($activeSheets[$sheetName]['HEADER_ROW'] ?? 1)) + 1;
                        // форсируем переоткрытие нового листа на следующей итерации
                        $this->fastSheetKey = '';
                        $this->csvPendingRow = null;
                        continue;
                    }
                    // буферизация строки для ретрая при ошибке обработки
                    $this->csvPendingRow = $data;

                    // фактический номер строки из файла (может отличаться при пропусках)
                    if ($actualRow > $rowNum) {
                        // строки с номерами rowNum..actualRow-1 отсутствуют в XML листа
                        // (полностью пустые — Excel их не сохраняет); учитываем в
                        // статистике пустых строк, чтобы номера в логе не выглядели "потерей"
                        $gap = $actualRow - $rowNum;
                        $this->processStats['Пропущено пустых строк'] = (int)($this->processStats['Пропущено пустых строк'] ?? 0) + $gap;
                        Logger::debug(
                            'Строки отсутствуют в XML листа (полностью пустые)',
                            [
                                'profile_id' => $id,
                                'sheet' => $sheetName,
                                'from_row' => $rowNum,
                                'to_row' => $actualRow - 1,
                                'count' => $gap,
                            ]
                        );
                    }
                    $rowNum = $actualRow;
                    // техническая информация строки: значения колонок из файла (режим «по одному элементу»)
                    if ($this->stepByStep) {
                        $this->rowDebug = ['raw' => $this->rowDebugRaw($data, $activeSheets[$sheetName])];
                    }

                    if ($this->isEmptyRow($data)) {
                        // пустая строка — вообще не обрабатываем, только сдвигаем курсор
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено пустых строк', null,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }

                    // проверка по хешу строки файла — в момент загрузки строки
                    // (опция HASH_USED_COLUMNS: хеш только по задействованным колонкам)
                    $rowHashRaw = $this->hashUsedColumns
                        ? $this->usedRowHash($data, $activeSheets[$sheetName])
                        : $this->rowHash((array)$data);
                    if ($this->hashType !== 'no'
                        && $this->checkHashSafe($id, $rowHashRaw)
                        && $this->isHashFinal($id, $rowHashRaw)) {
                        $this->touchHashElement($id, $rowHashRaw, $settings);
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено по хешу', null,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }

                    // дерево категорий: колонка categoryId заменяется на путь
                    // ('|' — вложенность, ';' — множественность), синтетическая
                    // колонка category_path (добавлена на этапе настроек листов)
                    // заполняется путем(ями) из id строки. Хеш строки считается
                    // по сырому значению (id) — смена названий разделов не
                    // перезапускает импорт строк
                    $this->rowCategoryPath = '';
                    if ($isXmlFile && $this->catTree) {
                        $this->rowCategoryPath = $this->buildCategoryPathValue(
                            (string)($data[$this->colIndexByHeader($activeSheets[$sheetName], 'categoryid')] ?? ''),
                            $sheetName
                        );
                        $data = $this->applyCategoryPath($data, $sheetName, $activeSheets[$sheetName]);
                        $cpIndex = $this->colIndexByHeader($activeSheets[$sheetName], 'categorypath');
                        if ($cpIndex >= 0 && array_key_exists($cpIndex, $data)) {
                            $data[$cpIndex] = $this->rowCategoryPath;
                        }
                    }

                    $this->rowSkipRules = false;
                    $this->rowDeleteRules = false;
                    MapRules::$lastUnset = false;
                    // #ELEMENT_FOUND# не должен тянуться с предыдущей строки:
                    // в полной сборке он всегда «не найден», реальное значение
                    // станет известно в processItem после поиска элемента
                    $this->itemElementFound = 'N';
                    // данные найденного элемента сбрасываются: макросы #ENTITY_*#
                    // до поиска — пусто, реальное значение появится в processItem
                    $this->itemEntityData = null;
                    $this->rowSkipRuleInfo = null;
                    MapRules::$lastSkip = false;
                    MapRules::$lastSkipRule = null;
                    MapRules::$lastSkipValue = null;
                    MapRules::$lastDelete = false;
                    MapRules::$lastApplied = [];
                    $item = $this->buildItem($entityId, $data, $activeSheets[$sheetName], $colStats);
                    // путь(и) категорий по id из categoryId — отдельным ключом item
                    if ($this->rowCategoryPath !== '') {
                        $item['categoryPath'] = $this->rowCategoryPath;
                    }
                    if ($this->stepByStep) {
                        $this->rowDebug['rules'] = MapRules::$lastApplied;
                        $this->rowDebug['skip_rule'] = $this->rowSkipRuleInfo;
                        $this->rowDebug['skip_value'] = $this->rowSkipRuleValue;
                    }
                    // правило удаления элемента проверяем ПЕРВЫМ: правило
                    // TYPE=delete тоже выставляет флаг пропуска строки
                    // (MapRules::processRules), поэтому проверка rowSkipRules
                    // раньше делала удаление недостижимым
                    if ($this->rowDeleteRules) {
                        // проверка по хешу вычисленных значений — после применения настроек правил
                        $itemHash = $this->rowHash($item);
                        return $this->deleteRowByRule(
                            $id, $settings, $sheetName, $rowNum, $processed,
                            $item, $rowHashRaw, $itemHash, $entityId,
                            $data, $activeSheets[$sheetName], $colStats, $sheetTotals,
                            $memStart, $memPeakStart, $bytesRead, $percent, $fileSize
                        );
                    }
                    if ($this->rowSkipRules) {
                        // строка пропущена по правилам сопоставления — не обрабатываем;
                        // сохраняем хеш (как в CSV) с маркером HASH_SKIP_MARK
                        self::saveHashSafe($id, $rowHashRaw, self::HASH_SKIP_MARK, (string)$entityId);
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            'Пропущено по правилам', $item,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }
                    // хеш вычисленных значений строки (до поиска элемента) —
                    // используется как резерв/маркер в правилах; проверка по
                    // хешу финальных значений выполняется в processItem после
                    // сборки загрузки (поле может не применяться, а макросы
                    // #ENTITY_*# раскрываются только после поиска элемента)
                    $itemHash = $this->rowHash($item);
                    // резерв хеша ДО обработки: обрыв хита по таймауту после
                    // создания элемента не приведет к повторной обработке строки
                    self::saveHashSafe($id, $rowHashRaw, '', (string)$entityId);
                    try {
                        $item = $this->processItemSafe($item, $activeSheets[$sheetName], (int)($settings['IBLOCK_ID'] ?? 0), $logErrors, $id, $rowNum, $data, $colStats, $rowHashRaw, $itemHash);
                    } catch (SkipRowSignal $e) {
                        // управляемый пропуск: SKIP_IF_NOT_FOUND, правила skip/delete
                        // с макросом #ELEMENT_FOUND# (оцениваются только после поиска);
                        // хеши — с маркером skip, строка больше не обрабатывается
                        self::saveHashSafe($id, $rowHashRaw, self::HASH_SKIP_MARK, (string)$entityId);
                        // финальный хеш (после поиска) приоритетнее — значения полей
                        // могли измениться при раскрытии макросов #ENTITY_*#
                        $skipItemHash = $this->finalItemHash !== '' ? $this->finalItemHash : $itemHash;
                        if ($skipItemHash !== '') {
                            self::saveHashSafe($id, $skipItemHash, self::HASH_SKIP_MARK, (string)$entityId);
                        }
                        return $this->skipRow(
                            $id, $settings, $sheetName, $rowNum, 0, $processed,
                            $e->statKey !== '' ? $e->statKey : 'Пропущено: элемент не найден', $item,
                            $memStart, $memPeakStart, $bytesRead, $percent,
                            $fileSize, 0, $colStats, $sheetTotals
                        );
                    }
                    if ($item !== null) {
                        // фиксируем оба хеша: сырой строки и собранных значений
                        $elementId = (string)($item['element_id'] ?? '');
                        self::saveHashSafe($id, $rowHashRaw, $elementId, (string)$entityId);
                        self::saveHashSafe($id, ($this->finalItemHash !== '' ? $this->finalItemHash : $itemHash), $elementId, (string)$entityId);
                    } else {
                        // ошибка обработки: снимаем резерв хеша
                        self::deleteHashSafe($id, $rowHashRaw);                        // ретрай: до 3 попыток на строку, затем пропускаем с сохранением курсора
                        $retry = (int)($state['RETRY'] ?? 0) + 1;
                        if ($retry >= 3) {
                            Logger::error('Строка пропущена после 3 неудачных попыток', ['profile_id' => $id, 'row_num' => $rowNum, 'item' => $item, 'raw_row' => $data]);
                            return $this->skipRow(
                                $id, $settings, $sheetName, $rowNum, 0, $processed,
                                'Ошибки обработки', null,
                                $memStart, $memPeakStart, $bytesRead, $percent,
                                $fileSize, 0, $colStats, $sheetTotals
                            );
                        }
                        // курсор не сдвигаем — строка будет повторена в следующем хите
                        $this->processStats['Текущий лист'] = $sheetName;
                        $this->processStats['Текущая строка'] = $rowNum;
                        $this->processStats['Обработано строк'] = $processed;
                        $this->saveState(
                            $id, $settings, $sheetName, $rowNum, 0,
                            $processed, $this->processStats, $this->processErrors, false, $colStats, $sheetTotals, $retry
                        );
                        return $this->buildResponse($processed, false, null, $this->processErrors, $this->processStats, $memStart, $memPeakStart, $bytesRead, $percent, $fileSize, 0, $memEnd ?? 0, $memPeakEnd ?? 0);
                    }
                    break;
                }
            }

            // замер памяти сразу после обработки строки, пока объекты не уничтожены GC
            $memEnd = memory_get_usage(false);
            $memPeakEnd = memory_get_peak_usage(false);

            if ($done) {
                $this->processStats['Обработано строк'] = $processed;
                $this->processStats['Прогресс файла, %'] = 100;
                $this->saveState($id, $settings, '', 0, 0, $processed, $this->processStats, $this->processErrors, true, $colStats, $sheetTotals);
                $resp = $this->buildResponse($processed, true, null, $this->processErrors, $this->processStats, $memStart, $memPeakStart, $bytesRead, 100.0, $fileSize, $fileSize, $memEnd, $memPeakEnd);
                $resp['columns'] = $this->topColumns($colStats);
                return $resp;
            }

            $processed++;
            $this->processStats['Текущий лист'] = $sheetName;
            $this->processStats['Текущая строка'] = $rowNum;
            $this->processStats['Обработано строк'] = $processed;
            $this->processStats['Прогресс файла, %'] = floor($percent * 10) / 10;

            $this->commitXmlPos($sheetName);
            $this->saveState(
                $id, $settings, $sheetName, $rowNum + 1,
                0,
                $processed, $this->processStats, $this->processErrors, false, $colStats, $sheetTotals
            );

            $resp = $this->buildResponse($processed, false, $item, $this->processErrors, $this->processStats, $memStart, $memPeakStart, $bytesRead, $percent, $fileSize, 0, $memEnd, $memPeakEnd);
            $resp['columns'] = $this->topColumns($colStats);
            // техническая информация обработанной строки (только режим «по одному элементу»)
            if ($this->stepByStep) {
                $this->rowDebug['sheet'] = $sheetName;
                $this->rowDebug['row'] = $rowNum;
                $resp['debug'] = $this->rowDebug;
            }
            return $resp;
        } catch (\Throwable $e) {
            // запись ошибки в журнал Битрикса (если включена опция)
            if ($logErrors) {
                \CEventLog::Add([
                    'SEVERITY' => 'ERROR',
                    'AUDIT_TYPE_ID' => 'AWZ_ALLXLS_PIMPORT_'.$id,
                    'MODULE_ID' => 'awz.allxls',
                    'ITEM_ID' => 'PROFILE#'.$id.' ROW#'.$rowNum,
                    'DESCRIPTION' => $e->getMessage().' ('.$e->getFile().':'.$e->getLine().')',
                ]);
            }
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Формирование ответа со статистикой памяти.
     */
    protected function buildResponse(
        int $processed, bool $done, ?array $item, array $errors,
        array $stats, int $memStart, int $memPeakStart, int $bytesRead,
        float $percent = 0.0, int $totalBytes = 0, int $bytesDone = 0,
        int $memEnd = 0, int $memPeakEnd = 0
    ): array {
        // замеры делаются в момент реальной обработки строки (до уничтожения объектов GC),
        // сюда передаются уже снятые значения; fallback — снять сейчас, если не переданы
        if ($memEnd <= 0) {
            $memEnd = memory_get_usage(false);
        }
        if ($memPeakEnd <= 0) {
            $memPeakEnd = memory_get_peak_usage(false);
        }

        $stats['Память: на старте, Мб'] = round($memStart / 1048576, 2);
        $stats['Память: на конце, Мб'] = round($memEnd / 1048576, 2);
        $stats['Память: пик за запрос, Мб'] = round(($memPeakEnd - $memPeakStart) / 1048576, 2);
        $stats['Память: пик всего, Мб'] = round($memPeakEnd / 1048576, 2);
        $stats['Прочитано байт за запрос'] = $bytesRead;

        return [
            'processed' => $processed,
            'total' => null, // финальное количество неизвестно при потоковом чтении
            'done' => $done,
            'item' => $item, // JSON обрабатываемого элемента
            'errors' => $errors,
            'stats' => $stats,
            // прогресс чтения файла: проценты (по байтам для CSV, по строкам листа для XLSX/ODS)
            'percent' => floor($percent * 10) / 10,
            'totalBytes' => $totalBytes,
            'bytesRead' => $bytesDone,
            'memory' => [
                'start' => $memStart,
                'end' => $memEnd,
                'peak_request' => $memPeakEnd - $memPeakStart,
                'peak_total' => $memPeakEnd,
                'bytes_read' => $bytesRead,
            ],
        ];
    }

    /**
     * Пошаговый читатель XLSX (один экземпляр на хит).
     * Курсор чтения сохраняется между вызовами importStep внутри хита,
     * между запросами позиция восстанавливается из состояния профиля.
     *
     * @param string $absPath абсолютный путь к файлу
     * @return FastXlsReader
     * @throws \Throwable при ошибке открытия zip/структуры книги
     */
    /**
     * Создает рабочую копию файла источника в temp (снимок на старт импорта):
     * живой файл может измениться в процессе многохитового импорта.
     *
     * @param int $id ID профиля
     * @param string $srcPath путь к исходному файлу
     * @return string путь к копии или '' при ошибке
     */
    protected function ensureSourceCopy(int $id, string $srcPath): string
    {
        try {
            // 1. Принудительно приводим путь к абсолютному, если передан относительный
            $docRoot = \Bitrix\Main\Application::getDocumentRoot();
            if (strpos($srcPath, $docRoot) !== 0) {
                $srcPath = $docRoot . '/' . ltrim($srcPath, '/');
            }

            // 2. Проверяем существование исходного файла
            $srcFile = new \Bitrix\Main\IO\File($srcPath);
            if (!$srcFile->isExists()) {
                return '';
            }

            // 3. Формируем путь для временной папки и создаем её
            $tmpDirPath = $docRoot . '/upload/tmp/awz.allxls/';
            \Bitrix\Main\IO\Directory::createDirectory($tmpDirPath);

            // 4. Генерируем уникальное имя целевого файла
            $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            $randomHash = substr(md5($srcPath . microtime(true) . mt_rand()), 0, 8);
            $tmpFilePath = $tmpDirPath . 'awz_allxls_' . $id . '_' . $randomHash . ($ext !== '' ? '.' . $ext : '.tmp');

            // 5. Безопасное потоковое копирование без загрузки всего файла в RAM
            $srcStream = @fopen($srcFile->getPhysicalPath(), 'rb');
            $dstStream = @fopen($tmpFilePath, 'wb');

            if (!$srcStream || !$dstStream) {
                if ($srcStream) fclose($srcStream);
                if ($dstStream) fclose($dstStream);
                return '';
            }

            // Копируем поток в поток порциями
            $copyResult = stream_copy_to_stream($srcStream, $dstStream);

            fclose($srcStream);
            fclose($dstStream);

            if ($copyResult === false) {
                return '';
            }

            // 6. Финальная проверка, что копия создана успешно
            $tmpFile = new \Bitrix\Main\IO\File($tmpFilePath);
            if (!$tmpFile->isExists()) {
                return '';
            }

            return $tmpFilePath;

        } catch (\Throwable $e) {
            Logger::error('Не удалось создать рабочую копию файла импорта', [
                'profile_id' => $id,
                'src' => $srcPath,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }



    /**
     * Удаляет рабочую копию файла источника.
     *
     * @param string $tmpFile путь к копии
     * @return void
     */
    protected function deleteSourceCopy(string $tmpFile): void
    {
        try {
            if ($tmpFile === '') {
                return;
            }
            $file = new \Bitrix\Main\IO\File($tmpFile);
            if ($file->isFileExists()) {
                $file->delete();
            }
        } catch (\Throwable $e) {
            // удалить копию не удалось — не мешает импорту
        }
    }

    protected function getFastReader(string $absPath): FastXlsReader
    {
        if ($this->fastReader === null) {
            $this->fastReader = new FastXlsReader($absPath);
        }
        return $this->fastReader;
    }

    /**
     * Ленивое создание пошагового читателя XML/YML (живет в рамках одного хита)
     *
     * @param string $absPath абсолютный путь к файлу
     * @param array $xmlPaths пути до элементов (XML_PATHS)
     * @return FastXmlReader
     */
    protected function getFastXmlReader(string $absPath, array $xmlPaths): FastXmlReader
    {
        if ($this->fastXmlReader === null) {
            $this->fastXmlReader = new FastXmlReader($absPath, $xmlPaths);
        }
        return $this->fastXmlReader;
    }

    /**
     * Ленивое создание пошагового читателя JSON (живет в рамках одного хита)
     *
     * @param string $absPath абсолютный путь к файлу
     * @param array $jsonPaths пути до элементов (XML_PATHS)
     * @return FastJsonReader
     */
    protected function getFastJsonReader(string $absPath, array $jsonPaths): FastJsonReader
    {
        if ($this->fastJsonReader === null) {
            $this->fastJsonReader = new FastJsonReader($absPath, $jsonPaths);
        }
        return $this->fastJsonReader;
    }

    /**
     * Ключи XML-колонок листа (HEADER) по индексам колонок —
     * для выравнивания строк ридера под порядок колонок sheetConfig
     */
    protected function sheetXmlKeys(array $sheetConfig): array
    {
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        $keys = [];
        foreach ($columns as $key => $col) {
            $index = (int)filter_var((string)$key, FILTER_SANITIZE_NUMBER_INT);
            $keys[$index] = (string)($col['HEADER'] ?? '');
        }
        if (!$keys) return [];
        ksort($keys);
        $max = max(array_keys($keys));
        $result = [];
        for ($i = 0; $i <= $max; $i++) {
            $result[$i] = (string)($keys[$i] ?? '');
        }
        return $result;
    }

    /**
     * Путь(и) категорий для item.categoryPath по сырому значению колонки
     * categoryId: id (или список id через ,;) → путь(и) '|', склеенные ';'.
     * Значение уже является путем (содержит '|') — возвращается как есть
     * (ретрай буферизованной строки).
     */
    protected function buildCategoryPathValue(string $value, string $sheetName): string
    {
        $value = trim($value);
        if ($value === '') return '';
        // лист категорий исключается
        if ($this->catTreePath !== ''
            && ProfilesHelper::normColKey($sheetName) === ProfilesHelper::normColKey($this->catTreePath)) {
            return '';
        }
        if (mb_strpos($value, '|') !== false) {
            return $value; // уже путь (буфер ретрая)
        }
        if (!preg_match('/^[\d\s;,]+$/u', $value)) {
            return ''; // не список id — не трогаем
        }
        $paths = [];
        foreach (preg_split('/[;,]+/u', $value) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (!array_key_exists($part, $this->catPathCache)) {
                $this->catPathCache[$part] = ProfilesHelper::categoryPathById($this->catTree, $part);
            }
            $path = $this->catPathCache[$part];
            if ($path !== '') $paths[] = $path;
        }
        return $paths ? implode(';', $paths) : '';
    }

    /**
     * Индекс колонки по нормализованному заголовку (точное совпадение
     * или суффикс — ключи XML могут иметь префикс родительского тега,
     * например offer_categoryid)
     */
    protected function colIndexByHeader(array $sheetConfig, string $normHeader): int
    {
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        $len = mb_strlen($normHeader);
        foreach ($columns as $key => $col) {
            $index = (int)filter_var((string)$key, FILTER_SANITIZE_NUMBER_INT);
            $norm = ProfilesHelper::normColKey((string)($col['HEADER'] ?? ''));
            if ($norm === $normHeader
                || ($len > 0 && mb_strlen($norm) > $len && mb_substr($norm, -$len) === $normHeader)) {
                return $index;
            }
        }
        return -1;
    }

    /**
     * Индекс колонки categoryId в листе (для замены id на путь категорий)
     */
    protected function categoryColIndex(array $sheetConfig): int
    {
        return $this->colIndexByHeader($sheetConfig, 'categoryid');
    }

    /**
     * Замена id категории на путь из дерева разделов в XML-строке.
     * Лист категорий (categories->category) не обрабатывается — там id —
     * это собственные данные дерева.
     */
    protected function applyCategoryPath(array $row, string $sheetName, array $sheetConfig): array
    {
        // лист категорий исключается
        if ($this->catTreePath !== ''
            && ProfilesHelper::normColKey($sheetName) === ProfilesHelper::normColKey($this->catTreePath)) {
            return $row;
        }
        $colIndex = $this->categoryColIndex($sheetConfig);
        if ($colIndex < 0 || !array_key_exists($colIndex, $row)) {
            return $row;
        }
        $value = trim((string)$row[$colIndex]);
        if ($value === '') {
            return $row;
        }
        // множественные id: разделители ';' и ',' (повторяющиеся теги
        // categoryId ридер склеивает через ', ')
        $parts = preg_split('/[;,]+/u', $value);
        if ($parts === false) {
            return $row;
        }
        $result = [];
        $changed = false;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (!array_key_exists($part, $this->catPathCache)) {
                $this->catPathCache[$part] = ProfilesHelper::categoryPathById($this->catTree, $part);
            }
            $path = $this->catPathCache[$part];
            if ($path !== '') {
                $result[] = $path;
                $changed = true;
            } else {
                // id нет в дереве — остается как есть
                $result[] = $part;
            }
        }
        if ($changed) {
            $row[$colIndex] = implode(';', $result);
        }
        return $row;
    }

    /**
     * Индекс листа в файле по коду из профиля (SHEET1/SHEET2/...).
     * Код определяет порядковый номер листа: SHEETn -> индекс n-1.
     * Имя листа файла не используется.
     *
     * @param string $sheetCode код листа из настроек профиля
     * @return int индекс (0-based) или -1, если код не распознан
     */
    protected function getSheetIndexByCode(string $sheetCode): int
    {
        if (preg_match('/^SHEET(\d+)$/i', $sheetCode, $m)) {
            return max(0, (int)$m[1] - 1);
        }
        return -1;
    }

    /**
     * Чтение одной строки листа из XLSX/ODS с фильтром (в память попадает только нужная строка).
     *
     * @param string $absPath абсолютный путь к файлу
     * @param int $sheetIndex индекс листа (по коду SHEET1/SHEET2/... — порядковый номер в файле)
     * @param int $rowNum номер строки (1-based)
     * @return array{data: ?array, bytes: int, totalRows: int}
     */
    protected function readSheetRow(string $absPath, int $sheetIndex, int $rowNum): array
    {
        $fileSize = filesize($absPath);

        // === XLSX: быстрое чтение одной строки напрямую из zip (FastXlsReader::readRow) ===
        // без загрузки книги через PhpSpreadsheet — стрим проматывается до нужной строки
        // тип файла: явный FILE_EXT либо расширение пути
        $ext = ProfilesHelper::fileExtOf($absPath, $this->profileHitCache['SETTINGS'] ?? []);
        if ($ext === '') {
            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        }
        if ($ext === 'xlsx') {
            try {
                $fast = new FastXlsReader($absPath);
                if ($sheetIndex < 0 || $sheetIndex >= count($fast->getSheets())) {
                    return ['data' => null, 'bytes' => (int)$fileSize, 'totalRows' => 0];
                }
                return [
                    'data' => $fast->readRow($sheetIndex, $rowNum),
                    'bytes' => (int)$fileSize,
                    'totalRows' => $fast->getTotalRows($sheetIndex),
                ];
            } catch (\Throwable $e) {
                // битый/нестандартный файл — падаем на тяжелый путь ниже
            }
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($absPath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class($rowNum) implements IReadFilter {
            protected int $row;
            public function __construct(int $row) { $this->row = $row; }
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row === $this->row;
            }
        });

        $spreadsheet = $reader->load($absPath);
        // лист выбирается строго по индексу (коду), без использования имени
        $sheet = ($sheetIndex >= 0 && $sheetIndex < $spreadsheet->getSheetCount())
            ? $spreadsheet->getSheet($sheetIndex) : null;

        $data = null;
        $totalRows = 0;
        if ($sheet) {
            $totalRows = (int)$sheet->getHighestRow();
            foreach ($sheet->getRowIterator($rowNum, $rowNum) as $rowObj) {
                $cellIterator = $rowObj->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                $data = [];
                foreach ($cellIterator as $cell) {
                    $data[] = (string)$cell->getValue();
                }
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);

        return ['data' => $data, 'bytes' => (int)$fileSize, 'totalRows' => $totalRows];
    }

    /**
     * Расчет процента обработки файла для XLSX/ODS по счетчику строк листов.
     * Пройденные листы учитываются полностью (по известным итоговым количествам строк),
     * текущий лист — пропорционально номеру строки.
     *
     * @param array $activeSheets активные листы профиля
     * @param string $currentSheet текущий лист
     * @param int $rowNum текущая строка на листе
     * @param array $sheetTotals известные итоговые количества строк по листам
     * @return float процент 0..100
     */
    protected function progressPercent(array $activeSheets, string $currentSheet, int $rowNum, array $sheetTotals): float
    {
        $order = array_keys($activeSheets);
        $curIdx = array_search($currentSheet, $order, true);
        if ($curIdx === false) return 0.0;

        $doneRows = 0;
        $totalRows = 0;
        foreach ($order as $idx => $name) {
            $t = (int)($sheetTotals[$name] ?? 0);
            if ($t <= 0) continue; // итоговое количество еще неизвестно
            if ($idx < $curIdx) {
                // лист уже пройден полностью
                $doneRows += $t;
                $totalRows += $t;
            } elseif ($idx === $curIdx) {
                $doneRows += min(max(0, $rowNum - 1), $t);
                $totalRows += $t;
            }
        }
        if ($totalRows <= 0) return 0.0;
        return min(100.0, $doneRows / $totalRows * 100);
    }

    /**
     * Имя следующего листа после $current (по порядку объявления).
     */
    protected function nextSheetName(array $sheets, string $current): string
    {
        $names = array_keys($sheets);
        $index = array_search($current, $names, true);
        if ($index === false) return '';
        return (string)($names[$index + 1] ?? '');
    }

    /**
     * Конвертация значений CSV-строки в UTF-8.
     * Файл может быть сохранен в windows-1251 или иной не-UTF-8 кодировке —
     * без конвертации Json::encode падает с "Malformed UTF-8 characters".
     *
     * @param array $row строка из CCSVData
     * @return array строка со значениями в UTF-8
     */
    protected function toUtf8Row(array $row): array
    {
        $siteCharset = \Bitrix\Main\Application::getInstance()->isUtfMode() ? 'UTF-8' : (string)\Bitrix\Main\Application::getInstance()->getContext()->getCulture()->getCharset();
        $result = [];
        foreach ($row as $key => $value) {
            $value = (string)$value;
            if ($value !== '' && !preg_match('//u', $value)) {
                $converted = false;
                if (function_exists('mb_convert_encoding')) {
                    // определяем исходную кодировку (по умолчанию windows-1251)
                    $from = 'windows-1251';
                    if (function_exists('mb_detect_encoding')) {
                        $detected = @mb_detect_encoding($value, ['UTF-8', 'windows-1251', 'ISO-8859-5', 'KOI8-R'], true);
                        if ($detected && $detected !== 'UTF-8') $from = $detected;
                    }
                    $tmp = @mb_convert_encoding($value, $siteCharset, $from);
                    if (is_string($tmp) && $tmp !== '' && preg_match('//u', $tmp)) {
                        $value = $tmp;
                        $converted = true;
                    }
                }
                if (!$converted && function_exists('iconv')) {
                    $tmp = @iconv('windows-1251', $siteCharset.'//IGNORE', $value);
                    if (is_string($tmp) && preg_match('//u', $tmp)) {
                        $value = $tmp;
                    }
                }
                // последняя страховка: убираем невалидные UTF-8 последовательности
                if (!preg_match('//u', $value) && function_exists('mb_convert_encoding')) {
                    $value = (string)@mb_convert_encoding($value, $siteCharset, 'UTF-8');
                }
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Проверка строки на пустоту (все значения пустые).
     */
    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') return false;
        }
        return true;
    }

    /**
     * Сборка элемента из строки: поле назначения (выбранное сопоставление) => значение.
     * Ключом результата выступает выбранное значение сопоставления (MAPS[i]),
     * а не индекс колонки. Если несколько сопоставлений указывают на одно и то
     * же поле, значения склеиваются через разделитель (DELIMETER из настроек
     * сопоставления, по умолчанию запятая), а не перезаписывают друг друга.
     * Накапливает статистику времени обработки по каждой колонке.
     *
     * Режим сборки $mode определяет, какие сопоставления обрабатываются:
     *  - 'all'    — все (проверка правил пропуска строки);
     *  - 'search' — только поля поиска элемента (SEARCH_USE = Y);
     *  - 'update' — поля для обновляемых элементов (IS_NEW != Y);
     *  - 'new'    — поля для новых элементов (IS_NEW != U).
     */
    protected function buildItem(string $entityId, array $row, array $sheetConfig, array &$colStats = [], string $mode = 'all'): array
    {
        $__perfT0 = microtime(true);
        $item = [];
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];

        // сырые значения строки по ключам колонок — для условий и макросов правил.
        // ВАЖНО: включая скрытые колонки (INCLUDE=N) — их значения должны быть
        // доступны в макросах #COL_<KEY># и условиях правил
        $rowValues = [];
        foreach ($columns as $key => $col) {
            $index = (int)filter_var($key, FILTER_SANITIZE_NUMBER_INT);
            $rowValues[$key] = (string)($row[$index] ?? '');
        }

        foreach ($columns as $key => $col) {
            if (($col['INCLUDE'] ?? 'Y') === 'N') continue;
            // правило пропуска сработало — оставшиеся колонки не обрабатываем;
            // в сборке поиска (mode=search) пропуски не обрывают цикл:
            // должны собраться ВСЕ поисковые поля (в т.ч. после колонки с
            // правилом skip/delete)
            if ($this->rowSkipRules && $mode !== 'search') break;
            $index = (int)filter_var($key, FILTER_SANITIZE_NUMBER_INT);
            $value = (string)($row[$index] ?? '');
            $t0 = microtime(true);
            // финальные значения колонки (поле назначения => результат) — для технической информации
            $colFinal = [];
            $ruleStart = count(MapRules::$lastApplied);
            $colRuleTexts = [];

            $maps = is_array($col['MAPS'] ?? null) ? $col['MAPS'] : [];
            $mapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];
            foreach ($maps as $mapIdx => $field) {
                if ($this->rowSkipRules && $mode !== 'search') break;
                $field = trim((string)$field);
                if ($field === '') continue;
                $settings = is_array($mapSettings[$mapIdx] ?? null) ? $mapSettings[$mapIdx] : [];
                // фильтрация сопоставлений по режиму сборки (поиск / новые / обновляемые)
                if (!$this->isMapAllowed($entityId, $col, $settings, $mode)) continue;
                $processed = $this->applyMaps($value, [
                    'MAPS' => [$field],
                    'MAP_SETTINGS' => [$settings],
                ], $rowValues, $key, $columns, (string)$entityId);
                //if ($processed === '') continue;

                // правило «не обрабатывать значение» (TYPE=unset): поле
                // исключается из загрузки — не попадает в элемент, строка
                // обрабатывается штатно (остальные поля без изменений)
                if (MapRules::$lastUnset) {
                    unset($item[$field]);
                    $colFinal[] = ['field' => $field, 'value' => '', 'unset' => true];
                    if ($this->stepByStep) {
                        for ($ri = $ruleStart, $rc = count(MapRules::$lastApplied); $ri < $rc; $ri++) {
                            $colRuleTexts[] = ['field' => $field, 'text' => $this->describeRule(MapRules::$lastApplied[$ri])];
                        }
                    }
                    $ruleStart = count(MapRules::$lastApplied);
                    continue;
                }

                //if (!array_key_exists($field, $item)) {
                    $item[$field] = $processed;
                //} else {
                    // одинаковый ключ: склеиваем значения, а не перезаписываем
                //    $dlmtr = (string)($settings['DELIMETER'] ?? ',');
                //    if ($dlmtr === '') $dlmtr = ',';
                //    $item[$field] .= $dlmtr . $processed;
                //}
                $colFinal[] = ['field' => $field, 'value' => $processed];
                // описания сработавших в этом сопоставлении правил
                // (только режим «по одному элементу»; привязка к полю
                // уже сделана в MapRules::applyMaps)
                if ($this->stepByStep) {
                    for ($ri = $ruleStart, $rc = count(MapRules::$lastApplied); $ri < $rc; $ri++) {
                        $colRuleTexts[] = ['field' => $field, 'text' => $this->describeRule(MapRules::$lastApplied[$ri])];
                    }
                }
                $ruleStart = count(MapRules::$lastApplied);
            }

            // техническая информация: результат обработки колонки
            // (только полная сборка и только в режиме «по одному элементу»)
            if ($mode === 'all' && $this->stepByStep && isset($this->rowDebug['raw'])) {
                $this->rowDebug['cols'][$key] = $colFinal;
                $this->rowDebug['rules_col'][$key] = $colRuleTexts;
            }

            if (!isset($colStats[$index])) $colStats[$index] = ['time' => 0.0, 'count' => 0];
            $colStats[$index]['time'] += microtime(true) - $t0;
            $colStats[$index]['count']++;
            // названия для статистики: заголовок колонки и поля назначения
            $letter = trim((string)($col['LETTER'] ?? ''));
            $header = trim((string)($col['HEADER'] ?? ''));
            $colStats[$index]['name'] = $letter !== '' && $header !== '' ? $letter . ': ' . $header : ($letter ?: $header);
            if ($maps) {
                $colStats[$index]['fields'] = implode(', ', array_map(static fn($f) => trim((string)$f), $maps));
            }
        }

        // значения всех макросов строки (режим «по одному элементу») — для отладки правил
        if ($mode === 'all' && $this->stepByStep && isset($this->rowDebug['raw'])) {
            $this->rowDebug['macros'] = MapRules::getMacrosValues(
                $columns, '', '', $rowValues,
                [
                    'element_found' => $this->itemElementFound,
                    'value_check' => ['iblock_id' => (int)$entityId, 'field' => ''],
                ]
            );
        }
        self::perfAccum('build_item', microtime(true) - $__perfT0);
        return $item;
    }

    /**
     * Безопасная обработка финального JSON строки: перехват исключений,
     * ведение счетчиков статистики и журналирование ошибок.
     * Счетчики, ошибки и хеши хранятся в защищенных свойствах объекта.
     *
     * @param array $item финальный JSON строки
     * @param array $sheetConfig настройки листа (COLUMNS с MAPS/MAP_SETTINGS)
     * @param int $iblockId ID инфоблока назначения (из настроек профиля)
     * @param bool $logErrors писать ошибки в журнал Битрикса
     * @param int $id ID профиля
     * @param int $rowNum номер обрабатываемой строки
     * @return array|null null при ошибке обработки
     */
    protected function processItemSafe(
        array $item, array $sheetConfig, int $iblockId = 0,
        bool $logErrors = false, int $id = 0, int $rowNum = 0, array $rawRow = [],
        array &$colStats = [], string $rowHashRaw = '', string $itemHash = ''
    ){
        $__perfT0 = microtime(true);
        try {
            $result = $this->processItem($item, $sheetConfig, $iblockId, $rawRow, $colStats, $rowHashRaw, $itemHash, $rowNum);
            self::perfAccum('process_item', microtime(true) - $__perfT0);
            return $result;
        } catch (SkipRowSignal $e) {
            // управляемый пропуск (SKIP_IF_NOT_FOUND) — не ошибка,
            // обработка выполняется в importStep
            throw $e;
        } catch (\Throwable $e) {
            // счетчик ошибок обработки
            $this->processStats['Ошибки'] = (int)($this->processStats['Ошибки'] ?? 0) + 1;
            $errText = 'Строка '.$rowNum.': '.$e->getMessage();
            if(count($this->processErrors)<10){
                $this->processErrors[] = $errText;
            }
            // полный контекст ошибки в PSR-лог: строка не обработана, дедуп не сохранен
            Logger::error(
                'Строка не обработана: '.$e->getMessage(),
                [
                    'profile_id' => $id,
                    'row_num' => $rowNum,
                    'file' => $e->getFile().':'.$e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'item' => $item,
                    'raw_row' => $rawRow,
                ]
            );
            if ($logErrors) {
                \CEventLog::Add([
                    'SEVERITY' => 'ERROR',
                    'AUDIT_TYPE_ID' => 'AWZ_ALLXLS_PIMPORT_'.$id,
                    'MODULE_ID' => 'awz.allxls',
                    'ITEM_ID' => 'PROFILE#'.$id.' ROW#'.$rowNum,
                    'DESCRIPTION' => $e->getMessage().' ('.$e->getFile().':'.$e->getLine().')',
                ]);
            }
            return null;
        }
    }

    /**
     * Обработка финального JSON строки.
     * На вход приходит собранный элемент (алиас => значение) и параметры
     * сопоставления колонок листа. Выполняется:
     *  - пропуск по хешу строки (уже обрабатывалась ранее);
     *  - поиск существующего элемента инфоблока по фильтру;
     *  - создание нового элемента или обновление существующего.
     *
     * Счетчики статистики (хранятся в защищенных свойствах объекта):
     *  - 'Ошибки' — ошибки обработки;
     *  - 'Добавлено новых' — созданные элементы;
     *  - 'Обновлено' — обновленные элементы;
     *  - 'Пропущено по хешу' — дубли строк, пропущенные по совпадению хеша;
     *  - 'Пропущено пустых строк' — пустые строки файла (не обрабатываются вообще);
     *  - 'Пропущено по правилам' — строки, пропущенные правилами сопоставления (TYPE=skip).
     *
     * @param array $item финальный JSON строки
     * @param array $sheetConfig настройки листа (COLUMNS с MAPS/MAP_SETTINGS)
     * @param int $entityId ID инфоблока назначения (из настроек профиля)
     * @return array
     */
    protected function processItem(
        array $item, array $sheetConfig, string $entityId = '', array $rawRow = [],
        array &$colStats = [], string $rowHashRaw = '', string $itemHash = '', int $rowNum = 0
    ): array {
        // сброс финального хеша прошлой строки: если в этой строке
        // хеш не будет вычислен, в importStep сохранится резервный itemHash
        $this->finalItemHash = '';
        // параметры сопоставления по каждой колонке
        $maps = [];
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        foreach ($columns as $key => $col) {
            if (($col['INCLUDE'] ?? 'Y') === 'N') continue;
            $index = (int)filter_var($key, FILTER_SANITIZE_NUMBER_INT);
            $maps[$index] = [
                'MAPS' => $col['MAPS'] ?? [],
                'MAP_SETTINGS' => $col['MAP_SETTINGS'] ?? [],
            ];
        }

        $elementId = 0;
        $existing = null;

        // сервис работы с инфоблоками (через ServiceLocator)
        /** @var \Awz\Allxls\Services\iblock $entityService */
        $entityService = $this->resolveService($this->profileData['SETTINGS']['DEST_TYPE']);
        // сохраняем флаг пропуска строки: повторная сборка элемента ниже
        // не должна влиять на решение о пропуске, принятое в importStep
        $skipBackup = $this->rowSkipRules;
        // === этап 1: сборка только поисковых полей и поиск существующего элемента ===
        $searchItem = $this->buildItem($entityId, $rawRow, $sheetConfig, $colStats, 'search');
        // сборка фильтра отбора существующего элемента инфоблока
        // из поисковых полей и настроек поиска колонок (MAP_SEARCH_USE / MAP_SEARCH_MODE)
        $filter = $entityService->buildFilter($entityId, $searchItem, $sheetConfig, $entityId);
        if (empty($filter)) {
            // нет полей поиска — элемент гарантированно будет добавлен как новый
            Logger::warning(
                'Пустой фильтр поиска: SEARCH_USE не настроен ни у одной колонки, элемент будет добавлен как новый',
                [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'search_item' => $searchItem,
                ]
            );
        }
        // поиск существующего элемента по собранному фильтру
        // (замер времени — здесь, в импорт-классе)
        $svc = $entityService;
        $tFind = 0.0;
        $existing = null;
        if (!empty($filter)) {
            $tFind0 = microtime(true);
            $existing = $entityService->findElement($entityId, $filter);
            $tFind = microtime(true) - $tFind0;
            $this->svcTimeStats['find']['time'] += $tFind;
            $this->svcTimeStats['find']['count']++;
            $tFind = round($tFind, 4);
        }
        // признак «элемент найден» — для макроса #ELEMENT_FOUND# в правилах
        // (актуален в сборках этапов поиска/сохранения)
        $this->itemElementFound = $existing ? 'Y' : 'N';
        // правила skip/delete с #ELEMENT_FOUND# в сборке поиска срабатывали
        // по устаревшему значению прошлой строки — сбрасываем: в сборках
        // этапов сохранения правило оценится уже по реальному значению
        MapRules::$lastUnset = false;
        // опция «пропускать строку, если элемент не найден»: новые элементы не добавляются
        if ($existing === null && $this->skipIfNotFound) {
            Logger::debug('Строка пропущена: элемент не найден (SKIP_IF_NOT_FOUND)', [
                'profile_id' => (int)($this->profileData['ID'] ?? 0),
                'iblock_id' => (int)$entityId,
                'filter' => $filter,
            ]);
            throw new SkipRowSignal('Элемент не найден (SKIP_IF_NOT_FOUND)');
        }
        if ($existing === null && !empty($filter)) {
            // элемент не найден — будет добавлен как новый (фильтр в логе для разбора)
            Logger::debug(
                'Элемент не найден по фильтру поиска — будет добавлен как новый',
                [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'filter' => $filter,
                    'search_item' => $searchItem,
                ]
            );
        }
        // === этап 2: обработка остальных колонок согласно режиму IS_NEW ===
        // найден элемент — режим 'update' (поля «только для новых» пропускаются),
        // не найден — режим 'new' (поля «только для обновляемых» пропускаются)
        $mode = $existing ? 'update' : 'new';
        // данные найденного элемента — для макросов #ENTITY_<ПОЛЕ># в правилах
        // (условиях и значениях действий); элемент не найден — данных нет,
        // макросы раскрываются в пустую строку
        $this->itemEntityData = is_array($existing) ? $existing : null;
        $saveItem = $this->buildItem($entityId, $rawRow, $sheetConfig, $colStats, $mode);
        // правило TYPE=delete с макросом #ELEMENT_FOUND# срабатывает только здесь,
        // после реального поиска элемента: найден — удаляем и завершаем строку
        if ($this->rowDeleteRules && $existing) {
            $deletedId = (int)$existing['ID'];
            $deleted = (bool)$this->svcTime('delete', function () use ($entityService, $deletedId, $entityId) {
                return Handlers::withImportFlag(function () use ($entityService, $deletedId, $entityId) {
                    return $entityService->deleteElement((string)$entityId, $deletedId);
                }, (string)$entityId);
            })[0];
            if ($deleted) {
                Logger::info('Элемент удален по правилу импорта', [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'row_num' => $rowNum,
                    'element_id' => $deletedId,
                    'rule' => $this->rowSkipRuleInfo,
                ]);
            } else {
                Logger::error('Ошибка удаления элемента по правилу', [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'row_num' => $rowNum,
                    'element_id' => $deletedId,
                ]);
            }
            throw new SkipRowSignal(
                $deleted ? 'Удалено по правилу' : 'Ошибки удаления по правилу',
                $deleted ? 'Удалено по правилу' : 'Ошибки удаления по правилу'
            );
        }
        // правило TYPE=skip с #ELEMENT_FOUND# тоже оценивается только здесь
        if ($this->rowSkipRules) {
            throw new SkipRowSignal('Пропущено по правилу', 'Пропущено по правилу');
        }
        // системные опции профиля попадают в массив загрузки (SYSTEM_*),
        // сервис-приемник разбирает их через prepareFields
        $saveItem += $this->systemFields();
        // типы привязки значений свойств (PROPERTY_XXX_BIND) из настроек сопоставления
        $saveItem += $this->propertyBindFields($sheetConfig);
        // восстанавливаем флаг пропуска строки
        $this->rowSkipRules = $skipBackup;

        if ($existing) {
            $existingData = $existing;
            // обновление самим импортом — хеши строки удалять не нужно
            // (замер времени сохранения — здесь, в импорт-классе)
            // поля, совпадающие с текущими данными элемента, удаляются из загрузки:
            // если после этого полей не осталось — обновление не выполняется
            $saveItemStart = $saveItem;
            $saveItem = $this->stripUnchangedFields($existingData, $saveItem);
            // хеш финальных значений полей: значения, которые реально останутся
            // в элементе после обработки (неизмененные поля — текущие значения
            // найденного элемента, измененные — новые значения из строки)
            $finalState = [];
            foreach ($saveItemStart as $k => $v) {
                if (!is_string($k) || str_starts_with($k, 'SYSTEM_') || str_ends_with($k, '_BIND') || str_ends_with($k, '_MULTI')) continue;
                $existingKey = str_starts_with($k, 'PROPERTY_') ? $k . '_VALUE' : $k;
                $finalState[$k] = array_key_exists($k, $saveItem) ? $v : (string)($existingData[$existingKey] ?? '');
            }
            $this->finalItemHash = $this->rowHash($finalState);

            // === обновление существующего элемента ===
            Logger::debug(
                'Обновлен элемент инфоблока ID='.$existingData['ID'],
                [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'row_num' => $rowNum,
                    'filter' => $filter,
                    'row_hash_raw' => $rowHashRaw,
                    'item_hash' => $itemHash,
                    'save_item' => $saveItem,
                    'save_item_start' => $saveItemStart
                ]
            );

            $hasChanges = false;
            foreach ($saveItem as $k => $v) {
                if (!is_string($k)) {
                    $hasChanges = true;
                    break;
                }
                if (str_starts_with($k, 'SYSTEM_') || str_ends_with($k, '_BIND') || str_ends_with($k, '_MULTI')) continue;
                $hasChanges = true;
                break;
            }
            // проверка по хешу финальных значений: если значения полей элемента
            // не изменились с прошлого прогона (хеш совпал и финализирован) —
            // пропускаем обновление, как при отсутствии изменений полей
            if ($hasChanges
                && $this->hashType === 'item'
                && $this->finalItemHash !== ''
                && $this->checkHashSafe((int)($this->profileData['ID'] ?? 0), $this->finalItemHash)
                && $this->isHashFinal((int)($this->profileData['ID'] ?? 0), $this->finalItemHash)) {
                $this->touchHashElement(
                    (int)($this->profileData['ID'] ?? 0),
                    $this->finalItemHash,
                    (array)($this->profileData['SETTINGS'] ?? [])
                );
                $hasChanges = false;
                $this->processStats['Пропущено по хешу'] = (int)($this->processStats['Пропущено по хешу'] ?? 0) + 1;
            }
            if ($hasChanges) {
                $updateResult = $this->svcTime('update', function () use ($entityService, $entityId, $existingData, $saveItem) {
                    Handlers::withImportFlag(function () use ($entityService, $entityId, $existingData, $saveItem) {
                        $entityService->updateElement($entityId, (int)$existingData['ID'], $saveItem);
                    }, (string)$entityId);
                });
                $tSave = $updateResult[1];
                $elementId = (string)$existingData['ID'];
                $this->processStats['Обновлено'] = (int)($this->processStats['Обновлено'] ?? 0) + 1;
            } else {
                Logger::debug(
                    'Обновление пропущено: поля элемента не изменились',
                    [
                        'profile_id' => (int)($this->profileData['ID'] ?? 0),
                        'iblock_id' => (int)$entityId,
                        'row_num' => $rowNum,
                        'element_id' => (string)($existingData['ID'] ?? ''),
                    ]
                );
                $tSave = 0.0;
                $elementId = (string)($existingData['ID'] ?? '');
                $this->processStats['Пропущено: без изменений'] = (int)($this->processStats['Пропущено: без изменений'] ?? 0) + 1;
            }
        } else {
            // хеш финальных значений полей нового элемента (для дедупликации
            // повторных прогонов: значения, которые реально запишутся в элемент)
            $finalState = [];
            foreach ($saveItem as $k => $v) {
                if (!is_string($k) || str_starts_with($k, 'SYSTEM_') || str_ends_with($k, '_BIND') || str_ends_with($k, '_MULTI')) continue;
                $finalState[$k] = $v;
            }
            $this->finalItemHash = $this->rowHash($finalState);
            // === создание нового элемента ===
            Logger::debug(
                'Добавлен новый элемент инфоблока',
                [
                    'profile_id' => (int)($this->profileData['ID'] ?? 0),
                    'iblock_id' => (int)$entityId,
                    'row_num' => $rowNum,
                    'filter' => $filter,
                    'save_item' => $saveItem,
                ]
            );
            // добавление самим импортом — флаг гарантирует, что хеши не будут удалены
            $tSave = $this->svcTime('add', function () use ($entityService, $entityId, $saveItem) {
                return Handlers::withImportFlag(function () use ($entityService, $entityId, $saveItem) {
                    return $entityService->addElement($entityId, $saveItem);
                }, (string)$entityId);
            });
            $elementId = (string)$tSave[0];
            $tSave = $tSave[1];
            $this->processStats['Добавлено новых'] = (int)($this->processStats['Добавлено новых'] ?? 0) + 1;
        }

        return [
            'data' => $item,
            'maps' => $maps,
            'element_id' => $elementId,
            'is_new' => !$existing,
            // техническая информация: режим обработки и фильтр поиска элемента
            'debug' => [
                'mode' => $mode,
                'filter' => $filter,
                'search_item' => $searchItem,
                // замеры времени методов сервиса (сек, за обработку строки)
                'times' => [
                    'find' => $tFind,
                    'save' => round($tSave, 4),
                    'op' => $existing ? 'update' : 'add',
                ],
            ],
            'processed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Фильтрация собранного элемента по режиму применения полей (IS_NEW):
     *  - 'update': поле применяется только если ни одно его сопоставление не помечено «только для новых»;
     *  - 'new':    поле применяется только если ни одно его сопоставление не помечено «только для обновляемых».
     * Логика обработки правил: сперва поля поиска, затем (если элемент найден) поля обновления,
     * иначе — поля для новых элементов.
     *
     * @param array $item финальный JSON строки
     * @param array $sheetConfig настройки листа (COLUMNS с MAPS/MAP_SETTINGS)
     * @param string $mode 'new' | 'update'
     * @return array
     */
    /**
     * Удаление из массива загрузки полей, значения которых совпадают с текущими
     * данными существующего элемента. Служебные ключи (SYSTEM_*, *_BIND,
     * *_MULTI) не трогаются.
     * Свойства сравниваются с PROPERTY_XXX_VALUE из данных элемента.
     * Сравнение консервативно: неуверенное совпадение (разный формат и т.п.) поле сохраняет.
     *
     * @param array $existingData данные существующего элемента (GetFields)
     * @param array $saveItem массив загрузки
     * @return array массив загрузки без неизменившихся полей
     */
    protected function stripUnchangedFields(array $existingData, array $saveItem): array
    {
        foreach ($saveItem as $key => $value) {
            if (!is_string($key)) continue;
            if (str_starts_with($key, 'SYSTEM_') || str_ends_with($key, '_BIND') || str_ends_with($key, '_MULTI')) continue;
            $existingKey = str_starts_with($key, 'PROPERTY_') ? $key . '_VALUE' : $key;
            if (!array_key_exists($existingKey, $existingData)) continue;
            if (self::normalizeForCompare($existingData[$existingKey]) !== self::normalizeForCompare($value)) continue;
            unset($saveItem[$key]);
        }
        return $saveItem;
    }

    /**
     * Нормализация значения для сравнения: строки — с обрезкой пробелов,
     * числа — без ведущих нулей и хвостового ".0", массивы — сортировка элементов.
     */
    protected static function normalizeForCompare(mixed $value): string
    {
        if (is_array($value)) {
            $parts = array_map(self::normalizeForCompare(...), array_values($value));
            sort($parts, SORT_STRING);
            return '[' . implode('|', $parts) . ']';
        }
        if (is_bool($value)) return $value ? 'Y' : 'N';
        if ($value === null) return '';
        $s = trim((string)$value);
        if ($s !== '' && is_numeric($s)) {
            $f = (float)$s;
            return (string)($f == (int)$f ? (string)(int)$f : (string)$f);
        }
        return $s;
    }

    /**
     * Проверка допустимости сопоставления при сборке элемента в заданном режиме:

     *  - 'all'    — все сопоставления (используется для проверки правил пропуска строки);
     *  - 'search' — только сопоставления, включенные в поиск элемента (SEARCH_USE = Y);
     *  - 'update' — пропускаются поля «только для новых» (IS_NEW = Y);
     *  - 'new'    — пропускаются поля «только для обновляемых» (IS_NEW = U).
     *
     * @param array $col конфиг колонки
     * @param array $settings настройки конкретного сопоставления (MAP_SETTINGS)
     * @param string $mode режим сборки
     * @return bool
     */

/**
     * Типы привязки значений свойств из настроек сопоставления листа:
     * MAP_SETTINGS[key].BIND -> PROPERTY_XXX_BIND (id|xml_id|name|code).
     * Разбирается сервисом-приемником в prepareFields; ключи не участвуют
     * в item-хеше (хеш считается по значениям, а не по служебным полям).
     */
    protected function propertyBindFields(array $sheetConfig): array
    {
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        $binds = [];
        foreach ($columns as $col) {
            $maps = is_array($col['MAPS'] ?? null) ? $col['MAPS'] : [];
            $mapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];
            foreach ($maps as $mapIdx => $field) {
                $field = trim((string)$field);
                if ($field === '') continue;
                // опции полей раздела и родительского раздела: тип привязки,
                // создание отсутствующих, разделитель уровней,
                // разделитель нескольких разделов (DELIMETER из настроек сопоставления)
                if ($field === 'IBLOCK_SECTION' || $field === 'IBLOCK_SECTION_PARENT') {
                    $sectionCreate = strtoupper(trim((string)($mapSettings[$mapIdx]['SECTION_CREATE'] ?? 'Y')));
                    if ($sectionCreate === 'N') {
                        $binds['IBLOCK_SECTION_CREATE'] = 'N';
                    }
                    $levelDelim = (string)($mapSettings[$mapIdx]['SECTION_LEVEL_DELIM'] ?? '/');
                    if ($levelDelim !== '' && $levelDelim !== '/') {
                        $binds['IBLOCK_SECTION_LEVEL_DELIM'] = $levelDelim;
                    }
                    $valuesDelim = (string)($mapSettings[$mapIdx]['DELIMETER'] ?? '');
                    if ($valuesDelim !== '') {
                        $binds['IBLOCK_SECTION_VALUES_DELIM'] = $valuesDelim;
                    }
                    $bind = strtoupper(trim((string)($mapSettings[$mapIdx]['BIND'] ?? '')));
                    if ($bind !== '' && $bind !== 'AUTO') {
                        $binds[$field . '_BIND'] = strtolower($bind);
                    }
                    continue;
                }
                if (!str_starts_with($field, 'PROPERTY_')) continue;
                $bind = strtoupper(trim((string)($mapSettings[$mapIdx]['BIND'] ?? '')));
                if ($bind !== '' && $bind !== 'AUTO') {
                    $binds[$field . '_BIND'] = strtolower($bind);
                }
                // автосоздание отсутствующих значений списка: в загрузку
                // передается только отключение ('N'), Y — по умолчанию
                $createEnum = strtoupper(trim((string)($mapSettings[$mapIdx]['CREATE_ENUM'] ?? 'Y')));
                if ($createEnum === 'N') {
                    $binds[$field . '_CREATE_ENUM'] = 'N';
                }
                // разделитель множественного значения (DELIMETER из настроек
                // сопоставления); в сервисе по умолчанию запятая
                $valuesDelim = (string)($mapSettings[$mapIdx]['DELIMETER'] ?? '');
                if ($valuesDelim !== '') {
                    $binds[$field . '_DELIM'] = $valuesDelim;
                }
                // режим множественного свойства (MULTI из настроек сопоставления):
                // append — дополнить текущие значения новыми, delete — удалить
                // совпавшие значения; пусто/replace — перезаписать все значения
                $multi = strtolower(trim((string)($mapSettings[$mapIdx]['MULTI'] ?? '')));
                if ($multi === 'append' || $multi === 'delete') {
                    $binds[$field . '_MULTI'] = $multi;
                }
            }
        }
        return $binds;
    }

    /**
     * Системные опции профиля (SYSTEM_*), передаваемые в массиве загрузки:
     *  - SYSTEM_REPLACE_DATE — обновлять дату у неизмененных элементов (обрабатывается
     *    в skipRow-путях через touchHashElement, в загрузку передается для совместимости);
     *  - SYSTEM_FACET — обновить фасетный индекс (только инфоблочные профили, по умолчанию да);
     *  - SYSTEM_META — обновить расчет мета тегов (только инфоблочные профили, по умолчанию да);
     *  - SYSTEM_SEARCH_NEW — индексировать новые элементы для поиска (только инфоблочные профили, по умолчанию да);
     *  - SYSTEM_SEARCH_UPDATE — индексировать измененные элементы для поиска (только инфоблочные профили, по умолчанию да);
     *  - SYSTEM_RESIZE_PICTURES — использовать настройки инфоблока для обработки изображений
     *    (только инфоблочные профили, по умолчанию нет);
     *  - SYSTEM_UPDATE_ORDER — тип обновления: fields_first (сперва поля, потом свойства,
     *    по умолчанию) | props_first (сперва свойства, потом поля).
     */
    protected function systemFields(): array
    {
        $settings = is_array($this->profileData['SETTINGS'] ?? null) ? $this->profileData['SETTINGS'] : [];
        $result = [];
        if ((string)($settings['SYSTEM_REPLACE_DATE'] ?? 'N') === 'Y') {
            $result['SYSTEM_REPLACE_DATE'] = 'Y';
        }
        if ((string)($settings['DEST_TYPE'] ?? '') === 'iblock') {
            if ((string)($settings['SYSTEM_FACET'] ?? 'Y') === 'Y') {
                $result['SYSTEM_FACET'] = 'Y';
            }
            if ((string)($settings['SYSTEM_META'] ?? 'Y') === 'Y') {
                $result['SYSTEM_META'] = 'Y';
            }
            if ((string)($settings['SYSTEM_SEARCH_NEW'] ?? 'Y') === 'Y') {
                $result['SYSTEM_SEARCH_NEW'] = 'Y';
            }
            if ((string)($settings['SYSTEM_SEARCH_UPDATE'] ?? 'Y') === 'Y') {
                $result['SYSTEM_SEARCH_UPDATE'] = 'Y';
            }
            if ((string)($settings['SYSTEM_RESIZE_PICTURES'] ?? 'N') === 'Y') {
                $result['SYSTEM_RESIZE_PICTURES'] = 'Y';
            }
            // тип обновления: сначала свойства, потом поля элемента — или наоборот
            $result['SYSTEM_UPDATE_ORDER'] = (string)($settings['SYSTEM_UPDATE_ORDER'] ?? 'fields_first') === 'props_first'
                ? 'props_first' : 'fields_first';
        }
        return $result;
    }

    /**
     * Обновление даты неизмененного (пропущенного по хешу) элемента:
     * ELEMENT_ID берется из хеша профиля — без поиска элемента по фильтру.
     * Работает только при опции SYSTEM_REPLACE_DATE=Y.
     */

/**
     * Безопасная проверка хеша строки: ошибка БД не ломает импорт,
     * а пишется в лог (иначе дедуп молча перестает работать).
     * При активной опции HASH_MEM_CACHE=Y проверка идет по набору в памяти
     * (загружен одним запросом на хит), запрос в БД не выполняется.
     */

/**
     * Финальность хеша: у сохраненной строки есть ELEMENT_ID (элемент реально
     * создан/обновлен) или маркер пропуска по правилам. Хеш с пустым ELEMENT_ID —
     * «висячий» резерв прерванной обработки (обрыв хита после резерва, до
     * создания элемента): такая строка не должна пропускаться, иначе элемент
     * никогда не будет создан. Вызывается после checkHashSafe=true.
     */

/**
     * Безопасное сохранение хеша строки: ошибка БД не ломает импорт.
     * При активном наборе в памяти новый хеш добавляется в набор,
     * чтобы последующие строки хита проверялись по нему без запроса в БД.
     */

/**
     * Безопасное удаление хеша строки (снятие резерва при ошибке обработки).
     */

/**
     * Топ-10 самых нагруженных колонок по накопленному времени обработки.
     * @param array $colStats [alias => ['time'=>float,'count'=>int]]
     * @return array
     */

/**
     * Применение настроек сопоставления к значению.
     * Логика опций и правил вынесена в класс Awz\Allxls\MapRules.
     *
     * @param string $value значение ячейки-источника
     * @param array $col конфиг колонки (MAPS, MAP_SETTINGS)
     * @param array $rowValues сырые значения всей строки (ключ колонки => значение)
     * @param string $fromKey ключ текущей колонки
     * @param array $columns конфиги всех колонок листа (для макросов)
     * @param string $iblockId ид инфоблока-приёмника (для действия unique_code)
     * @return string
     */

/**
     * Описание сработавшего правила обработки (для технической информации).
     *
     * @param array $entry запись из MapRules::$lastApplied (type, action, aval, rule)
     * @return string человекочитаемое описание
     */

/**
     * Описание условия правила (листа или группы дерева условий).
     *
     * @param array $cond условие (SOURCE, OP, VALUE, VALUE2, CHILDREN, AGG)
     * @return string человекочитаемое описание
     */

/**
     * Значения колонок строки из файла (для технической информации в ответе).
     *
     * @param array $row строка файла
     * @param array $sheetConfig настройки листа (COLUMNS)
     * @return array список {col: буква/ключ, header: заголовок, value: значение}
     */
    protected function rowDebugRaw(array $row, array $sheetConfig): array
    {
        $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
        $result = [];
        foreach ($columns as $key => $col) {
            if (($col['INCLUDE'] ?? 'Y') === 'N') continue;
            $index = (int)filter_var($key, FILTER_SANITIZE_NUMBER_INT);
            $letter = trim((string)($col['LETTER'] ?? ''));
            $header = trim((string)($col['HEADER'] ?? ''));
            $result[] = [
                'col' => $letter !== '' ? $letter : $key,
                'key' => $key,
                'header' => $header,
                'value' => (string)($row[$index] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Хеш строки только по задействованным колонкам: значения колонок
     * с INCLUDE=Y и непустым списком сопоставлений (MAPS). Неиспользуемые
     * колонки (текстовые примечания и т.п.) не меняют хеш — дедуп не
     * срабатывает повторно при изменении неиспользуемых ячеек файла.
     *
     * @param array $row строка файла
     * @param array $sheetConfig настройки листа (COLUMNS)
     * @return string md5-хеш
     */

/**
     * Вычисление хеша строки: каноничный JSON значений строки -> md5.
     * Используется как для сырых строк файла (hashType=row),
     * так и для собранных элементов после применения правил (hashType=item).
     *
     * @param array $row значения строки (или собранный элемент)
     * @return string md5-хеш строки
     */

/**
     * Удаление элемента по правилу TYPE=delete: собираются поисковые поля
     * (buildItem mode=search), строится фильтр, найденный элемент удаляется.
     * Обе метки хешей сохраняются с маркером HASH_SKIP_MARK — строка больше
     * не обрабатывается при повторных импортах. Статистика: «Удалено по правилу»
     * / «Ошибки удаления по правилу».
     */

/**
     * Обработка пропущенной строки (пустая строка или пропуск по правилам):
     * инкремент счетчика статистики, сдвиг курсора без обработки и без
     * увеличения счетчика обработанных строк, сохранение состояния.
     *
     * @param int $id ID профиля
     * @param array $settings настройки профиля
     * @param string $sheetName текущий лист
     * @param int $rowNum текущая строка
     * @param int $offset новая байтовая позиция курсора (CSV), для XLSX — 0
     * @param int $processed счетчик обработанных строк (не изменяется)
     * @param string $statKey ключ счетчика статистики ('Пропущено пустых строк' / 'Пропущено по правилам')
     * @param array|null $item собранный элемент строки (null для пустой строки)
     * @param int $memStart память на старте запроса
     * @param int $memPeakStart пик памяти на старте запроса
     * @param int $bytesRead прочитано байт за запрос
     * @param float $percent прогресс файла
     * @param int $fileSize размер файла
     * @param int $readPos текущая позиция чтения (байты)
     * @param array $colStats статистика по колонкам
     * @param array $sheetTotals итоговые количества строк по листам
     * @return array ответ для клиента
     */

/**
     * Защита от ручного запуска/изменения параметров, пока профиль
     * выполняется cron-процессом.
     *
     * @param int $id ID профиля
     * @param bool $allowFromCron true — вызов из cron-процесса
     * @return bool false — доступ запрещен (ошибка добавлена)
     */
    protected function cronRunGuard(int $id, bool $allowFromCron = false): bool
    {
        if ($id <= 0) return true; // NOT_FOUND обрабатывается вызывающим кодом
        if (!\Awz\Allxls\Cron\Runner::isProfileLocked($id)) return true;
        if ($allowFromCron) return true;
        // блокировка без обновления статистики дольше 3 минут — cron считается
        // упавшим: ручное продолжение разрешено, блокировка и счетчик падений
        // сбрасываются (ручное вмешательство = восстановление)
        if (!\Awz\Allxls\Cron\Runner::isCronAlive($id)) {
            \Awz\Allxls\Cron\Runner::releaseLock($id);
            \Awz\Allxls\Cron\Runner::resetCronFalls($id);
            return true;
        }
        $this->addError(new \Bitrix\Main\Error(
            Loc::getMessage('AWZ_ALLXLS_API_PIMPORT_CRON_LOCKED'),
            'CRON_RUNNING'
        ));
        return false;
    }

    /**
     * Сохранение параметров запуска обработки в настройках профиля.
     * Вызывается перед стартом обработки.
     */
    public function saveParamsAction(int $id = 0, int $hitTimeLimit = 20, int $maxExecutionTime = 0, string $logErrors = 'N', string $hashType = 'item', string $hashMemCache = 'N', string $hashUsedColumns = 'N', string $skipIfNotFound = 'N', string $systemReplaceDate = 'N', string $systemFacet = 'Y', string $systemMeta = 'Y', string $systemSearchNew = 'Y', string $systemSearchUpdate = 'Y', string $systemResizePictures = 'N', string $systemUpdateOrder = 'fields_first', string $stepByStep = 'N', int $requestDelay = 0)
    {
        $id = (int)$id;
        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'), 'NOT_FOUND'
            ));
            return null;
        }
        if (!$this->cronRunGuard($id)) return null;
        $profile = ProfilesTable::getById($id)->fetch();
        if (!$profile) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'), 'NOT_FOUND'
            ));
            return null;
        }
        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];
        $settings['HIT_TIME_LIMIT'] = max(0, $hitTimeLimit);
        $settings['MAX_EXECUTION_TIME'] = max(0, $maxExecutionTime);
        // пауза между запросами в UI (сек)
        $settings['REQUEST_DELAY'] = max(0, $requestDelay);
        $settings['LOG_ERRORS'] = ($logErrors === 'Y') ? 'Y' : 'N';
        // тип проверки хешей: '' — без проверки, 'row' — по строке файла, 'item' — по финальным значениям
        $settings['HASH_TYPE'] = in_array($hashType, ['no', 'row', 'item'], true) ? $hashType : 'row';
        // режим дедупа: 'Y' — все хеши профиля грузятся одним запросом в память,
        // 'N' — проверка запросом в БД на каждую строку
        $settings['HASH_MEM_CACHE'] = ($hashMemCache === 'Y') ? 'Y' : 'N';
        // хеш строки только по задействованным колонкам (INCLUDE=Y и есть сопоставления)
        $settings['HASH_USED_COLUMNS'] = ($hashUsedColumns === 'Y') ? 'Y' : 'N';
        // пропускать строку, если существующий элемент не найден (не добавлять новые)
        $settings['SKIP_IF_NOT_FOUND'] = ($skipIfNotFound === 'Y') ? 'Y' : 'N';
        // системные опции: обновлять дату у неизмененных элементов
        $settings['SYSTEM_REPLACE_DATE'] = ($systemReplaceDate === 'Y') ? 'Y' : 'N';
        // режим «импорт по одному элементу»: одна строка за запрос,
        // следующий элемент запускается кнопкой на финальном этапе
        $settings['STEP_BY_STEP'] = ($stepByStep === 'Y') ? 'Y' : 'N';
        // инфоблочные профили: фасетный индекс и мета теги (по умолчанию обновляются)
        if ((string)($settings['DEST_TYPE'] ?? '') === 'iblock') {
            $settings['SYSTEM_FACET'] = ($systemFacet === 'N') ? 'N' : 'Y';
            $settings['SYSTEM_META'] = ($systemMeta === 'N') ? 'N' : 'Y';
            $settings['SYSTEM_SEARCH_NEW'] = ($systemSearchNew === 'N') ? 'N' : 'Y';
            $settings['SYSTEM_SEARCH_UPDATE'] = ($systemSearchUpdate === 'N') ? 'N' : 'Y';
            $settings['SYSTEM_RESIZE_PICTURES'] = ($systemResizePictures === 'Y') ? 'Y' : 'N';
            $settings['SYSTEM_UPDATE_ORDER'] = ($systemUpdateOrder === 'props_first') ? 'props_first' : 'fields_first';
        }
        ProfilesTable::update($id, [
            'SETTINGS' => $settings
        ]);
        return ['saved' => true];
    }

    /**
     * Принудительное завершение обработки прерванного импорта:
     * состояние помечается как завершенное (статистика сохраняется).
     */
    public function finishAction(int $id = 0)
    {
        $id = (int)$id;
        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'), 'NOT_FOUND'
            ));
            return null;
        }
        $profile = ProfilesTable::getById($id)->fetch();
        if (!$profile) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'), 'NOT_FOUND'
            ));
            return null;
        }
        // принудительное завершение: cron-блокировка (в т.ч. «висячая» после
        // обрыва cron-процесса) снимается, чтобы профиль снова был доступен.
        // Пока статистика обновлялась менее 3 минут назад — cron может быть жив,
        // завершение блокируется с сообщением о необходимости подождать
        if (\Awz\Allxls\Cron\Runner::isProfileLocked($id)) {
            if (\Awz\Allxls\Cron\Runner::isCronAlive($id)) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PIMPORT_CRON_ALIVE_WAIT'),
                    'CRON_ALIVE_WAIT'
                ));
                return null;
            }
            \Awz\Allxls\Cron\Runner::releaseLock($id);
            \Awz\Allxls\Cron\Runner::resetCronFalls($id);
            Logger::info('Блокировка cron снята при завершении импорта', ['profile_id' => $id]);
        }
        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];
        $process = is_array($settings['PROCESS'] ?? null) ? $settings['PROCESS'] : [];
        // завершение импорта: рабочая копия файла источника удаляется
        $this->deleteSourceCopy((string)($process['TMP_FILE'] ?? ''));
        unset($process['TMP_FILE']);
        $process['DONE'] = 'Y';
        $process['SHEET'] = '';
        $process['ROW'] = 0;
        $process['OFFSET'] = 0;
        $process['UPDATED'] = date('Y-m-d H:i:s');
        // статистика остается после завершения: если отображаемой еще нет —
        // собираем ее из накопленных счетчиков STATS (полный набор показателей)
        if (empty($process['STATS_VIEW']) || !is_array($process['STATS_VIEW'])) {
            $rawStats = is_array($process['STATS'] ?? null) ? $process['STATS'] : [];
            if ($rawStats) {
                // накопленные счетчики восстанавливаются в память контроллера
                $this->processStats = $rawStats;
                $process['STATS_VIEW'] = $this->buildStatsView(
                    (float)($process['TOTAL_TIME'] ?? 0), 0.0, 0,
                    (float)($process['PEAK_MEM'] ?? 0),
                    is_array($process['SVC_TIME'] ?? null) ? $process['SVC_TIME'] : []
                );
            }
        }
        $settings['PROCESS'] = $process;
        ProfilesTable::update($id, [
            'SETTINGS' => $settings
        ]);
        return ['finished' => true];
    }

    /**
     * Сброс состояния и накопленной статистики импорта (новый запуск).
     */
    /**
     * Очистка каталога рабочих копий (/upload/tmp/awz.allxls):
     * для каждого профиля остаются только 3 последних файла,
     * копии, на которые ссылаются состояния профилей (PROCESS.TMP_FILE),
     * не удаляются; прочие файлы — старше одного дня.
     */
    protected function cleanupTmpDir(): void
    {
        try {
            $docRoot = \Bitrix\Main\Application::getDocumentRoot();
            $dir = $docRoot . '/upload/tmp/awz.allxls';
            if (!is_dir($dir)) return;

            // копии, на которые ссылается состояние любого профиля, не трогаем
            $protected = [];
            $rs = ProfilesTable::getList(['select' => ['SETTINGS']]);
            while ($row = $rs->fetch()) {
                $tmp = (string)(($row['SETTINGS']['PROCESS']['TMP_FILE'] ?? ''));
                if ($tmp !== '') {
                    $protected[strtolower(str_replace('\\', '/', $tmp))] = true;
                }
            }

            $keepPerProfile = 3;
            $edge = time() - 86400;
            $removed = 0;

            // группируем файлы по id профиля (awz_allxls_{id}_{hash}.ext)
            $byProfile = [];
            $others = [];
            foreach (new \DirectoryIterator($dir) as $file) {
                if ($file->isDot() || !$file->isFile()) continue;
                if (preg_match('/^awz_allxls_(\d+)_/', $file->getFilename(), $m)) {
                    $byProfile[(int)$m[1]][] = $file;
                } else {
                    $others[] = $file;
                }
            }

            foreach ($byProfile as $profileId => $files) {
                // сортировка по времени модификации: новые первыми
                usort($files, static function ($a, $b) {
                    return $b->getMTime() <=> $a->getMTime();
                });
                foreach ($files as $i => $file) {
                    $path = strtolower(str_replace('\\', '/', $file->getPathname()));
                    if (isset($protected[$path])) continue; // активная копия состояния
                    if ($i < $keepPerProfile) continue;     // 3 последних на профиль
                    if (@unlink($file->getPathname())) $removed++;
                }
            }

            // файлы вне схемы именования профилей — по возрасту (старше суток)
            foreach ($others as $file) {
                $path = strtolower(str_replace('\\', '/', $file->getPathname()));
                if (isset($protected[$path])) continue;
                if ($file->getMTime() >= $edge) continue;
                if (@unlink($file->getPathname())) $removed++;
            }

            if ($removed > 0) {
                Logger::info('Очистка временных копий: удалено файлов', [
                    'removed' => $removed,
                    'dir' => 'upload/tmp/awz.allxls',
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error('Ошибка очистки временных копий', ['error' => $e->getMessage()]);
        }
    }

    protected function resetState(int $id): void
    {
        $profile = ProfilesTable::getById($id)->fetch();
        if (!$profile) return;
        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];
        // сброс импорта: рабочая копия файла источника больше не нужна
        $this->deleteSourceCopy((string)($settings['PROCESS']['TMP_FILE'] ?? ''));
        $settings['PROCESS'] = [
            'SHEET' => '',
            'ROW' => 0,
            'OFFSET' => 0,
            'PROCESSED' => 0,
            'STATS' => [],
            'ERRORS' => [],
            'COL_STATS' => [],
            'SHEET_TOTALS' => [],
            'DONE' => 'N',
            'UPDATED' => date('Y-m-d H:i:s'),
        ];
        $this->lastStatsView = null;
        ProfilesTable::update($id, [
            'SETTINGS' => $settings
        ]);
    }

    /**
     * «Живой» дамп статистики в профиль (не чаще раза в 30 сек из цикла хита):
     * при долгом cron-запуске (CRON_HIT_TIME=0 — обработка до завершения за один
     * вызов) прогресс виден в интерфейсе до конца хита.
     * @param int $id ID профиля
     * @param float $totalTime накопленное общее время работы импорта, сек
     * @param float $hitElapsed время текущего хита, сек
     * @param int $hitRows строк обработано в текущем хите
     */

/**
     * Сохранение состояния обработки.
     *
     * Ленивое сохранение: актуальное состояние всегда кладется в память
     * (pendingState — читается следующим importStep), а запись в профиль
     * выполняется с интервалом stateSaveInterval (1 сек в браузере, 30 сек
     * в cron — экономия БД; при обрыве строки докатываются по дедупу хешей)
     * и всегда при done.
     */
    /**
     * Коммит байтовой позиции XML-курсора листа: вызывается при сдвиге
     * курсора строки (успех/пропуск/строка отсутствует). Позиция указывает
     * на байт сразу после закрывающего тега последнего обработанного
     * элемента — следующая строка листа начнется с нее (следующий хит
     * открывает XML-ридер на этой позиции). При ошибке обработки (ретрай)
     * позиция не коммитится — строка перечитается в следующем хите.
     */
    protected function commitXmlPos(string $sheetName): void
    {
        if ($this->xmlPosSheet === $sheetName) {
            $this->xmlPosMap[$sheetName] = $this->xmlPosPending;
        }
    }

    protected function saveState(
        int $id, array $settings, string $sheetName, int $rowNum, int $offset,
        int $processed, array $stats, array $errors, bool $done, array $colStats = [],
        array $sheetTotals = [], int $retry = 0
    ): void {
        $oldProcess = is_array($settings['PROCESS'] ?? null) ? $settings['PROCESS'] : [];
        $settings['PROCESS'] = [
            'SHEET' => $done ? '' : $sheetName,
            'ROW' => $done ? 0 : $rowNum,
            'OFFSET' => $done ? 0 : $offset,
            'PROCESSED' => $processed,
            'STATS' => $stats,
            'ERRORS' => $errors,
            'COL_STATS' => $colStats,
            'SHEET_TOTALS' => $sheetTotals,
            // байтовая позиция XML-курсора по листам (после закрывающего тега
            // последнего обработанного элемента) — продолжение без промотки.
            // Пустая карта (новый хит, строки этого листа еще не читались)
            // не затирает сохраненные в БД позиции
            'XML_POS' => !empty($this->xmlPosMap)
                ? $this->xmlPosMap
                : (is_array($oldProcess['XML_POS'] ?? null) ? $oldProcess['XML_POS'] : []),
            // счетчик повторных попыток текущей строки (сброс после успеха)
            'RETRY' => $done ? 0 : $retry,
            'DONE' => $done ? 'Y' : 'N',
            // время последнего сохранения состояния (для возобновления после падения)
            'UPDATED' => date('Y-m-d H:i:s'),
        ];
        // отображаемая статистика и накопленные показатели времени/памяти
        // сохраняются между хитами — обнуляются только при новом запуске (resetState)
        foreach (['TOTAL_TIME', 'PEAK_MEM', 'SVC_TIME', 'TMP_FILE', 'SOURCE', 'CRON_ALIVE', 'RUN_STARTED', 'CAT_TREE_BUILT'] as $keepKey) {
            if (isset($oldProcess[$keepKey])) {
                $settings['PROCESS'][$keepKey] = $oldProcess[$keepKey];
            }
        }
        // STATS_VIEW: приоритет у «живого» дампа (dumpLiveStats обновляет его
        // в базе каждые 30 сек, но кеш профиля начала хита содержит старое
        // значение — keepKey затирал свежий дамп)
        if ($this->lastStatsView !== null) {
            $settings['PROCESS']['STATS_VIEW'] = $this->lastStatsView;
        } elseif (isset($oldProcess['STATS_VIEW'])) {
            $settings['PROCESS']['STATS_VIEW'] = $oldProcess['STATS_VIEW'];
        }
        // маркер cron-запуска (CLI): интерфейс показывает «обработка на crontab»
        if ($this->isCronRun) {
            $settings['PROCESS']['SOURCE'] = 'cron';
            // heartbeat для детекта падения cron-процесса (3 мин без обновления)
            $settings['PROCESS']['CRON_ALIVE'] = time();
            // старт прогона — с первого же cron-хита (UI считает общее время)
            if ((int)($settings['PROCESS']['RUN_STARTED'] ?? 0) <= 0) {
                $settings['PROCESS']['RUN_STARTED'] = time();
            }
        }
        // «живой» дамп статистики (cron-запуск): не теряется при промежуточных
        // saveState в рамках хита (кеш профиля мог не видеть последний дамп)
        if (!isset($settings['PROCESS']['STATS_VIEW']) && $this->lastStatsView !== null) {
            $settings['PROCESS']['STATS_VIEW'] = $this->lastStatsView;
        }
        // импорт завершен — рабочая копия файла источника больше не нужна
        if ($done) {
            $this->deleteSourceCopy((string)($oldProcess['TMP_FILE'] ?? ''));
            unset($settings['PROCESS']['TMP_FILE']);
        }
        // актуальное состояние — в память для следующего importStep хита
        $this->pendingState = $settings['PROCESS'];

        // запись в профиль — с интервалом stateSaveInterval (1 сек в браузере,
        // 30 сек в cron), всегда при завершении
        if ($done || (microtime(true) - $this->lastStateSave) >= $this->stateSaveInterval) {
            $this->lastStateSave = microtime(true);
            $__perfT0 = microtime(true);
            $upd = ProfilesTable::update($id, [
                'SETTINGS' => $settings
            ]);
            self::perfAccum('save_state', microtime(true) - $__perfT0);
            if (!$upd->isSuccess()) {
                // молчаливый провал записи курсора перезапускал импорт с начала
                // на каждом хите (пропуск строк по хешу с самого начала файла)
                Logger::error('Не удалось сохранить состояние импорта', [
                    'profile_id' => $id,
                    'errors' => implode('; ', $upd->getErrorMessages()),
                ]);
            }
        }
    }
}
