<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Helper\ProfilesHelper;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Контроллер пошагового экспорта профиля (этап 4, TYPE=export).
 * Экшены:
 *  - awz:allxls.api.pexport.export      — пошаговая выгрузка элементов в файл
 *  - awz:allxls.api.pexport.saveparams  — сохранение параметров запуска
 *  - awz:allxls.api.pexport.finish      — принудительное завершение
 *  - awz:allxls.api.pexport.settings    — настройки экспорта (этап 3): листы + поля
 *  - awz:allxls.api.pexport.savesettings— сохранение настроек экспорта (этап 3)
 *
 * Файл выгрузки: /upload/awz.allxls/export/profile_<id>.xlsx (PhpSpreadsheet).
 * Пошаговость: за один хит выгружается порция элементов (лимит по времени),
 * состояние (индекс листа, последний ID элемента) хранится в SETTINGS['PROCESS_EXPORT'].
 */
class Pexport extends \Bitrix\Main\Engine\Controller {

    /** @var array накопительная статистика */
    protected array $processStats = [];
    /** @var array список ошибок */
    protected array $processErrors = [];

    public function __construct(\Bitrix\Main\Request $request = null)
    {
        \Bitrix\Main\Loader::includeModule('awz.allxls');
        parent::__construct($request);
        self::ensureTypeColumn();
    }

    protected function init()
    {
        // Включаем readonly-режим для сессии ДО инициализации родительского контроллера
        if (!defined('BX_SECURITY_SESSION_READONLY')) {
            define('BX_SECURITY_SESSION_READONLY', true);
        }

        parent::init();
    }

