<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Services\ExportBase;
use Awz\Allxls\Services\iblockexport;
use Awz\Allxls\MapRules;
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
 *
 * Источник данных — сервис приёмника (Awz\Allxls\Services\ExportBase, по
 * DEST_TYPE: 'awz.allxls.<dest_type>export' из ServiceLocator, по умолчанию
 * awz.allxls.iblockexport). Подмена — событие onAwzAllxlsResolveExportService.
 * JSON-фильтр выборки (SETTINGS['EXPORT_FILTER'], шаг 2) передается в сервис.
 * Правила обработки колонок — движок импорта Awz\Allxls\MapRules
 * (TYPE=transform|skip: действия преобразования + пропуск строки).
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
     * Резолв сервиса-источника экспорта по DEST_TYPE профиля.
     *
     * Код сервиса: 'awz.allxls.<dest_type>export' (по умолчанию iblockexport).
     * Подмена/расширение — событие D7 onAwzAllxlsResolveExportService
     * (обработчик меняет параметр service — объект Services\ExportBase —
     * и добавляет EventResult(SUCCESS, [])).
     *
     * @param array $settings настройки профиля
     * @return ExportBase
     */
    protected function resolveService(array $settings): ExportBase
    {
        $destType = (string)($settings['DEST_TYPE'] ?? 'iblock');
        if ($destType === '') $destType = 'iblock';
        $service = null;
        $code = 'awz.allxls.'.$destType.'export';
        try {
            $locator = \Bitrix\Main\DI\ServiceLocator::getInstance();
            if ($locator->has($code)) {
                $service = $locator->get($code);
            }
        } catch (\Throwable $e) {
            $service = null;
        }
        if (!$service instanceof ExportBase) {
            $service = new iblockexport();
        }

        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsResolveExportService', [
            'dest_type' => $destType,
            'service' => $service,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (isset($params['service']) && $params['service'] instanceof ExportBase) {
                    $service = $params['service'];
                }
            }
        }
        return $service;
    }

    /**
     * JSON-фильтр выборки элементов (шаг 2) в виде массива.
     *
     * @param array $settings настройки профиля
     * @return array (невалидный/пустой JSON — пустой массив)
     */
    protected function getExportFilter(array $settings): array
    {
        $raw = trim((string)($settings['EXPORT_FILTER'] ?? ''));
        if ($raw === '') return [];
        try {
            $decoded = \Bitrix\Main\Web\Json::decode($raw);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Итоговый фильтр выборки записей для сервиса экспорта.
     *
     * База — JSON-фильтр профиля (SETTINGS['EXPORT_FILTER'], шаг 2).
     * Финальный фильтр можно изменить обработчиком события D7
     * onAwzAllxlsBuildExportFilter (модуль awz.allxls):
     *
     *   EventManager::getInstance()->addEventHandler(
     *       'awz.allxls', 'onAwzAllxlsBuildExportFilter',
     *       function (\Bitrix\Main\Event $event) {
     *           $filter = $event->getParameter('filter');   // текущий массив фильтра
     *           $filter['ACTIVE'] = 'Y';                    // ... свои условия
     *           $event->setParameter('filter', $filter);
     *           $event->addResult(new \Bitrix\Main\EventResult(
     *               \Bitrix\Main\EventResult::SUCCESS, []
     *           ));
     *       }
     *   );
     *
     * Параметры события: id (ID профиля), dest_type, entity_id, filter, settings.
     *
     * @param int $id ID профиля
     * @param array $settings настройки профиля
     * @return array фильтр в формате сервиса-источника
     */
    protected function buildExportFilter(int $id, array $settings): array
    {
        $destType = (string)($settings['DEST_TYPE'] ?? 'iblock');
        $entityId = (string)($settings['IBLOCK_ID'] ?? '');
        $filter = $this->getExportFilter($settings);

        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsBuildExportFilter', [
            'id' => $id,
            'dest_type' => $destType,
            'entity_id' => $entityId,
            'filter' => $filter,
            'settings' => $settings,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            $params = $result->getParameters();
            if (isset($params['filter']) && is_array($params['filter'])) {
                $filter = $params['filter'];
            }
        }
        return $filter;
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

        // список полей для выбора — из сервиса-источника экспорта,
        // расширение/замена — обработчиком события onAwzAllxlsGetExportFields
        $groups = $this->getExportFields($id, $profile, is_array($settings) ? $settings : []);

        return [
            'id' => $id,
            'iblock_id' => (int)($settings['IBLOCK_ID'] ?? 0),
            'sheets' => is_array($settings['EXPORT_SHEETS'] ?? null) ? $settings['EXPORT_SHEETS'] : [],
            'groups' => $groups,
            'json_template' => (string)($settings['EXPORT_JSON_TEMPLATE'] ?? ''),
        ];
    }

    /**
     * Группированный список полей источника для селектов колонок (шаг 3).
     *
     * База — сервис-источник экспорта (Services\ExportBase::getFields,
     * для iblockexport — ProfilesHelper::getFieldGroups: базовые поля +
     * свойства инфоблока + цены/каталог/склады + событие onAwzAllxlsGetProfileFields).
     *
     * Расширение/замена списка — обработчиком события D7
     * onAwzAllxlsGetExportFields (модуль awz.allxls):
     *
     *   EventManager::getInstance()->addEventHandler(
     *       'awz.allxls', 'onAwzAllxlsGetExportFields',
     *       function (\Bitrix\Main\Event $event) {
     *           $groups = $event->getParameter('GROUPS');
     *           $groups[] = ['NAME' => 'Мои поля', 'ITEMS' => [['ID' => 'MY_FIELD', 'NAME' => 'Мое поле']]];
     *           $event->setParameter('GROUPS', $groups);
     *           $event->addResult(new \Bitrix\Main\EventResult(
     *               \Bitrix\Main\EventResult::SUCCESS, []
     *           ));
     *       }
     *   );
     *
     * Параметры события: PROFILE_ID, DEST_TYPE, ENTITY_ID, GROUPS, SETTINGS.
     *
     * @param int $id ID профиля
     * @param array $profile строка профиля
     * @param array $settings настройки профиля
     * @return array
     */
    protected function getExportFields(int $id, array $profile, array $settings): array
    {
        $service = $this->resolveService($settings);
        $entityId = (string)($settings['IBLOCK_ID'] ?? '');
        $groups = $service->getFields($entityId);

        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetExportFields', [
            'PROFILE_ID' => $id,
            'DEST_TYPE' => (string)($settings['DEST_TYPE'] ?? 'iblock'),
            'ENTITY_ID' => $entityId,
            'GROUPS' => $groups,
            'SETTINGS' => $settings,
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (is_array($params['GROUPS'] ?? null)) {
                    $groups = $params['GROUPS'];
                }
            }
        }
        return $groups;
    }

    /**
     * Сохранение настроек экспорта (этап 3).
     * Структура листа: {NAME: имя листа, COLUMNS: [{NAME: заголовок колонки, FIELD: поле}]}
     *
     * @param int $id ID профиля
     * @param array $sheets листы экспорта
     * @return array|null
     */
    public function saveSettingsAction(int $id = 0, array $sheets = [], string $json_template = '')
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

        // JSON-шаблон корня выгрузки (макросы #<ИМЯ ЛИСТА>#) — только для формата json
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'json') {
            $settings['EXPORT_JSON_TEMPLATE'] = trim($json_template);
        } else {
            $settings['EXPORT_JSON_TEMPLATE'] = '';
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

                // правила обработки — полный формат импорта (MapRules):
                // TYPE=transform (действие преобразования) и TYPE=skip (пропуск строки)
                $rules = self::normalizeExportRules((array)($col['RULES'] ?? []));

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
        // правила приводим к формату импорта (MapRules) — старые форматы конвертируются
        $rules = self::normalizeExportRules(
            (isset($column['RULES']) && is_array($column['RULES'])) ? $column['RULES'] : []
        );

        $uniq = 'awz_exp_cond_' . md5($id . '|export|' . $sheet . '|' . $col) . '_' . mt_rand(1000, 999999);

        // источники условий и макросы — по колонкам листа (формат импорта:
        // ключ колонки = код поля, HEADER = заголовок колонки)
        $pseudoColumns = [];
        foreach ((array)($sheetCfg['COLUMNS'] ?? []) as $colKey => $colCfg) {
            if (!is_array($colCfg)) continue;
            $field = (string)($colCfg['FIELD'] ?? '');
            if ($field === '') continue;
            $pseudoColumns[$field] = [
                'LETTER' => (string)($colKey + 1),
                'HEADER' => (string)($colCfg['NAME'] ?? ''),
                'ALIAS' => '',
            ];
        }
        $condRules = self::buildExportCondRules($pseudoColumns, (string)($column['FIELD'] ?? ''));
        $condValues = \Awz\Allxls\Api\Map::buildCondValues($rules);
        $macros = self::buildExportMacros($pseudoColumns);

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
                    macros: <?= \Bitrix\Main\Web\Json::encode($macros) ?>
                });
            </script>
            <style>
            <?foreach(\Awz\Allxls\Api\Map::getRuleActions(true) as $visibleId=>$visibleAr){?>
                <?foreach(['aval','aval2'] as $action){?>
                    <?if(!in_array($action, $visibleAr)){?>
                    .awz-allxls-mapdlg a[data-a="<?=$visibleId?>"][data-inp="<?=$action?>"], .awz-allxls-mapdlg a[data-a="<?=$visibleId?>"][data-inp="<?=$action?>"] + input + a {display: none!important;}
                    <?}?>
                <?}?>
            <?}?>
            <?foreach(\Awz\Allxls\Api\Map::getRuleOperators(true) as $visibleId=>$visibleAr){?>
                <?foreach(['value','value2'] as $action){?>
                    <?if(!in_array($action, $visibleAr)){?>
                    .awz-allxls-mapdlg a[data-op="<?=$visibleId?>"][data-inp="<?=$action?>"], .awz-allxls-mapdlg a[data-op="<?=$visibleId?>"][data-inp="<?=$action?>"] + input + a {display: none!important;}
                    <?}?>
                <?}?>
            <?}?>
            </style>
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
     * Описания контролов BX.TreeConditions для правил экспорта (как в импорте).
     * Доступные правила: transform (действие преобразования значения) и
     * skip (пропуск строки); условия — по любой колонке листа (SOURCE),
     * вложенные группы без ограничений.
     *
     * @param array $columns псевдоколонки листа (код поля => {LETTER, HEADER})
     * @param string $from код поля текущей колонки
     * @return array
     */
    public static function buildExportCondRules(array $columns = [], string $from = ''): array
    {
        $currentSourceValues = \Awz\Allxls\Api\Map::getRuleCurrentValues([], $columns, $from, '');

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

        // условие (лист) — источник: колонки листа / макросы
        $rules[] = [
            'controlId' => 'cond',
            'group' => false,
            'label' => 'Условие',
            'defaultText' => 'Условие',
            'showIn' => ['ruleTransform', 'ruleSkip', 'condGroup'],
            'control' => [
                'Если ',
                [
                    'type' => 'select',
                    'id' => 'source',
                    'name' => 'source',
                    'values' => $currentSourceValues,
                    'defaultValue' => isset($currentSourceValues[$from]) ? $from : '',
                ],
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
            'showIn' => ['ruleTransform', 'ruleSkip', 'condGroup'],
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

        // правило: преобразовать значение выгрузки
        $rules[] = [
            'controlId' => 'ruleTransform',
            'group' => true,
            'label' => 'Преобразовать значение',
            'defaultText' => 'Преобразовать значение',
            'showIn' => ['CondGroup'],
            'visual' => $groupVisual,
            'control' => [
                'Действие со значением: ',
                [
                    'type' => 'select',
                    'id' => 'action',
                    'name' => 'action',
                    'values' => \Awz\Allxls\Api\Map::getRuleActions(),
                    'defaultValue' => 'replace',
                ],
                [
                    'type' => 'input',
                    'id' => 'aval',
                    'name' => 'aval',
                    'defaultValue' => '',
                ],
                [
                    'type' => 'input',
                    'id' => 'aval2',
                    'name' => 'aval2',
                    'defaultValue' => '',
                ],
                'Если ',
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

        // правило: пропустить строку выгрузки
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
     * Макросы правил экспорта: значение колонки-источника + макросы по
     * колонкам листа (#COL_<КОД ПОЛЯ># — начальное значение).
     *
     * @param array $columns псевдоколонки листа (код поля => {LETTER, HEADER})
     * @return array
     */
    protected static function buildExportMacros(array $columns = []): array
    {
        $macros = [
            '#VALUE#' => 'Начальное значение колонки-источника (до правил)',
            '#VALUE_PROCESSED#' => 'Текущее (обработанное) значение колонки-источника',
        ];
        foreach ($columns as $k => $v) {
            $letter = (string)($v['LETTER'] ?? '');
            $title = 'Колонка ' . ($letter ?: $k) . ($v['HEADER'] ? ': ' . $v['HEADER'] : '');
            $macros['#COL_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $k)) . '#'] =
                $title . ' — начальное значение';
            $macros['#COL_PROCESSED_' . strtoupper(preg_replace('/[^a-z0-9]/i', '', $k)) . '#'] =
                $title . ' — обработанное значение';
        }
        // событие D7: сторонние модули добавляют свои макросы
        $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetMacros', [
            'macros' => $macros,
            'columns' => $columns,
            'groups' => [],
        ]);
        $event->send();
        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                $params = $result->getParameters();
                if (isset($params['macros']) && is_array($params['macros'])) {
                    $macros = $params['macros'] + $macros;
                }
            }
        }
        return $macros;
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
        // событие финализации экспорта (до записи состояния в профиль)
        $this->fireExportFinishEvent($id, $settings);
        ProfilesTable::update($id, ['SETTINGS' => $settings]);
        return ['finished' => true, 'file_url' => $this->fileUrl((string)($process['FILE'] ?? ''))];
    }

    /**
     * Событие финализации экспорта (до записи завершенного состояния в профиль):
     * 'awz.allxls', 'onAwzAllxlsExportFinish'.
     * Обработчик может изменить настройки профиля: вернуть EventResult(SUCCESS,
     * ['settings' => [...]]) — массив заменит SETTINGS перед ProfilesTable::update.
     *
     * @param int $id ID профиля
     * @param array $settings настройки профиля (могут быть заменены обработчиками)
     */
    protected function fireExportFinishEvent(int $id, array &$settings): void
    {
        try {
            $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsExportFinish', [
                'profile_id' => $id,
                'settings' => $settings,
            ]);
            $event->send();
            foreach ($event->getResults() as $result) {
                if ($result->getType() !== \Bitrix\Main\EventResult::SUCCESS) continue;
                $params = $result->getParameters();
                if (is_array($params['settings'] ?? null)) {
                    $settings = $params['settings'];
                }
            }
        } catch (\Throwable $e) {
            \Awz\Allxls\Logger::warning(
                'Ошибка обработчика onAwzAllxlsExportFinish',
                ['profile_id' => $id, 'error' => $e->getMessage()]
            );
        }
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
        if (!is_array($settings)) $settings = [];
        // ключи листов (SHEET1/SHEET2/...) приводим к числовым индексам 0..N-1
        $sheets = array_values(is_array($settings['EXPORT_SHEETS'] ?? null) ? $settings['EXPORT_SHEETS'] : []);
        $iblockId = (int)($settings['IBLOCK_ID'] ?? 0);

        if (!$sheets) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_SHEETS_EMPTY'), 'SHEETS_EMPTY'
            ));
            return null;
        }
        if ($iblockId <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PEXPORT_IBLOCK_EMPTY'), 'IBLOCK_EMPTY'
            ));
            return null;
        }

        // сервис-источник экспорта (DEST_TYPE, подмена через событие) и
        // итоговый фильтр выборки записей (JSON-фильтр шага 2 + событие)
        $service = $this->resolveService($settings);
        $exportFilter = $this->buildExportFilter($id, $settings);

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

        // путь к файлу выгрузки — из настроек профиля (этап 2), по умолчанию profile_<id>.xlsx.
        // Выгрузка идет во временный файл /upload/tmp/awz.allxls/export/ и копируется
        // по финальному пути при завершении (DONE) — промежуточное состояние файла недоступно
        $relDir = '/upload/awz.allxls/export/';
        $absDir = \Bitrix\Main\Application::getDocumentRoot().$relDir;
        if (!is_dir($absDir)) @mkdir($absDir, BX_DIR_PERMISSIONS ?? 0755, true);
        // формат выгрузки: сначала EXPORT_FILE_EXT (для json имя файла не задается),
        // затем по расширению FILE_PATH, по умолчанию xlsx
        $fileExt = strtolower((string)($settings['EXPORT_FILE_EXT'] ?? ''));
        if ($fileExt === '') {
            $fileExt = strtolower(pathinfo((string)($settings['FILE_PATH'] ?? ''), PATHINFO_EXTENSION));
        }
        if (!in_array($fileExt, ['csv', 'xlsx', 'json'], true)) $fileExt = 'xlsx';
        $finalPath = (string)($settings['FILE_PATH'] ?? '');
        if ($finalPath === '' || strpos($finalPath, '..') !== false) {
            $finalPath = $relDir.'profile_'.$id.'.'.$fileExt;
        }
        $tmpDir = '/upload/tmp/awz.allxls/export/';
        $tmpAbsDir = \Bitrix\Main\Application::getDocumentRoot().$tmpDir;
        if (!is_dir($tmpAbsDir)) @mkdir($tmpAbsDir, BX_DIR_PERMISSIONS ?? 0755, true);
        $tmpExt = $fileExt;
        $relPath = $tmpDir.'profile_'.$id.'.'.$tmpExt;
        $absPath = \Bitrix\Main\Application::getDocumentRoot().$relPath;
        $isCsv = $fileExt === 'csv';
        $isJson = $fileExt === 'json';
        if ($isCsv) {
            // CSV — только один лист
            $sheets = array_slice($sheets, 0, 1);
            $sheetIdx = 0;
        }

        if ($reset && file_exists($absPath)) {
            @unlink($absPath);
        }

        try {
            // JSON: потоковая дозапись построчно (без пересборки файла).
            // С шаблоном EXPORT_JSON_TEMPLATE: каждая обработанная строка идет
            // в свой part-файл (tmp), при завершении собирается итоговый JSON —
            // литералы шаблона + содержимое part-файлов на месте макросов
            // #<ИМЯ ЛИСТА># (без шаблона — массив объектов первого листа)
            $jsonHandle = null;
            $jsonPartPath = '';
            $jsonTemplateRaw = $isJson ? trim((string)($settings['EXPORT_JSON_TEMPLATE'] ?? '')) : '';
            $jsonTemplateMode = $isJson && $jsonTemplateRaw !== '';
            // кеш ключей объектов JSON (по колонкам текущего листа)
            $this->jsonKeys = [];
            $this->jsonKeysUsed = [];
            // макросы шаблона: ИМЯ ЛИСТА (верхний регистр) => индекс листа
            $jsonSheetKeys = [];
            foreach ($sheets as $i => $sheetCfg) {
                $sheetName = strtoupper(trim((string)($sheetCfg['NAME'] ?? ('SHEET'.($i + 1)))));
                if ($sheetName === '') $sheetName = 'SHEET'.($i + 1);
                $jsonSheetKeys[$sheetName] = $i;
            }
            if ($jsonTemplateMode && $reset) {
                // сброс: удаляем part-файлы листов
                foreach (array_keys($jsonSheetKeys) as $partIdx) {
                    @unlink(\Bitrix\Main\Application::getDocumentRoot()
                        .$tmpDir.'profile_'.$id.'_s'.$partIdx.'.json');
                }
            }

            if (!$isJson) {
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
            }

            $done = false;
            $batchSize = $stepByStep ? 1 : 100;

            while ((microtime(true) - $hitStart) < $hitTimeLimit) {
                if ($sheetIdx >= count($sheets)) {
                    $done = true;
                    break;
                }
                $sheetCfg = $sheets[$sheetIdx];
                $ws = $isJson ? null : $spreadsheet->getSheet($sheetIdx);

                if ($isJson) {
                    // ключи объектов JSON пересчитываются на каждый лист
                    $this->jsonKeys = [];
                    $this->jsonKeysUsed = [];
                    // открытие/продолжение файла поточной записи (JSON)
                    $jsonPartPath = $jsonTemplateMode
                        ? (\Bitrix\Main\Application::getDocumentRoot().$tmpDir.'profile_'.$id.'_s'.$sheetIdx.'.json')
                        : $absPath;
                    $jsonHandle = @fopen($jsonPartPath, 'c+');
                    if (!$jsonHandle) {
                        throw new \Bitrix\Main\IO\IoException('Не удалось открыть файл выгрузки JSON: '.$jsonPartPath);
                    }
                    @flock($jsonHandle, LOCK_EX);
                    $size = filesize($jsonPartPath);
                    if ($size === false || $size < 2) {
                        ftruncate($jsonHandle, 0);
                        fseek($jsonHandle, 0);
                        fwrite($jsonHandle, "[\n");
                    } else {
                        // существующий файл: курсор записи — в конец;
                        // ремонт хвоста после обрыва хита (незавершенная строка)
                        fseek($jsonHandle, 0, SEEK_END);
                        $size = (int)fstat($jsonHandle)['size'];
                        $scan = min(65536, $size - 2);
                        if ($scan > 0) {
                            fseek($jsonHandle, $size - $scan);
                            $tail = fread($jsonHandle, $scan);
                            $nl = strrpos($tail, "\n");
                            if ($nl === false) {
                                // целостный хвост не найден — начинаем заново
                                ftruncate($jsonHandle, 0);
                                fseek($jsonHandle, 0);
                                fwrite($jsonHandle, "[\n");
                            } else {
                                $keep = $size - $scan + $nl + 1;
                                if ($keep < $size) {
                                    ftruncate($jsonHandle, $keep);
                                }
                                fseek($jsonHandle, 0, SEEK_END);
                            }
                        } else {
                            fseek($jsonHandle, 0, SEEK_END);
                        }
                    }
                }

                // активные колонки листа (ACTIVE != N)
                $activeColumns = [];
                foreach ((array)($sheetCfg['COLUMNS'] ?? []) as $column) {
                    if (is_array($column) && ($column['ACTIVE'] ?? 'Y') === 'N') continue;
                    $activeColumns[] = $column;
                }

                // выборка порции записей источника (по возрастанию ID, после LAST_ID)
                $select = ['ID'];
                foreach ($activeColumns as $column) {
                    $field = (string)($column['FIELD'] ?? '');
                    if ($field === '' || in_array($field, $select, true)) continue;
                    $select[] = $field;
                }

                $rows = $service->getElements(
                    (string)$iblockId,
                    $lastId,
                    $batchSize,
                    $select,
                    $exportFilter
                );

                if (!$rows) {
                    // лист закончился: финализируем его JSON-файл и идем дальше
                    if ($isJson && is_resource($jsonHandle)) {
                        $this->jsonFinalizePart($jsonHandle);
                        $jsonHandle = null;
                    }
                    $sheetIdx++;
                    $lastId = 0;
                    continue;
                }

                // для JSON строка-заголовок отсутствует (объекты), нумерация
                // строк книги нужна только xlsx/csv
                $rowNum = $isJson ? 0 : $ws->getHighestRow();
                if ($rowNum < 1) $rowNum = 1;
                foreach ($rows as $fields) {
                    $recordId = $service->getRecordId(is_array($fields) ? $fields : []);
                    // сырые значения всех активных колонок строки (источник
                    // условий SOURCE — начальные значения колонок, как в импорте)
                    $rawValues = [];
                    foreach ($activeColumns as $column) {
                        $field = (string)($column['FIELD'] ?? '');
                        if ($field === '') continue;
                        $rawValues[$field] = $service->extractFieldValue(
                            $fields,
                            $field,
                            (string)($column['DELIMETER'] ?? ', ')
                        );
                    }
                    // конфиг колонок для макросов #COL_*# (формат импорта)
                    $columnsCfg = [];
                    foreach ($activeColumns as $column) {
                        $field = (string)($column['FIELD'] ?? '');
                        if ($field === '' || isset($columnsCfg[$field])) continue;
                        $columnsCfg[$field] = [
                            'LETTER' => '',
                            'HEADER' => (string)($column['NAME'] ?? ''),
                            'ALIAS' => '',
                        ];
                    }

                    // обработка значений колонок (TRIM/CASE + правила MapRules)
                    $rowValues = [];
                    $skipRow = false;
                    $debugRow = null;
                    if ($stepByStep) {
                        $debugRow = [
                            'element_id' => (int)$recordId,
                            'columns' => [],
                            'skip' => false,
                            'skip_reason' => '',
                        ];
                        // печать полей записи: сырые значения всех выбираемых полей
                        // (как debug.raw в импорте — значения колонок из файла)
                        $debugRow['raw'] = [];
                        foreach ($select as $fieldCode) {
                            $debugRow['raw'][] = [
                                'field' => (string)$fieldCode,
                                'value' => $service->extractFieldValue($fields, (string)$fieldCode, ', '),
                            ];
                        }
                    }
                    // опции контекста правил (колонки для макросов #COL_*# и т.д.)
                    $options = [
                        'columns' => $columnsCfg,
                        'iblock_id' => $iblockId,
                    ];
                    // накопитель сработавших правил строки (как в импорте)
                    $rowApplied = [];
                    foreach ($activeColumns as $column) {
                        $field = (string)($column['FIELD'] ?? '');
                        $rawValue = $rawValues[$field] ?? '';
                        $value = $this->applyColumnValue($rawValue, is_array($column) ? $column : []);

                        // правила обработки колонки: transform (преобразование
                        // значения) + skip (пропуск строки) — движок импорта
                        $rules = self::normalizeExportRules((array)($column['RULES'] ?? []));
                        if ($rules) {
                            MapRules::$lastSkip = false;
                            MapRules::$lastDelete = false;
                            MapRules::$lastUnset = false;
                            MapRules::$lastApplied = [];
                            $options['from'] = $field;
                            $result = MapRules::processRules($rules, $value, $rawValues, $options);
                            $value = $result['value'];
                            // сработавшие правила колонки — в общий список строки
                            foreach (MapRules::$lastApplied as $applied) {
                                $applied['field'] = (string)($column['NAME'] ?? $field);
                                $rowApplied[] = $applied;
                            }
                            if (!empty($result['skip'])) {
                                $skipRow = true;
                                if ($stepByStep && is_array($debugRow)) {
                                    $debugRow['skip'] = true;
                                    $debugRow['skip_rule'] = MapRules::$lastSkipRule;
                                    $debugRow['skip_value'] = MapRules::$lastSkipValue;
                                    $debugRow['skip_reason'] = 'Правило ('
                                        .(string)(MapRules::$lastSkipRule['TYPE'] ?? 'skip')
                                        .') в колонке «'.(string)($column['NAME'] ?? $field).'»'
                                        .' (значение: '.(string)(MapRules::$lastSkipValue ?? $value).')';
                                }
                                break;
                            }
                        }

                        if ($stepByStep && is_array($debugRow)) {
                            $debugRow['columns'][] = [
                                'name' => (string)($column['NAME'] ?? ''),
                                'field' => $field,
                                'raw' => $rawValue,
                                'value' => $value,
                            ];
                            // значения макросов для отладки правил (как в импорте)
                            $options['from'] = $field;
                            $debugRow['macros'] = MapRules::getMacrosValues(
                                $columnsCfg, $field, $value, $rawValues, $options
                            );
                        }
                        $rowValues[] = $value;
                    }
                    if ($stepByStep && is_array($debugRow)) {
                        $debugRow['rules'] = $rowApplied;
                    }
                    if ($skipRow) {
                        $rowNum--; // строка не используется
                        if ((int)$recordId > 0) $lastId = (int)$recordId;
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

                    if ($isJson) {
                        // потоковая дозапись строки JSON: компактный объект
                        // (дозапись в конец файла, строка заканчивается запятой —
                        // закрывающая скобка дописывается на финализации)
                        $item = [];
                        $idx = 0;
                        // ключи объектов — валидные идентификаторы: транслит
                        // названия колонки (кириллица → латиница), только
                        // [a-z0-9_], тире/пробелы → подчеркивания; дубликаты —
                        // с суффиксом _2, _3...
                        foreach ($activeColumns as $column) {
                            $name = trim((string)($column['NAME'] ?? ''));
                            if ($name === '') $name = 'col'.($idx + 1);
                            $key = $this->jsonKeys[$idx] ?? null;
                            if ($key === null) {
                                $key = ProfilesHelper::transliterate($name);
                                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key));
                                $key = trim($key, '_');
                                if ($key === '') $key = 'col'.($idx + 1);
                                if (isset($this->jsonKeysUsed[$key])) {
                                    $n = 2;
                                    while (isset($this->jsonKeysUsed[$key.'_'.$n])) $n++;
                                    $key .= '_'.$n;
                                }
                                $this->jsonKeysUsed[$key] = true;
                                $this->jsonKeys[$idx] = $key;
                            }
                            $item[$key] = $rowValues[$idx] ?? '';
                            $idx++;
                        }
                        fwrite($jsonHandle, json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).",\n");
                    } else {
                        $rowNum++;
                        $col = 1;
                        foreach ($rowValues as $value) {
                            $ws->setCellValue(Coordinate::stringFromColumnIndex($col).$rowNum, $value);
                            $col++;
                        }
                    }
                    if ((int)$recordId > 0) $lastId = (int)$recordId;
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
            if ($isJson) {
                // финализация: закрываем последний part-файл
                if (is_resource($jsonHandle)) {
                    $this->jsonFinalizePart($jsonHandle);
                    $jsonHandle = null;
                }
                if ($done && $jsonTemplateMode) {
                    // сборка итогового JSON: литералы шаблона + part-файлы
                    // на месте макросов #<ИМЯ ЛИСТА>#
                    $docRoot = \Bitrix\Main\Application::getDocumentRoot();
                    $parts = [];
                    foreach ($jsonSheetKeys as $key => $partIdx) {
                        $parts[$key] = $docRoot.$tmpDir.'profile_'.$id.'_s'.$partIdx.'.json';
                    }
                    $parts = $this->jsonFinalizeParts($parts);
                    $out = @fopen($absPath, 'w');
                    if (!$out) {
                        throw new \Bitrix\Main\IO\IoException('Не удалось открыть файл выгрузки JSON: '.$relPath);
                    }
                    $segments = preg_split('/#([A-Za-z0-9_\-]+)#/', $jsonTemplateRaw, -1, PREG_SPLIT_DELIM_CAPTURE);
                    for ($i = 0; $i < count($segments); $i++) {
                        if (($i % 2) === 0) {
                            fwrite($out, $segments[$i]);
                            continue;
                        }
                        $key = strtoupper(trim((string)$segments[$i]));
                        if (isset($parts[$key]) && is_file($parts[$key])) {
                            // потоковое копирование содержимого part-файла
                            $in = @fopen($parts[$key], 'r');
                            if ($in) {
                                while (!feof($in)) {
                                    fwrite($out, (string)fread($in, 262144));
                                }
                                fclose($in);
                            }
                        } else {
                            fwrite($out, '[]');
                        }
                    }
                    fflush($out);
                    fclose($out);
                    // part-файлы больше не нужны
                    foreach ($parts as $partFile) {
                        if (is_file($partFile)) @unlink($partFile);
                    }
                }
            } else {
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
            }

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

            // завершение: копируем временный файл по финальному пути
            $stateFile = $relPath;
            if ($done && is_file($absPath)) {
                $finalAbs = \Bitrix\Main\Application::getDocumentRoot().$finalPath;
                $finalDir = dirname($finalAbs);
                if (!is_dir($finalDir)) @mkdir($finalDir, BX_DIR_PERMISSIONS ?? 0755, true);
                if (@copy($absPath, $finalAbs)) {
                    @unlink($absPath);
                    $stateFile = $finalPath;
                }
            }

            // сохраняем состояние
            $state = [
                'SHEET' => $done ? 0 : $sheetIdx,
                'LAST_ID' => $done ? 0 : $lastId,
                'PROCESSED' => $processed,
                'STATS' => $this->processStats,
                // «живой» дамп для отображения прогресса cron-запуска в UI
                'STATS_VIEW' => $this->processStats,
                'ERRORS' => $this->processErrors,
                'FILE' => $stateFile,
                'DONE' => $done ? 'Y' : 'N',
                'UPDATED' => date('Y-m-d H:i:s'),
                'TOTAL_TIME' => $totalTime,
                // маркер cron-запуска (CLI) + heartbeat + старт прогона
                'SOURCE' => ($source !== '') ? $source : null,
                'CRON_ALIVE' => ($cronAlive > 0) ? $cronAlive : null,
                'RUN_STARTED' => $runStarted,
            ];
            $settings['PROCESS_EXPORT'] = $state;
            // событие финализации экспорта (до записи состояния в профиль)
            if ($done) {
                $this->fireExportFinishEvent($id, $settings);
            }
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
                'file_url' => $this->fileUrl($stateFile),
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
     * Финализация поточного JSON-файла: снимаем висячую запятую последнего
     * элемента и дописываем закрывающую скобку массива (пустой файл → []).
     */
    protected function jsonFinalizePart($handle): void
    {
        if (!is_resource($handle)) return;
        $size = (int)fstat($handle)['size'];
        if ($size > 2) {
            fseek($handle, $size - 2);
            $tail = fread($handle, 2);
            if ($tail === ",\n") {
                ftruncate($handle, $size - 2);
            }
            fseek($handle, 0, SEEK_END);
            fwrite($handle, "\n]");
        } else {
            ftruncate($handle, 0);
            fwrite($handle, "[]");
        }
        fflush($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Финализация part-файлов листов (каждый — самостоятельный JSON-массив):
     * файл без закрывающей скобки (записи закончились запятой после обрыва
     * хита) доводится до валидного массива.
     *
     * @param array $parts [ключ => путь к part-файлу]
     * @return array
     */
    protected function jsonFinalizeParts(array $parts): array
    {
        foreach ($parts as $path) {
            if (!is_file($path)) continue;
            $size = (int)@filesize($path);
            if ($size >= 3 && substr((string)@file_get_contents($path, false, null, $size - 3, 3), 0, 1) !== ']') {
                $handle = @fopen($path, 'c+');
                if (!$handle) continue;
                $this->jsonFinalizePart($handle);
            }
        }
        return $parts;
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
     * Нормализация правил колонки в формате импорта (MapRules).
     *
     * Поддерживаются типы transform и skip (delete/unset/entity экспорту
     * не применимы и отбрасываются). Старые форматы конвертируются:
     *  - {ACTION: skip_row} → skip с условием «пустое значение»;
     *  - условие прямо в правиле (OP/VALUE/VALUE2 без CONDITIONS) —
     *    заворачивается в CONDITIONS.
     *
     * @param array $rules массив правил
     * @return array
     */
    protected static function normalizeExportRules(array $rules): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $type = (string)($rule['TYPE'] ?? '');
            // старый формат: {ACTION: skip_row, CONDITION: empty}
            if ($type === '' && (string)($rule['ACTION'] ?? '') === 'skip_row') {
                $result[] = [
                    'TYPE' => 'skip',
                    'AGG' => 'AND',
                    'CONDITIONS' => [['OP' => 'empty', 'VALUE' => '', 'VALUE2' => '']],
                ];
                continue;
            }
            if ($type !== 'transform' && $type !== 'skip') continue;

            // обратная совместимость: условие прямо в правиле (без CONDITIONS)
            $conditions = (isset($rule['CONDITIONS']) && is_array($rule['CONDITIONS']))
                ? $rule['CONDITIONS']
                : [['OP' => (string)($rule['OP'] ?? 'eq'), 'VALUE' => (string)($rule['VALUE'] ?? ''), 'VALUE2' => (string)($rule['VALUE2'] ?? '')]];

            $entry = [
                'TYPE' => $type,
                'AGG' => in_array(($rule['AGG'] ?? 'AND'), ['AND', 'OR', 'AND_NOT', 'OR_NOT'], true) ? $rule['AGG'] : 'AND',
                'CONDITIONS' => self::normalizeExportConditions($conditions),
            ];
            if ($type === 'transform') {
                $entry['ACTION'] = (string)($rule['ACTION'] ?? 'replace');
                $entry['ACTION_VALUE'] = (string)($rule['ACTION_VALUE'] ?? '');
                $entry['ACTION_VALUE2'] = (string)($rule['ACTION_VALUE2'] ?? '');
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Нормализация дерева условий правил экспорта (формат импорта,
     * лист-условие может задавать SOURCE — колонку/макрос источника).
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
            $entry = [
                'SOURCE' => (string)($cond['SOURCE'] ?? ''),
                'OP' => (string)($cond['OP'] ?? 'eq'),
                'VALUE' => (string)($cond['VALUE'] ?? ''),
                'VALUE2' => (string)($cond['VALUE2'] ?? ''),
            ];
            if ($entry['SOURCE'] === '') unset($entry['SOURCE']);
            $result[] = $entry;
        }
        return $result;
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