    /**
     * Защита от ручного запуска, пока профиль выполняется cron-процессом.
     *
     * @param int $id ID профиля
     * @param bool $allowFromCron true — вызов из cron-процесса
     * @return bool false — доступ запрещен (ошибка добавлена)
     */
    protected function cronRunGuard(int $id, bool $allowFromCron = false): bool
    {
        if ($id <= 0) return true;
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
            Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_CRON_LOCKED'),
            'CRON_RUNNING'
        ));
        return false;
    }

    /**
     * Защита от изменения настроек профиля с незавершенной выгрузкой
     * (PROCESS_EXPORT.DONE != Y и есть прогресс): работающий экспорт
     * перезаписывает SETTINGS своим состоянием — изменения терялись бы молча.
     *
     * @param int $id ID профиля
     * @return bool true — выгрузка не завершена (ошибка добавлена)
     */
    protected function processNotDoneGuard(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $row = ProfilesTable::getRow([
                'select' => ['SETTINGS'],
                'filter' => ['=ID' => $id],
            ]);
            if (!$row) return false;
            $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
            $proc = is_array($st['PROCESS_EXPORT'] ?? null) ? $st['PROCESS_EXPORT'] : [];
            $unfinished = (($proc['DONE'] ?? 'Y') !== 'Y')
                && ((int)($proc['PROCESSED'] ?? 0) > 0 || (string)($proc['LAST_ID'] ?? '') !== '');
            if (!$unfinished) return false;
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_PROCESS_NOT_DONE'),
                'PROCESS_NOT_DONE'
            ));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Миграция: добавление колонки TYPE в таблицу профилей (для существующих установок).
     */
    public static function ensureTypeColumn(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $conn = \Bitrix\Main\Application::getConnection();
            if (!$conn->isTableExists(ProfilesTable::getTableName())) return;
            $columns = $conn->getTableFields(ProfilesTable::getTableName());
            if (!isset($columns['TYPE'])) {
                $conn->queryExecute(
                    "ALTER TABLE ".ProfilesTable::getTableName()
                    ." ADD COLUMN TYPE varchar(10) NOT NULL DEFAULT 'import'"
                );
            }
        } catch (\Throwable $e) {
            // игнорируем: колонка может быть добавлена штатной установкой
        }
    }

    public function configureActions()
    {
        return [
            'export' => [
                'prefilters' => [
                    new \Bitrix\Main\Engine\ActionFilter\Authentication(),
                    new \Bitrix\Main\Engine\ActionFilter\CloseSession(),
                ]
            ],
            'settings' => [
                'prefilters' => [
                    new \Bitrix\Main\Engine\ActionFilter\Authentication(),
                    new \Bitrix\Main\Engine\ActionFilter\CloseSession(),
                ]
            ],
            'savesettings' => [
                'prefilters' => [
                    new \Bitrix\Main\Engine\ActionFilter\Authentication(),
                    new \Bitrix\Main\Engine\ActionFilter\CloseSession(),
                ]
            ],
            'colsettings' => [
                'prefilters' => [
                    new \Bitrix\Main\Engine\ActionFilter\Authentication(),
                    new \Bitrix\Main\Engine\ActionFilter\CloseSession(),
                ]
            ],
        ];
    }


    /**
     * Настройки экспорта (этап 3): сохраненные листы + список полей инфоблока.
     *
     * @param int $id ID профиля
     * @return array|null
     */
    public function settingsAction(int $id = 0)
    {
        $id = (int)$id;
        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        $savedSheets = is_array($settings['EXPORT_SHEETS'] ?? null) ? $settings['EXPORT_SHEETS'] : [];

        // список полей для выбора (базовые поля + свойства инфоблока)
        $groups = ProfilesHelper::getFieldGroups($profile, $id);

        return [
            'id' => $id,
            'iblock_id' => (int)($settings['IBLOCK_ID'] ?? 0),
            'sheets' => $savedSheets,
            'groups' => $groups,
        ];
    }

    /**
     * Сохранение настроек экспорта (этап 3).
     * Структура листа: {NAME: имя листа, COLUMNS: [{NAME: заголовок колонки, FIELD: поле}]}
     *
     * @param int $id ID профиля
     * @param array $sheets листы экспорта
     * @return array|null
     */
    public function saveSettingsAction(int $id = 0, array $sheets = [])
    {
        $id = (int)$id;
        if ($this->processNotDoneGuard($id)) return null;
        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];

        // CSV: лист может быть только один
        $filePath = (string)($settings['FILE_PATH'] ?? '');
        $isCsv = $filePath !== '' && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'csv';
        if ($isCsv) {
            $sheets = array_slice($sheets, 0, 1);
        }

        $normalized = [];
        $sheetNum = 0;
        foreach ($sheets as $sheet) {
            if (!is_array($sheet)) continue;
            $name = trim((string)($sheet['NAME'] ?? ''));
            if ($name === '') $name = 'SHEET'.($sheetNum + 1);

            $columns = [];
            foreach ((array)($sheet['COLUMNS'] ?? []) as $col) {
                if (!is_array($col)) continue;
                $field = trim((string)($col['FIELD'] ?? ''));
                if ($field === '') continue;

                // опции обработки значения (шестеренка в настройке колонок)
                $case = (string)($col['CASE'] ?? '');
                if (!in_array($case, ['lower', 'upper', 'firstupper'], true)) $case = '';

                // правила обработки — в формате импорта (TYPE/AGG/CONDITIONS/ACTION...)
                // поддерживается только действие skip (пропуск строки)
                $rules = [];
                foreach ((array)($col['RULES'] ?? []) as $rule) {
                    if (!is_array($rule)) continue;
                    $type = (string)($rule['TYPE'] ?? '');
                    if ($type === '' && (string)($rule['ACTION'] ?? '') === 'skip_row') {
                        // старый формат: {ACTION: skip_row, CONDITION: empty}
                        $rules[] = [
                            'TYPE' => 'skip',
                            'AGG' => 'AND',
                            'CONDITIONS' => [['OP' => 'empty', 'VALUE' => '', 'VALUE2' => '']],
                        ];
                        continue;
                    }
                    if ($type !== 'skip') continue;
                    $rules[] = [
                        'TYPE' => 'skip',
                        'AGG' => in_array(($rule['AGG'] ?? 'AND'), ['AND', 'OR', 'AND_NOT', 'OR_NOT'], true) ? $rule['AGG'] : 'AND',
                        'CONDITIONS' => self::normalizeExportConditions((array)($rule['CONDITIONS'] ?? [])),
                    ];
                }

                $columns[] = [
                    'NAME' => trim((string)($col['NAME'] ?? '')),
                    'FIELD' => $field,
                    'ACTIVE' => (($col['ACTIVE'] ?? 'Y') === 'N') ? 'N' : 'Y',
                    'TRIM' => (($col['TRIM'] ?? '') === 'Y') ? 'Y' : 'N',
                    'CASE' => $case,
                    'DELIMETER' => (string)($col['DELIMETER'] ?? ', '),
                    'RULES' => $rules,
                ];
            }
            if (!$columns) continue;

            $normalized['SHEET'.(++$sheetNum)] = [
                'NAME' => $name,
                'COLUMNS' => $columns,
            ];
        }

        if (!$normalized) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_SHEETS_EMPTY'),
                'SHEETS_EMPTY'
            ));
            return null;
        }

        $settings['EXPORT_SHEETS'] = $normalized;

        $result = ProfilesTable::update($id, ['SETTINGS' => $settings]);
        if ($result->isSuccess()) {
            return [
                'id' => $id,
                'sheets' => $normalized,
                'nextStepUrl' => '/bitrix/admin/awz_allxls_profiles_edit.php?id='.$id.'&step=4&lang='.LANGUAGE_ID
            ];
        }
        foreach ($result->getErrors() as $error) {
            $this->addError($error);
        }
        return null;
    }

    /**
     * HTML настроек колонки выгрузки для всплывающего окна (этап 3, экспорт).
     * Окно и правила оформлены как в импорте: те же классы awz-allxls-mapdlg
     * и тот же конструктор правил BX.TreeConditions (действие — пропуск строки).
     *
     * @param int   $id     ID профиля
     * @param int   $sheet  индекс листа (0..N-1)
     * @param int   $col    индекс колонки (0..N-1)
     * @param array $column текущая колонка с клиента (несохраненные изменения:
     *                      новая колонка еще не записана в EXPORT_SHEETS)
     * @return array|null
     */
    public function colsettingsAction(int $id = 0, int $sheet = 0, int $col = 0, array $columnCfg = [])
    {
        $id = (int)$id;
        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        $sheets = array_values(is_array($settings['EXPORT_SHEETS'] ?? null) ? $settings['EXPORT_SHEETS'] : []);
        $sheetCfg = $sheets[$sheet] ?? null;
        $column = is_array($sheetCfg) ? ((array)($sheetCfg['COLUMNS'] ?? [])[$col] ?? null) : null;
        // колонки еще нет в сохраненных настройках (добавлена на шаге 3 и не
        // сохранена) — используем текущее состояние с клиента
        if (!$column && trim((string)($columnCfg['FIELD'] ?? '')) !== '') {
            $column = $columnCfg;
        }
        if (!$column) {
            $this->addError(new \Bitrix\Main\Error('Колонка не найдена', 'COL_NOT_FOUND'));
            return null;
        }

        $name = (string)($column['NAME'] ?? '');
        if ($name === '') $name = (string)($column['FIELD'] ?? '');

        $trim = (($column['TRIM'] ?? 'N') === 'Y') ? ' checked' : '';
        $case = (string)($column['CASE'] ?? '');
        $dlmtr = (string)($column['DELIMETER'] ?? ', ');
        $rules = (isset($column['RULES']) && is_array($column['RULES'])) ? $column['RULES'] : [];

        $uniq = 'awz_exp_cond_' . md5($id . '|export|' . $sheet . '|' . $col) . '_' . mt_rand(1000, 999999);

        ob_start();
        ?>
        <div class="awz-allxls-mapdlg">
            <div class="awz-allxls-mapdlg__row">
                <label class="awz-allxls-mapdlg__label">Обрезать пробелы:</label>
                <input type="checkbox" name="MAP_TRIM"<?=$trim?> value="Y">
            </div>
            <div class="awz-allxls-mapdlg__row">
                <label class="awz-allxls-mapdlg__label">Регистр значения:</label>
                <select name="MAP_CASE">
                    <option value="">как есть</option>
                    <option value="lower"<?=$case === 'lower' ? ' selected' : ''?>>нижний</option>
                    <option value="upper"<?=$case === 'upper' ? ' selected' : ''?>>ВЕРХНИЙ</option>
                    <option value="firstupper"<?=$case === 'firstupper' ? ' selected' : ''?>>Заглавная (первая буква)</option>
                </select>
            </div>
            <div class="awz-allxls-mapdlg__row">
                <label class="awz-allxls-mapdlg__label">Разделитель множ. значения:</label>
                <input type="text" name="DELIMETER" value="<?=htmlspecialcharsbx($dlmtr)?>">
            </div>

            <div class="awz-allxls-mapdlg__section">Правила обработки</div>

            <?php
            $condRules = self::buildExportCondRules();
            $condValues = \Awz\Allxls\Api\Map::buildCondValues($rules);
            ?>
            <form id="<?= $uniq ?>_form" data-map-cond-form onsubmit="return false;">
                <div id="<?= $uniq ?>"></div>
            </form>
            <script>
                (window.AwzAllxlsMapCondQueue = window.AwzAllxlsMapCondQueue || []).push({
                    container: '<?= $uniq ?>',
                    form: '<?= $uniq ?>_form',
                    prefix: 'maprule',
                    rules: <?= \Bitrix\Main\Web\Json::encode($condRules) ?>,
                    values: <?= \Bitrix\Main\Web\Json::encode($condValues) ?>,
                    macros: {}
                });
            </script>
        </div>
        <?php
        $html = ob_get_clean();

        return [
            'id' => $id,
            'sheet' => $sheet,
            'col' => $col,
            'name' => $name,
            'html' => $html,
        ];
    }

    /**
     * Описания контролов BX.TreeConditions для правил экспорта.
     * В отличие от импорта доступно только одно действие — «Пропустить строку»,
     * условие проверяется по значению выгружаемой колонки.
     */
    public static function buildExportCondRules(): array
    {
        $groupVisual = [
            'controls' => ['fLevel', 'notLevel'],
            'values' => [
                ['fLevel' => 'AND', 'notLevel' => 'N'],
                ['fLevel' => 'OR', 'notLevel' => 'N'],
                ['fLevel' => 'AND', 'notLevel' => 'Y'],
                ['fLevel' => 'OR', 'notLevel' => 'Y'],
            ],
            'logic' => [
                ['style' => 'condition-logic-and', 'message' => 'И'],
                ['style' => 'condition-logic-or', 'message' => 'ИЛИ'],
                ['style' => 'condition-logic-and', 'message' => 'И НЕ'],
                ['style' => 'condition-logic-or', 'message' => 'ИЛИ НЕ'],
            ],
        ];

        $sourceValues = ['value' => 'Текущее значение колонки'];

        $rules = [];

        // корневая группа — набор правил
        $rules[] = [
            'controlId' => 'CondGroup',
            'defaultText' => '',
            'group' => true,
            'label' => 'Добавить правило',
            'showIn' => [],
            'visual' => [
                'controls' => ['fLevel'],
                'values' => [['fLevel' => 'AND']],
                'logic' => [['style' => 'condition-logic-and', 'message' => '+']],
            ],
            'control' => [
                'Выполнять ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все правила'],
                    'defaultText' => 'все правила',
                    'defaultValue' => 'AND',
                ],
            ],
        ];

        // условие (лист)
        $rules[] = [
            'controlId' => 'cond',
            'group' => false,
            'label' => 'Условие',
            'defaultText' => 'Условие',
            'showIn' => ['ruleSkip', 'condGroup'],
            'control' => [
                'Если значение ',
                [
                    'type' => 'select',
                    'id' => 'op',
                    'name' => 'op',
                    'values' => \Awz\Allxls\Api\Map::getRuleOperators(),
                    'defaultValue' => 'eq',
                ],
                [
                    'type' => 'input',
                    'id' => 'value',
                    'name' => 'value',
                    'defaultValue' => '',
                ],
                [
                    'type' => 'input',
                    'id' => 'value2',
                    'name' => 'value2',
                    'defaultValue' => '',
                ],
            ],
        ];

        // вложенная группа условий
        $rules[] = [
            'controlId' => 'condGroup',
            'group' => true,
            'label' => 'Группа условий',
            'defaultText' => 'Группа условий',
            'showIn' => ['ruleSkip', 'condGroup'],
            'visual' => $groupVisual,
            'control' => [
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        // единственное действие: пропуск строки
        $rules[] = [
            'controlId' => 'ruleSkip',
            'group' => true,
            'label' => 'Пропустить строку',
            'defaultText' => 'Пропустить строку',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Пропустить строку, если ',
                [
                    'id' => 'fLevel',
                    'name' => 'aggregator',
                    'type' => 'select',
                    'values' => ['AND' => 'все условия', 'OR' => 'любое из условий'],
                    'defaultValue' => 'AND',
                ],
                [
                    'id' => 'notLevel',
                    'name' => 'not',
                    'type' => 'select',
                    'values' => ['N' => 'выполнено(ы)', 'Y' => 'не выполнено(ы)'],
                    'defaultValue' => 'N',
                ],
            ],
        ];

        return $rules;
    }

    /**
     * Сохранение параметров запуска экспорта.
     */
    public function saveParamsAction(int $id = 0, int $hitTimeLimit = 20, int $maxExecutionTime = 0, string $logErrors = 'N', int $requestDelay = 0, string $stepByStep = 'N')
    {
        $id = (int)$id;
        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];
        $settings['EXPORT_HIT_TIME_LIMIT'] = max(0, $hitTimeLimit);
        $settings['EXPORT_MAX_EXECUTION_TIME'] = max(0, $maxExecutionTime);
        $settings['EXPORT_LOG_ERRORS'] = ($logErrors === 'Y') ? 'Y' : 'N';
        $settings['EXPORT_REQUEST_DELAY'] = max(0, $requestDelay);
        $settings['EXPORT_STEP_BY_STEP'] = ($stepByStep === 'Y') ? 'Y' : 'N';
        ProfilesTable::update($id, ['SETTINGS' => $settings]);
        return ['saved' => true];
    }

    /**
     * Принудительное завершение экспорта (файл остается на диске).
     */
    public function finishAction(int $id = 0)
    {
        $id = (int)$id;
        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        if (!is_array($settings)) $settings = [];
        $process = is_array($settings['PROCESS_EXPORT'] ?? null) ? $settings['PROCESS_EXPORT'] : [];
        $process['DONE'] = 'Y';
        $process['UPDATED'] = date('Y-m-d H:i:s');
        if (!empty($process['STATS']) && empty($process['STATS_VIEW'])) {
            $process['STATS_VIEW'] = $process['STATS'];
        }
        $settings['PROCESS_EXPORT'] = $process;
        ProfilesTable::update($id, ['SETTINGS' => $settings]);
        return ['finished' => true, 'file_url' => $this->fileUrl((string)($process['FILE'] ?? ''))];
    }

    /**
     * Пошаговый экспорт.
     *
     * @param int $id ID профиля
     * @param int $hitTimeLimit лимит времени хита, сек
     * @param int $maxExecutionTime макс. время скрипта, сек
     * @param string $reset Y — новый запуск (файл и состояние сбрасываются)
     * @param string $logErrors Y — писать ошибки в журнал
     * @return array|null
     */
    public function exportAction(int $id = 0, int $hitTimeLimit = 20, int $maxExecutionTime = 0, string $reset = 'N', string $logErrors = 'N', string $fromCron = 'N', int $requestDelay = 0, string $stepByStep = 'N')
    {
        $id = (int)$id;
        $hitTimeLimit = max(1, $hitTimeLimit);
        $reset = ($reset === 'Y');
        $logErrors = ($logErrors === 'Y');
        // режим «экспорт по одному элементу»: один элемент за запрос,
        // следующий — по кнопке «Продолжить» (JS)
        $stepByStep = ($stepByStep === 'Y');
        $requestDelay = max(0, $requestDelay);

        // профиль, выполняемый cron-процессом, может обрабатываться только самим cron
        if (!$this->cronRunGuard($id, $fromCron === 'Y')) return null;

        $profile = $this->getProfile($id);
        if ($profile === null) return null;

        $settings = $profile['SETTINGS'] ?? null;
        // ключи листов (SHEET1/SHEET2/...) приводим к числовым индексам 0..N-1
        $sheets = array_values(is_array($settings['EXPORT_SHEETS'] ?? null) ? $settings['EXPORT_SHEETS'] : []);
        $iblockId = (int)($settings['IBLOCK_ID'] ?? 0);

        if (!$sheets) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_SHEETS_EMPTY'), 'SHEETS_EMPTY'
            ));
            return null;
        }
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_IBLOCK_EMPTY'), 'IBLOCK_EMPTY'
            ));
            return null;
        }

        $scriptLimit = $maxExecutionTime > 0 ? $maxExecutionTime : ($hitTimeLimit + 10);
        if (function_exists('set_time_limit')) @set_time_limit($scriptLimit);
        if (function_exists('ini_set')) @ini_set('max_execution_time', (string)$scriptLimit);

        $hitStart = microtime(true);

        // состояние экспорта
        $state = is_array($settings['PROCESS_EXPORT'] ?? null) ? $settings['PROCESS_EXPORT'] : [];
        if ($reset) {
            $state = [];
        }
        // маркер cron-запуска (CLI): интерфейс показывает «выгрузка на crontab»
        $source = ($fromCron === 'Y') ? 'cron' : (string)($state['SOURCE'] ?? '');
        // heartbeat cron-процесса (для детекта падения cron, 3 мин без обновления)
        $cronAlive = ($fromCron === 'Y') ? time() : (int)($state['CRON_ALIVE'] ?? 0);
        // время старта текущего прогона (UI считает общее время как elapsed)
        $runStarted = (int)($state['RUN_STARTED'] ?? 0);
        if ($reset || $runStarted <= 0) {
            $runStarted = time();
        }
        $sheetIdx = (int)($state['SHEET'] ?? 0);
        $lastId = (int)($state['LAST_ID'] ?? 0);
        $processed = (int)($state['PROCESSED'] ?? 0);
        $this->processStats = is_array($state['STATS'] ?? null) ? $state['STATS'] : [];
        $this->processErrors = is_array($state['ERRORS'] ?? null) ? $state['ERRORS'] : [];

        // путь к файлу выгрузки — из настроек профиля (этап 2), по умолчанию profile_<id>.xlsx
        $relDir = '/upload/awz.allxls/export/';
        $absDir = \Bitrix\Main\Application::getDocumentRoot().$relDir;
        if (!is_dir($absDir)) @mkdir($absDir, BX_DIR_PERMISSIONS ?? 0755, true);
        $relPath = (string)($settings['FILE_PATH'] ?? '');
        if ($relPath === '' || strpos($relPath, '..') !== false) {
            $relPath = $relDir.'profile_'.$id.'.xlsx';
        }
        $absPath = \Bitrix\Main\Application::getDocumentRoot().$relPath;
        $isCsv = strtolower(pathinfo($absPath, PATHINFO_EXTENSION)) === 'csv';
        if ($isCsv) {
            // CSV: только один лист
            $sheets = array_slice($sheets, 0, 1);
            $sheetIdx = 0;
        }

        if ($reset && file_exists($absPath)) {
            @unlink($absPath);
        }

        try {
            // открываем книгу: существующую или новую (листы по конфигу)
            if (file_exists($absPath)) {
                $spreadsheet = IOFactory::load($absPath);
            } else {
                $spreadsheet = new Spreadsheet();
                $spreadsheet->removeSheetByIndex(0);
                foreach ($sheets as $sheetCfg) {
                    $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet(
                        $spreadsheet, (string)($sheetCfg['NAME'] ?? 'SHEET')
                    );
                    $spreadsheet->addSheet($sheet);
                }
                // строка заголовков из имен колонок (только активные)
                foreach ($sheets as $i => $sheetCfg) {
                    $ws = $spreadsheet->getSheet($i);
                    $col = 1;
                    foreach ((array)($sheetCfg['COLUMNS'] ?? []) as $column) {
                        if (is_array($column) && ($column['ACTIVE'] ?? 'Y') === 'N') continue;
                        $ws->setCellValue(Coordinate::stringFromColumnIndex($col).'1', (string)($column['NAME'] ?? ''));
                        $col++;
                    }
                }
            }

            $done = false;
            $batchSize = $stepByStep ? 1 : 100;

            while ((microtime(true) - $hitStart) < $hitTimeLimit) {
                if ($sheetIdx >= count($sheets)) {
                    $done = true;
                    break;
                }
                $sheetCfg = $sheets[$sheetIdx];
                $ws = $spreadsheet->getSheet($sheetIdx);

                // активные колонки листа (ACTIVE != N)
                $activeColumns = [];
                foreach ((array)($sheetCfg['COLUMNS'] ?? []) as $column) {
                    if (is_array($column) && ($column['ACTIVE'] ?? 'Y') === 'N') continue;
                    $activeColumns[] = $column;
                }

                // выборка порции элементов инфоблока (по возрастанию ID, после LAST_ID)
                $select = ['ID'];
                foreach ($activeColumns as $column) {
                    $field = (string)($column['FIELD'] ?? '');
                    if ($field === '' || in_array($field, $select, true)) continue;
                    $select[] = $field;
                }

                $rows = [];
                $res = \CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    ['IBLOCK_ID' => $iblockId, '>ID' => $lastId],
                    false,
                    ['nTopCount' => $batchSize],
                    $select
                );
                while ($ob = $res->GetNextElement()) {
                    $rows[] = $ob->GetFields();
                }

                if (!$rows) {
                    // лист закончился — следующий
                    $sheetIdx++;
                    $lastId = 0;
                    continue;
                }

                $rowNum = $ws->getHighestRow();
                if ($rowNum < 1) $rowNum = 1;
                foreach ($rows as $fields) {
                    // обработка значений колонок + правила (пропуск строки)
                    $rowValues = [];
                    $skipRow = false;
                    $debugRow = null;
                    if ($stepByStep) {
                        $debugRow = [
                            'element_id' => (int)($fields['ID'] ?? 0),
                            'columns' => [],
                            'skip' => false,
                            'skip_reason' => '',
                        ];
                    }
                    foreach ($activeColumns as $column) {
                        $rawValue = $this->extractFieldValue(
                            $fields,
                            (string)($column['FIELD'] ?? ''),
                            (string)($column['DELIMETER'] ?? ', ')
                        );
                        $value = $this->applyColumnValue($rawValue, is_array($column) ? $column : []);
                        if ($stepByStep && is_array($debugRow)) {
                            $debugRow['columns'][] = [
                                'name' => (string)($column['NAME'] ?? ''),
                                'field' => (string)($column['FIELD'] ?? ''),
                                'raw' => $rawValue,
                                'value' => $value,
                            ];
                        }
                        if ($this->columnRuleSkipsRow($value, is_array($column) ? $column : [])) {
                            $skipRow = true;
                            if ($stepByStep && is_array($debugRow)) {
                                $debugRow['skip'] = true;
                                $debugRow['skip_reason'] = 'Правило пропуска строки в колонке «'
                                    .(string)($column['NAME'] ?? '').'» (значение: '.$value.')';
                            }
                            break;
                        }
                        $rowValues[] = $value;
                    }
                    if ($skipRow) {
                        $rowNum--; // строка не используется
                        $lastId = (int)($fields['ID'] ?? $lastId);
                        if ($stepByStep) {
                            if (is_array($debugRow)) {
                                // последняя колонка дала пропуск — убираем её значения из дебага
                                $debugRow['columns'] = array_slice($debugRow['columns'], 0, -1);
                            }
                            $this->processStats['Пропущено строк'] = ((int)($this->processStats['Пропущено строк'] ?? 0)) + 1;
                            break 2;
                        }
                        continue;
                    }

                    $rowNum++;
                    $col = 1;
                    foreach ($rowValues as $value) {
                        $ws->setCellValue(Coordinate::stringFromColumnIndex($col).$rowNum, $value);
                        $col++;
                    }
                    $lastId = (int)($fields['ID'] ?? 0);
                    $processed++;
                    $this->processStats['Выгружено строк'] = $processed;
                    $this->processStats['Текущий лист'] = (string)($sheetCfg['NAME'] ?? ('SHEET'.($sheetIdx + 1)));
                    if ($stepByStep) {
                        if (is_array($debugRow)) {
                            $debugRow['row'] = $rowNum;
                            $debugRow['sheet'] = (string)($sheetCfg['NAME'] ?? ('SHEET'.($sheetIdx + 1)));
                        }
                        break 2;
                    }
                }
                $this->processStats['Прогресс, %'] = 0; // точный процент неизвестен до завершения
            }

            if ($sheetIdx >= count($sheets) && !$rows) {
                $done = true;
            }

            // сохраняем файл книги (CSV — с настроенным разделителем)
            if ($isCsv) {
                $writer = IOFactory::createWriter($spreadsheet, 'Csv');
                if (method_exists($writer, 'setDelimiter')) {
                    $writer->setDelimiter((string)($settings['CSV_DELIMITER'] ?? ',') ?: ',');
                }
            } else {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            }
            $writer->save($absPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            // конвертация CSV-выгрузки в выбранную кодировку (utf8 | cp1251 | other)
            if ($isCsv) {
                $csvEncoding = (string)($settings['CSV_ENCODING'] ?? 'utf8');
                if ($csvEncoding === 'cp1251') {
                    $targetCharset = 'Windows-1251';
                } elseif ($csvEncoding === 'other' && (string)($settings['CSV_ENCODING_CUSTOM'] ?? '') !== '') {
                    $targetCharset = (string)$settings['CSV_ENCODING_CUSTOM'];
                } else {
                    $targetCharset = '';
                }
                if ($targetCharset !== '' && strcasecmp($targetCharset, 'UTF-8') !== 0 && is_file($absPath)) {
                    $content = @file_get_contents($absPath);
                    if ($content !== false) {
                        $converted = @mb_convert_encoding($content, $targetCharset, 'UTF-8');
                        if ($converted !== false) {
                            @file_put_contents($absPath, $converted);
                        }
                    }
                }
            }

            // накопленное общее время работы экспорта (продолжается между вызовами)
            $elapsed = microtime(true) - $hitStart;
            $totalTime = (float)($state['TOTAL_TIME'] ?? 0) + $elapsed;
            $totalSec = (int)round($totalTime);
            $this->processStats['Общее время работы'] =
                (intdiv($totalSec, 60) > 0 ? intdiv($totalSec, 60) . ' мин ' : '') . ($totalSec % 60) . ' сек';
            // скорость выгрузки: средняя за весь экспорт и за последний хит
            $totalRows = (int)($this->processStats['Выгружено строк'] ?? 0);
            $this->processStats['RPS: среднее'] =
                ($totalTime > 0 && $totalRows > 0) ? round($totalRows / $totalTime, 1) : 0;
            $rowsInHit = max(0, $processed - (int)($state['PROCESSED'] ?? 0));
            $this->processStats['RPS: последний хит'] =
                ($elapsed > 0 && $rowsInHit > 0) ? round($rowsInHit / $elapsed, 1) : 0;

            // сохраняем состояние
            $state = [
                'SHEET' => $done ? 0 : $sheetIdx,
                'LAST_ID' => $done ? 0 : $lastId,
                'PROCESSED' => $processed,
                'STATS' => $this->processStats,
                // «живой» дамп для отображения прогресса cron-запуска в UI
                'STATS_VIEW' => $this->processStats,
                'ERRORS' => $this->processErrors,
                'FILE' => $relPath,
                'DONE' => $done ? 'Y' : 'N',
                'UPDATED' => date('Y-m-d H:i:s'),
                'TOTAL_TIME' => $totalTime,
                // маркер cron-запуска (CLI) + heartbeat + старт прогона
                'SOURCE' => ($source !== '') ? $source : null,
                'CRON_ALIVE' => ($cronAlive > 0) ? $cronAlive : null,
                'RUN_STARTED' => $runStarted,
            ];
            $settings['PROCESS_EXPORT'] = $state;
            ProfilesTable::update($id, ['SETTINGS' => $settings]);

            if ($done) {
                $this->processStats['Прогресс, %'] = 100;
            }

            return [
                'processed' => $processed,
                'total' => null,
                'done' => $done,
                'percent' => $done ? 100 : 0,
                'errors' => $this->processErrors,
                'stats' => $this->processStats,
                'file_url' => $this->fileUrl($relPath),
                'step_by_step' => $stepByStep,
                // подробная информация по выгруженному элементу (режим по одному)
                'debug' => (is_array($debugRow ?? null)) ? $debugRow : null,
                'item' => (is_array($debugRow ?? null))
                    ? ['id' => $debugRow['element_id'], 'skip' => $debugRow['skip']]
                    : null,
            ];
        } catch (\Throwable $e) {
            if ($logErrors) {
                \CEventLog::Add([
                    'SEVERITY' => 'ERROR',
                    'AUDIT_TYPE_ID' => 'AWZ_ALLXLS_PEXPORT_'.$id,
                    'MODULE_ID' => 'awz.allxls',
                    'ITEM_ID' => 'PROFILE#'.$id,
                    'DESCRIPTION' => $e->getMessage().' ('.$e->getTraceAsString().')',
                ]);
            }
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Значение поля элемента для выгрузки.
     * Базовые поля — напрямую, свойства — PROPERTY_<CODE>_VALUE (множественные склеиваются).
     */
    protected function extractFieldValue(array $fields, string $field, string $delimiter = ', '): string
    {
        if ($field === '') return '';
        $delimiter = $delimiter !== '' ? $delimiter : ', ';
        if (strpos($field, 'PROPERTY_') === 0) {
            $value = $fields[$field.'_VALUE'] ?? ($fields[$field] ?? '');
            if (is_array($value)) {
                $value = implode($delimiter, array_map('strval', $value));
            }
            return (string)$value;
        }
        $value = $fields[$field] ?? '';
        if (is_array($value)) {
            $value = implode($delimiter, array_map('strval', $value));
        }
        return (string)$value;
    }

    /**
     * Применение опций обработки колонки к значению: TRIM, CASE.
     */
    protected function applyColumnValue(string $value, array $column): string
    {
        if (($column['TRIM'] ?? '') === 'Y') {
            $value = trim($value);
        }
        $case = (string)($column['CASE'] ?? '');
        if ($case !== '' && $value !== '') {
            switch ($case) {
                case 'lower':
                    $value = mb_strtolower($value, 'UTF-8');
                    break;
                case 'upper':
                    $value = mb_strtoupper($value, 'UTF-8');
                    break;
                case 'firstupper':
                    $value = mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
                        .mb_strtolower(mb_substr($value, 1, null, 'UTF-8'), 'UTF-8');
                    break;
            }
        }
        return $value;
    }

    /**
     * Нормализация дерева условий правил экспорта.
     */
    protected static function normalizeExportConditions(array $conditions): array
    {
        $result = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond)) continue;
            if (isset($cond['CHILDREN']) && is_array($cond['CHILDREN'])) {
                $agg = (string)($cond['AGG'] ?? 'AND');
                $result[] = [
                    'AGG' => in_array($agg, ['AND', 'OR', 'AND_NOT', 'OR_NOT'], true) ? $agg : 'AND',
                    'CHILDREN' => self::normalizeExportConditions($cond['CHILDREN']),
                ];
                continue;
            }
            $result[] = [
                'OP' => (string)($cond['OP'] ?? 'eq'),
                'VALUE' => (string)($cond['VALUE'] ?? ''),
                'VALUE2' => (string)($cond['VALUE2'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Проверка правил колонки: нужно ли пропустить строку.
     * Поддерживается одно действие: skip (пропуск строки) с деревом условий
     * по значению колонки (формат правил как в импорте).
     */
    protected function columnRuleSkipsRow(string $value, array $column): bool
    {
        foreach ((array)($column['RULES'] ?? []) as $rule) {
            if (!is_array($rule)) continue;
            $type = (string)($rule['TYPE'] ?? '');
            // обратная совместимость со старым форматом {ACTION: skip_row}
            if ($type === '' && (string)($rule['ACTION'] ?? '') === 'skip_row') {
                $condition = (string)($rule['CONDITION'] ?? 'empty');
                if ($condition === 'empty' && trim($value) === '') return true;
                continue;
            }
            if ($type !== 'skip') continue;

            $agg = (string)($rule['AGG'] ?? 'AND');
            $conditions = (array)($rule['CONDITIONS'] ?? []);
            $matched = $this->evaluateExportConditions($value, $conditions, $agg);
            if ($matched) return true;
        }
        return false;
    }

    /**
     * Вычисление дерева условий правила по значению колонки.
     *
     * @param string $value значение колонки
     * @param array $conditions дерево условий
     * @param string $agg AND|OR|AND_NOT|OR_NOT
     * @return bool
     */
    protected function evaluateExportConditions(string $value, array $conditions, string $agg = 'AND'): bool
    {
        $conditions = array_values(array_filter($conditions, 'is_array'));
        if (!$conditions) return false;

        $isOr = (strpos($agg, 'OR') === 0);
        $not = substr($agg, -4) === '_NOT';

        $results = [];
        foreach ($conditions as $cond) {
            if (isset($cond['CHILDREN']) && is_array($cond['CHILDREN'])) {
                $childAgg = (string)($cond['AGG'] ?? 'AND');
                $results[] = $this->evaluateExportConditions($value, $cond['CHILDREN'], $childAgg);
            } else {
                $results[] = $this->evaluateExportCondition($value, $cond);
            }
        }

        $result = $isOr ? in_array(true, $results, true) : !in_array(false, $results, true);
        return $not ? !$result : $result;
    }

    /**
     * Вычисление одного условия (листа) по значению колонки.
     */
    protected function evaluateExportCondition(string $value, array $cond): bool
    {
        $op = (string)($cond['OP'] ?? 'eq');
        $v = (string)($cond['VALUE'] ?? '');
        $v2 = (string)($cond['VALUE2'] ?? '');

        switch ($op) {
            case 'empty':
                return trim($value) === '';
            case 'not_empty':
                return trim($value) !== '';
            case 'any':
                return true;
            case 'eq':
                return $value === $v;
            case 'ne':
                return $value !== $v;
            case 'gt':
                return is_numeric($value) && is_numeric($v) && ($value > $v);
            case 'lt':
                return is_numeric($value) && is_numeric($v) && ($value < $v);
            case 'ge':
                return is_numeric($value) && is_numeric($v) && ($value >= $v);
            case 'le':
                return is_numeric($value) && is_numeric($v) && ($value <= $v);
            case 'between':
                return is_numeric($value) && is_numeric($v) && is_numeric($v2)
                    && ($value >= $v && $value <= $v2);
            case 'contains':
                return $v !== '' && mb_stripos($value, $v) !== false;
            case 'not_contains':
                return $v === '' || mb_stripos($value, $v) === false;
            case 'begins':
                return $v !== '' && mb_stripos($value, $v) === 0;
            case 'ends':
                $len = mb_strlen($v);
                return $len > 0 && mb_strripos($value, $v) === mb_strlen($value) - $len;
            case 'regex':
                return @preg_match($v, $value) === 1;
            case 'not_regex':
                return @preg_match($v, $value) !== 1;
            default:
                return false;
        }
    }

    /**
     * URL файла выгрузки (относительный).
     */
    protected function fileUrl(string $relPath): string
    {
        return $relPath !== '' ? $relPath : '';
    }

    /**
     * Загрузка профиля с проверкой типа (export).
     */
    protected function getProfile(int $id): ?array
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
        return $profile;
    }
}
