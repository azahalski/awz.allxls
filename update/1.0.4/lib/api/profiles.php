<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Helper\FastXlsReader;
use Awz\Allxls\Helper\ProfilesHelper;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Cron\Runner as CronRunner;
use Awz\Allxls\Cron\Schedule as CronSchedule;
use Awz\Allxls\Cron\Agents as CronAgents;
use Bitrix\Iblock\PropertyTable;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class Profiles extends \Bitrix\Main\Engine\Controller {

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
        Loc::loadMessages(__FILE__);

        parent::init();
    }

    public function configureActions()
    {
        $config = [
            'save'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'forward'=> [
                'prefilters' => []
            ],
            'cronsave'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'cronstatus'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'externalinfo'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'externals'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'filecachestatus'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ]
        ];

        return $config;
    }

    /**
     * Сохранение профиля (этап 1 — базовые поля)
     *
     * @param int $id ID профиля (0 — создание)
     * @param array $fields NAME, ACTIVE, SECTION_ID, DESC
     * @param array $params
     * @return array
     */
    public function saveAction(int $id = 0, array $fields = [], array $params = [])
    {
        $id = (int)$id;
        if ($this->cronLockedGuard($id)) return null;
        if ($this->processNotDoneGuard($id)) return null;

        $data = [
            'NAME' => trim((string)($fields['NAME'] ?? '')),
            'ACTIVE' => (($fields['ACTIVE'] ?? 'Y') === 'N') ? 'N' : 'Y',
            'SECTION_ID' => (int)($fields['SECTION_ID'] ?? 0),
            'DESC' => trim((string)($fields['DESC'] ?? '')),
            // тип профиля: импорт (по умолчанию) или экспорт
            'TYPE' => ((string)($fields['TYPE'] ?? '') === 'export') ? 'export' : 'import',
        ];

        if ($data['NAME'] === '') {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NAME_EMPTY'),
                'NAME_EMPTY'
            ));
            return null;
        }

        try {
            if ($id > 0) {
                $existing = ProfilesTable::getById($id)->fetch();
                if (!$existing) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                        'NOT_FOUND'
                    ));
                    return null;
                }
                $result = ProfilesTable::update($id, $data);
            } else {
                $result = ProfilesTable::add($data);
            }

            if ($result->isSuccess()) {
                $newId = $id > 0 ? $id : $result->getId();
                return [
                    'id' => $newId,
                    'nextStepUrl' => '/bitrix/admin/awz_allxls_profiles_edit.php?id=' . $newId . '&step=2&lang='.LANGUAGE_ID
                ];
            }

            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }
            return null;
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Сохранение пути к файлу источника (этап 2)
     *
      * @param int $id ID профиля
      * @param string $file_path путь к файлу относительно корня сайта
      * @param int $max_params максимальное количество колонок (параметров) из файла импорта (0 — без ограничения)
      * @return array|null
      */
    public function saveFileAction(int $id = 0, string $file_path = '', string $proxy = '', string $csv_delimiter = ',', string $dest_type = 'iblock', int $iblock_id = 0, string $orm_file = '', string $csv_encoding = 'utf8', string $csv_encoding_custom = '', string $xml_paths = '', int $file_cache_lifetime = -1, string $file_ext = '', int $max_params = 0, string $export_filter = '')
    {
        $id = (int)$id;
        if ($this->cronLockedGuard($id)) return null;
        if ($this->processNotDoneGuard($id)) return null;
        $filePath = trim(str_replace('\\', '/', (string)$file_path));
        $proxy = trim((string)$proxy);
        $csvDelimiter = (string)$csv_delimiter;
        // необязательное поле: допустимы , ; \t либо любой другой одиночный символ
        if ($csvDelimiter !== '' && mb_strlen($csvDelimiter) !== 1) $csvDelimiter = ',';

        // кодировка CSV-выгрузки: utf8 | cp1251 | other (+ произвольная кодировка)
        $csvEncoding = (string)$csv_encoding;
        if (!in_array($csvEncoding, ['utf8', 'cp1251', 'other'], true)) $csvEncoding = 'utf8';
        $csvEncodingCustom = trim((string)$csv_encoding_custom);
        if ($csvEncoding !== 'other' || $csvEncodingCustom === '') {
            $csvEncodingCustom = '';
        }

        // куда грузим: инфоблок или ORM-сущность
        $destType = ((string)$dest_type === 'orm') ? 'orm' : 'iblock';
        $iblockId = 0;
        $ormFile = '';
        if ($destType === 'iblock') {
            $iblockId = (int)$iblock_id;
            if ($iblockId <= 0) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_DEST_IBLOCK_EMPTY'),
                    'DEST_IBLOCK_EMPTY'
                ));
                return null;
            }
            if (\Bitrix\Main\Loader::includeModule('iblock')) {
                $iblock = \CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
                if (!$iblock) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_DEST_IBLOCK_NOT_FOUND'),
                        'DEST_IBLOCK_NOT_FOUND'
                    ));
                    return null;
                }
            }
        } else {
            $ormFile = trim(str_replace('\\', '/', (string)$orm_file));
            if ($ormFile === '' || strpos($ormFile, '..') !== false || strtolower(pathinfo($ormFile, PATHINFO_EXTENSION)) !== 'php') {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_ORM_FILE_INVALID'),
                    'ORM_FILE_INVALID'
                ));
                return null;
            }
            $absOrmPath = \Bitrix\Main\Application::getDocumentRoot().$ormFile;
            if (!file_exists($absOrmPath) || !is_file($absOrmPath)) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_ORM_FILE_NOT_EXISTS'),
                    'ORM_FILE_NOT_EXISTS'
                ));
                return null;
            }
        }

        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                'NOT_FOUND'
            ));
            return null;
        }

        if ($filePath === '') {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_EMPTY'),
                'FILE_EMPTY'
            ));
            return null;
        }

        // защита от выхода за пределы корня сайта
        if (strpos($filePath, '..') !== false) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_INVALID'),
                'FILE_INVALID'
            ));
            return null;
        }

        try {
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }

            $isExternal = ProfilesHelper::isExternalPath($filePath);
            $isExport = (($profile['TYPE'] ?? 'import') === 'export');
            if ($isExport) {
                // экспорт: файл выгрузки создается при экспорте, проверяем только путь и расширение
                $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['csv', 'xlsx', 'json'], true)
                    || strpos($filePath, '/upload/awz.allxls/') !== 0) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_EXPORT_INVALID'),
                        'FILE_EXPORT_INVALID'
                    ));
                    return null;
                }
                // прокси для экспорта не используется
                $proxy = '';
            } elseif ($isExternal) {
                // внешний источник: локальная проверка не требуется,
                // файл скачивается в кеш на cron/агенте (шаг 3 ждет кеш)
            } else {
                $absPath = \Bitrix\Main\Application::getDocumentRoot().$filePath;
                if (!file_exists($absPath) || !is_file($absPath)) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_NOT_EXISTS'),
                        'FILE_NOT_EXISTS'
                    ));
                    return null;
                }
            }

            $settings = $profile['SETTINGS'] ?? null;
            // формат выгрузки (json/csv/xlsx) хранится отдельно (EXPORT_FILE_EXT)
            if ($isExport) {
                $settings['EXPORT_FILE_EXT'] = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            }
            $settings['FILE_PATH'] = $filePath;
            $settings['PROXY'] = $proxy;
            // явно заданный тип файла источника (для ссылок без расширения):
            // '' (авто, по расширению пути) | csv | xlsx | xls | xml | yml
            $fileExt = strtolower(trim((string)($file_ext ?? '')));
            if (!in_array($fileExt, ['csv', 'xlsx', 'xls', 'xml', 'yml', 'json'], true)) {
                $fileExt = '';
            }
            $settings['FILE_EXT'] = $fileExt;
            $settings['CSV_DELIMITER'] = $csvDelimiter;
            $settings['CSV_ENCODING'] = $csvEncoding;
            $settings['CSV_ENCODING_CUSTOM'] = $csvEncodingCustom;
            // путь до элементов: минимальная глубина — для JSON допустим
            // один сегмент (товары в корне файла: products / корневой массив)
            $srcExt = $fileExt;
            if ($srcExt === '') {
                $pathPart = ProfilesHelper::isExternalPath($filePath) ? (string)(parse_url($filePath, PHP_URL_PATH) ?: '') : $filePath;
                if (strtolower(pathinfo($pathPart, PATHINFO_EXTENSION)) === 'gz') {
                    $srcExt = strtolower(pathinfo(pathinfo($pathPart, PATHINFO_FILENAME), PATHINFO_EXTENSION));
                } else {
                    $srcExt = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION));
                }
            }
            // пути до элементов XML/YML/JSON (items->item, offers->offer, products)
            $settings['XML_PATHS'] = ProfilesHelper::parseXmlPaths($xml_paths, $srcExt === 'json' ? 1 : 2);
            // время жизни кеша внешнего источника (сек; 0 — обновлять при каждом запуске)
            if ($file_cache_lifetime >= 0) {
                $settings['FILE_CACHE_LIFETIME'] = max(0, (int)$file_cache_lifetime);
            }
            // максимальное количество колонок (параметров) из файла импорта
            // (0 — без ограничения)
            $settings['MAX_PARAMS'] = max(0, (int)$max_params);

            // JSON-фильтр выборки элементов для экспорта (необязательно):
            // хранится как есть (валидность проверяется при сохранении),
            // передается в сервис-источник экспорта
            $exportFilter = trim((string)$export_filter);
            if ($exportFilter !== '') {
                try {
                    $decodedFilter = \Bitrix\Main\Web\Json::decode($exportFilter);
                    if (!is_array($decodedFilter)) {
                        $this->addError(new \Bitrix\Main\Error(
                            'JSON-фильтр должен быть объектом или массивом',
                            'EXPORT_FILTER_INVALID'
                        ));
                        return null;
                    }
                    $settings['EXPORT_FILTER'] = \Bitrix\Main\Web\Json::encode($decodedFilter);
                } catch (\Throwable $e) {
                    $this->addError(new \Bitrix\Main\Error(
                        'Невалидный JSON-фильтр: '.$e->getMessage(),
                        'EXPORT_FILTER_INVALID'
                    ));
                    return null;
                }
            } else {
                $settings['EXPORT_FILTER'] = '';
            }

            // для XML/YML/JSON ИСТОЧНИКА (импорт) пути до элементов обязательны.
            // Для экспорта .json — это расширение ФАЙЛА ВЫГРУЗКИ, источник —
            // сервис-приемник (XML_PATHS не нужен), проверка не применяется
            if (!$isExport && in_array($srcExt, ['xml', 'yml', 'json'], true) && !$settings['XML_PATHS']) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_XML_PATHS_EMPTY') ?: 'Не заданы пути до элементов (например: items->item)',
                    'XML_PATHS_EMPTY'
                ));
                return null;
            }
            $settings['DEST_TYPE'] = $destType;
            $settings['IBLOCK_ID'] = $iblockId;
            $settings['ORM_FILE'] = $ormFile;

            $result = ProfilesTable::update($id, [
                'SETTINGS' => $settings
            ]);

            if ($result->isSuccess()) {
                return [
                    'id' => $id,
                    'file_path' => $filePath,
                    'proxy' => $proxy,
                    'csv_delimiter' => $csvDelimiter,
                    'csv_encoding' => $csvEncoding,
                    'csv_encoding_custom' => $csvEncodingCustom,
                    'xml_paths' => trim((string)$xml_paths),
                    'file_cache_lifetime' => ($file_cache_lifetime >= 0 ? (int)$file_cache_lifetime : null),
                    'max_params' => $settings['MAX_PARAMS'],
                    'external' => $isExternal,
                    'dest_type' => $destType,
                    'iblock_id' => $iblockId,
                    'orm_file' => $ormFile,
                    'nextStepUrl' => '/bitrix/admin/awz_allxls_profiles_edit.php?id=' . $id . '&step=3&lang='.LANGUAGE_ID
                ];
            }

            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }
            return null;
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Статус кеш-файла внешнего источника (опрос со страницы шага 3,
     * пока файл по ссылке еще не скачан cron-ом/агентом).
     *
     * Экшен: awz:allxls.api.profiles.filecachestatus
     *
     * @param int $id ID профиля
     * @return array|null {external, ready, age, lifetime, file_path}
     */
    public function filecachestatusAction(int $id = 0)
    {
        $id = (int)$id;
        try {
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) return ['external' => false, 'ready' => true];
            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            return ProfilesHelper::externalCacheStatus($id, $settings);
        } catch (\Throwable $e) {
            return ['external' => false, 'ready' => true];
        }
    }

    /**
     * Настройки листов файла (этап 3).
     * Возвращает список листов, колонки (заголовки) и первую строку данных,
     * а также сохраненные ранее настройки листов.
     *
     * @param int $id ID профиля
     * @return array|null
     */
    public function settingsAction(int $id = 0, array $headerRows = [])
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
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
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

            if (ProfilesHelper::isExternalPath($filePath)) {
                // внешний источник: файл качается в кеш на cron/агенте
                // (run.php/Agents::runAll перед прогонами). Здесь — подтягивание
                // с учетом TTL (не чаще времени жизни кеша) и статус для опроса:
                // кеша нет — шаг 3 показывает «ожидаем скачивания файла»
                $refresh = ProfilesHelper::refreshProfileSource($profile);
                $status = ProfilesHelper::externalCacheStatus($id, $settings);
                if (!$status['ready']) {
                    return [
                        'id' => $id,
                        'file_path' => $filePath,
                        'file_cache' => 'pending',
                        'file_cache_status' => $status,
                        'file_cache_refresh' => $refresh,
                        'sheets' => [],
                    ];
                }
                $absPath = \Bitrix\Main\Application::getDocumentRoot().$status['file_path'];
            } else {
                $absPath = \Bitrix\Main\Application::getDocumentRoot().$filePath;
                if (!file_exists($absPath) || !is_file($absPath)) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_FILE_NOT_EXISTS'),
                        'FILE_NOT_EXISTS'
                    ));
                    return null;
                }
            }

            $delimiter = (string)($settings['CSV_DELIMITER'] ?? ',');
            $savedSheets = is_array($settings['SHEETS'] ?? null) ? $settings['SHEETS'] : [];

            // тип файла: явный FILE_EXT (ссылка/FTP без расширения) либо расширение пути
            $ext = ProfilesHelper::fileExtOf($absPath, $settings);
            if ($ext === '') {
                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            }
            if ($ext === 'csv') {
                // CSV: побайтовое чтение через штатный CCSVData
                $sheets = [ProfilesHelper::buildCsvSheet($absPath, $delimiter, $savedSheets['SHEET1'] ?? [], $headerRows)];
                return [
                    'id' => $id,
                    'file_path' => $filePath,
                    'sheets' => $this->limitSheetColumns($sheets, $settings),
                ];
            }

            if ($ext === 'json') {
                // JSON: листы задаются путями до повторяющихся элементов (XML_PATHS),
                // чтение — побайтовый потоковый FastJsonReader (как XML у FastXmlReader)
                $sheets = ProfilesHelper::buildJsonSheets($absPath, $settings['XML_PATHS'] ?? '', $savedSheets);
                if (!$sheets) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_XML_PATHS_EMPTY') ?: 'Не заданы пути до элементов JSON',
                        'XML_PATHS_EMPTY'
                    ));
                    return null;
                }
                return [
                    'id' => $id,
                    'file_path' => $filePath,
                    'sheets' => $this->limitSheetColumns($sheets, $settings),
                ];
            }

            if (in_array($ext, ['xml', 'yml'], true)) {
                // XML/YML: листы задаются путями до повторяющихся элементов (XML_PATHS)
                $sheets = ProfilesHelper::buildXmlSheets($absPath, $settings['XML_PATHS'] ?? '', $savedSheets);
                if (!$sheets) {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_XML_PATHS_EMPTY') ?: 'Не заданы пути до элементов',
                        'XML_PATHS_EMPTY'
                    ));
                    return null;
                }
                // дерево категорий: строится всегда для XML/YML (элементы
                // <category id=".." parentId="..">сканируются по всему файлу,
                // независимо от XML_PATHS) и записывается в профиль.
                // При старте импорта дерево пересобирается
                $catPath = 'categories->category';
                $tree = ProfilesHelper::buildCategoryTree($absPath, $catPath);
                if (!empty($tree['map'])) {
                    $settings['CAT_TREE'] = [
                        'PATH' => $catPath,
                        'MAP' => $tree['map'],
                        'BUILT' => date('Y-m-d H:i:s'),
                    ];
                    try {
                        ProfilesTable::update($id, ['SETTINGS' => $settings]);
                    } catch (\Throwable $e) {
                        // дерево — вспомогательные данные: ошибка сохранения не блокирует шаг 3
                    }
                }
                return [
                    'id' => $id,
                    'file_path' => $filePath,
                    'sheets' => $this->limitSheetColumns($sheets, $settings),
                    // дерево категорий для отображения на шаге 3
                    'cat_tree' => is_array($settings['CAT_TREE'] ?? null) ? $settings['CAT_TREE'] : null,
                ];
            }

            // Excel: быстрое чтение первых строк напрямую из zip (FastXlsReader::readSheetsHead)
            $maxHeaderRow = 1;
            foreach ($savedSheets as $savedSheet) {
                if (is_array($savedSheet)) {
                    $maxHeaderRow = max($maxHeaderRow, (int)($savedSheet['HEADER_ROW'] ?? 1));
                }
            }
            // Страховочный проход по $headerRows, если структура пришла плоским списком
            foreach ($headerRows as $row) {
                if (is_numeric($row)) {
                    $maxHeaderRow = max($maxHeaderRow, (int)$row);
                }
            }
            // заголовок + до 20 строк сэмплов
            $maxRow = $maxHeaderRow + 20;

            $fastSheets = (new FastXlsReader($absPath))->readSheetsHead($maxRow);

            $sheets = [];
            foreach ($fastSheets as $fastIndex => $fastSheet) {
                // идентификация листа только по его номеру (индексу),
                // имя листа может меняться и не является стабильным ключом
                $sheetIndex = (int)($fastSheet['INDEX'] ?? $fastIndex);
                $sheetName = (string)$fastSheet['NAME'];
                $sheetKey = 'SHEET'.($sheetIndex + 1);
                $rows = is_array($fastSheet['ROWS'] ?? null) ? $fastSheet['ROWS'] : [];
                $saved = is_array($savedSheets[$sheetKey] ?? null) ? $savedSheets[$sheetKey] : [];

                $headerRow = max(1, (int)($saved['HEADER_ROW'] ?? 1));
                // переопределение строки заголовков с клиента (при изменении поля на лету) — по номеру листа
                if (isset($headerRows[$sheetKey]) && is_numeric($headerRows[$sheetKey])) {
                    $headerRow = max(1, (int)$headerRows[$sheetKey]);
                } elseif (isset($headerRows[$sheetIndex]) && is_numeric($headerRows[$sheetIndex])) {
                    $headerRow = max(1, (int)$headerRows[$sheetIndex]);
                }

                $headers = array_values((array)($rows[$headerRow] ?? []));

                // читаем до 20 строк данных для примеров значений
                $sampleRows = [];
                for ($rowNum = $headerRow + 1; $rowNum <= $headerRow + 20; $rowNum++) {
                    if (!isset($rows[$rowNum])) {
                        break;
                    }
                    $sampleRows[] = array_values((array)$rows[$rowNum]);
                }

                $highestColIndex = 0;
                foreach (array_merge([$headers], $sampleRows) as $r) {
                    $highestColIndex = max($highestColIndex, is_array($r) ? count($r) : 0);
                }

                $sheets[] = ProfilesHelper::buildSheetData($sheetName, $saved, $headerRow, $headers, $sampleRows, $highestColIndex, $sheetKey);
            }

            return [
                'id' => $id,
                'file_path' => $filePath,
                'sheets' => $this->limitSheetColumns($sheets, $settings),
            ];
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Ограничение количества колонок (параметров) из файла импорта
     * (MAX_PARAMS, шаг 2; 0 — без ограничения). Колонки сверх лимита
     * не показываются на шаге 3 и не сохраняются в настройках —
     * при большом количестве параметров файла настройки в браузере
     * становятся слишком тяжелыми. Колонки несут собственные KEY/INDEX,
     * поэтому импорт оставшихся колонок файла не ломается.
     *
     * @param array $sheets построенные листы
     * @param array $settings настройки профиля
     * @return array
     */
    protected function limitSheetColumns(array $sheets, array $settings): array
    {
        $maxParams = (int)($settings['MAX_PARAMS'] ?? 0);
        if ($maxParams <= 0) {
            return $sheets;
        }
        foreach ($sheets as &$sheet) {
            if (!is_array($sheet) || !is_array($sheet['COLUMNS'] ?? null)) continue;
            if (count($sheet['COLUMNS']) > $maxParams) {
                $sheet['COLUMNS'] = array_slice($sheet['COLUMNS'], 0, $maxParams);
            }
        }
        unset($sheet);
        return $sheets;
    }

    /**
     * Сохранение настроек листов (этап 3)
     *
     * @param int $id ID профиля
     * @param array $sheets список настроек листов
     * @param array $customFields кастомные поля (NAME + CODE), сопоставление по коду
     * @return array|null
     */
    public function saveSettingsAction(int $id = 0, array $sheets = [], array $customFields = [])
    {
        $id = (int)$id;
        if ($this->cronLockedGuard($id)) return null;
        if ($this->processNotDoneGuard($id)) return null;
        if ($id <= 0) {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                'NOT_FOUND'
            ));
            return null;
        }

        try {
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }

            $settings = $profile['SETTINGS'] ?? null;

            $normalized = [];
            // ключ листа — НЕ имя (русские имена листов ломаются при сохранении настроек),
            // а безопасный SHEET1/SHEET2/...; реальное имя листа хранится в поле NAME
            $sheetNum = 0;
            foreach ($sheets as $sheet) {
                if (!is_array($sheet)) continue;
                $name = trim((string)($sheet['NAME'] ?? ''));
                if ($name === '') continue;

                $columns = [];
                $rawColumns = is_array($sheet['COLUMNS'] ?? null) ? $sheet['COLUMNS'] : [];
                foreach ($rawColumns as $key => $col) {
                    if (!is_array($col)) continue;
                    $maps = [];
                    $mapSettings = [];
                    $rawMapSettings = is_array($col['MAP_SETTINGS'] ?? null) ? $col['MAP_SETTINGS'] : [];
                    $mapIdx = 0;
                    foreach ((array)($col['MAPS'] ?? []) as $mapValue) {
                        $mapValue = trim((string)$mapValue);
                        if ($mapValue === '') continue;
                        $maps[] = $mapValue;
                        // индивидуальные настройки сопоставления (по индексу)
                        $rawSettings = is_array($rawMapSettings[$mapIdx] ?? null) ? $rawMapSettings[$mapIdx] : [];
                        //тут можно сделать доп проверки
                        $mapSettings[] = $rawSettings;
                        $mapIdx++;
                    }
                    $columns[(string)$key] = [
                        'INCLUDE' => (($col['INCLUDE'] ?? 'Y') === 'N') ? 'N' : 'Y',
                        'ALIAS' => trim((string)($col['ALIAS'] ?? '')),
                        'LETTER' => trim((string)($col['LETTER'] ?? '')),
                        'HEADER' => trim((string)($col['HEADER'] ?? '')),
                        'MAPS' => $maps,
                        'MAP_SETTINGS' => $mapSettings,
                    ];
                }

                $normalized['SHEET'.(++$sheetNum)] = [
                    'NAME' => $name,
                    'ACTIVE' => (($sheet['ACTIVE'] ?? 'Y') === 'N') ? 'N' : 'Y',
                    'HEADER_ROW' => max(1, (int)($sheet['HEADER_ROW'] ?? 1)),
                    'COLUMNS' => $columns,
                    'SAMPLE_COLS' => max(1, min(10, (int)($sheet['SAMPLE_COLS'] ?? 1))),
                    'SAMPLE_HIDDEN' => array_values(array_filter(array_map('intval', (array)($sheet['SAMPLE_HIDDEN'] ?? [])), function ($v) {
                        return $v >= 1 && $v <= 9;
                    })),
                ];
            }

            $settings['SHEETS'] = $normalized;

            // кастомные поля: название + символьный код; при появлении нового
            // параметра код = транслитерации названия
            $settings['CUSTOM_FIELDS'] = $this->normalizeCustomFields($customFields);

            $result = ProfilesTable::update($id, [
                'SETTINGS' => $settings
            ]);

            if ($result->isSuccess()) {
                return [
                    'id' => $id,
                    'sheets' => $normalized,
                    'nextStepUrl' => '/bitrix/admin/awz_allxls_profiles_edit.php?id=' . $id . '&step=4&lang='.LANGUAGE_ID
                ];
            }

            foreach ($result->getErrors() as $error) {
                $this->addError($error);
            }
            return null;
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }


    /**
     * Список полей для сопоставления (этап 3).
     * Постоянен для текущего профиля — загружается один раз при открытии этапа.
     *
     * @param int $id ID профиля
     * @return array|null
     */
    public function fieldsAction(int $id = 0)
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
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }

            $groups = ProfilesHelper::getFieldGroups($profile, $id);

            $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
            $iblockId = (($settings['DEST_TYPE'] ?? '') === 'iblock') ? (int)($settings['IBLOCK_ID'] ?? 0) : 0;

            return [
                'id' => $id,
                'groups' => $groups,
                'iblock_id' => $iblockId,
            ];
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Создание нового свойства инфоблока (idempotent по коду: если свойство
     * с таким кодом уже есть — возвращается существующее, дубликат не создается).
     * Используется ресивером стандартного диалога
     * /bitrix/admin/iblock_edit_property.php (bxpublic=Y, receiver=obAwzAllxlsProps).
     *
     * Экшен: awz:allxls.api.profiles.createproperty
     *
     * @param int $iblockId ID инфоблока
     * @param string $name название свойства
     * @param string $code символьный код (пустой — генерируется транслитерацией)
     * @param string $type тип: S|N|L|F|E|G|HTML (HTML => S + USER_TYPE=HTML)
     * @param string $multiple Y|N множественное
     * @return array|null {ID, CODE, NAME, PROPERTY_TYPE, EXISTS}
     */
    public function createPropertyAction(
        int $iblockId = 0, string $name = '', string $code = '',
        string $type = '', string $multiple = 'N'
    ) {
        $iblockId = (int)$iblockId;
        $name = trim((string)$name);
        $type = strtoupper(trim((string)$type));
        $userType = '';
        if ($type === 'HTML') {
            // пользовательские HTML: S + USER_TYPE=HTML
            $type = 'S';
            $userType = 'HTML';
        }
        if ($iblockId <= 0 || $name === '' || !in_array($type, ['S','N','L','F','E','G'], true)) {
            $this->addError(new \Bitrix\Main\Error('Некорректные параметры свойства', 'BAD_PARAMS'));
            return null;
        }
        if (!Loader::includeModule('iblock')) {
            $this->addError(new \Bitrix\Main\Error('Модуль iblock не установлен', 'NO_MODULE'));
            return null;
        }

        try {
            // код: транслитерация с названия, все буквы заглавные,
            // не начинается с цифры, без прочерков, нижнее подчеркивание допустимо
            $baseCode = self::normalizePropertyCode($name, (string)$code);
            if ($baseCode === '') {
                $this->addError(new \Bitrix\Main\Error('Не удалось сформировать символьный код', 'BAD_CODE'));
                return null;
            }

            // код уникален в рамках инфоблока: сравнение регистронезависимое
            // (свойство, созданное стандартным диалогом, может хранить код
            // в другом регистре); если код занят другим свойством (с другим
            // названием) — к базовому коду добавляется суффикс _2, _3...
            $iblockProps = PropertyTable::getList([
                'filter' => ['=IBLOCK_ID' => $iblockId],
                'select' => ['ID', 'CODE', 'NAME', 'PROPERTY_TYPE'],
            ])->fetchAll();
            $findByCode = function ($searchCode) use ($iblockProps) {
                foreach ($iblockProps as $p) {
                    if (mb_strtolower(trim((string)$p['CODE'])) === mb_strtolower($searchCode)) {
                        return $p;
                    }
                }
                return null;
            };

            $code = $baseCode;
            $suffix = 1;
            while (true) {
                $existing = $findByCode($code);
                if (!$existing) {
                    break;
                }
                // то же свойство (код + название совпадают) — idempotent,
                // дубликат не создается; код приводится к единому регистру
                if (mb_strtolower(trim((string)$existing['NAME'])) === mb_strtolower($name)) {
                    if ((string)$existing['CODE'] !== $code) {
                        PropertyTable::update((int)$existing['ID'], ['CODE' => $code]);
                    }
                    return [
                        'ID' => (int)$existing['ID'],
                        'CODE' => $code,
                        'NAME' => (string)$existing['NAME'],
                        'PROPERTY_TYPE' => (string)$existing['PROPERTY_TYPE'],
                        'EXISTS' => true,
                    ];
                }
                $suffix++;
                $code = $baseCode . '_' . $suffix;
            }

            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'ACTIVE' => 'Y',
                'SORT' => 500,
                'CODE' => $code,
                'PROPERTY_TYPE' => $type,
                'MULTIPLE' => ($multiple === 'Y') ? 'Y' : 'N',
                'IS_REQUIRED' => 'N',
            ];
            if ($userType !== '') {
                $fields['USER_TYPE'] = $userType;
            }
            $obProperty = new \CIBlockProperty;
            $newId = $obProperty->Add($fields);
            if (!$newId) {
                $this->addError(new \Bitrix\Main\Error(
                    trim((string)$obProperty->LAST_ERROR) ?: 'Ошибка создания свойства',
                    'CREATE_FAIL'
                ));
                return null;
            }

            return [
                'ID' => (int)$newId,
                'CODE' => $code,
                'NAME' => $name,
                'PROPERTY_TYPE' => $type,
                'EXISTS' => false,
            ];
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Проверка существования свойства инфоблока по символьному коду
     * (fallback после закрытия стандартного диалога создания свойства).
     *
     * Экшен: awz:allxls.api.profiles.propertycheck
     *
     * @param int $iblockId ID инфоблока
     * @param string $code символьный код
     * @return array|null {ID, CODE, NAME, PROPERTY_TYPE} или пустой массив
     */
    public function propertycheckAction(int $iblockId = 0, string $code = '')
    {
        $iblockId = (int)$iblockId;
        $code = trim((string)$code);
        if ($iblockId <= 0 || $code === '') {
            return ['property' => null];
        }
        if (!Loader::includeModule('iblock')) {
            return ['property' => null];
        }
        try {
            $props = PropertyTable::getList([
                'filter' => ['=IBLOCK_ID' => $iblockId],
                'select' => ['ID', 'CODE', 'NAME', 'PROPERTY_TYPE'],
            ])->fetchAll();
            $prop = null;
            foreach ($props as $p) {
                // регистронезависимое сравнение: диалог мог сохранить код
                // в другом регистре
                if (mb_strtolower(trim((string)$p['CODE'])) === mb_strtolower($code)) {
                    $prop = $p;
                    break;
                }
            }
            return [
                'property' => $prop ? [
                    'ID' => (int)$prop['ID'],
                    'CODE' => (string)$prop['CODE'],
                    'NAME' => (string)$prop['NAME'],
                    'PROPERTY_TYPE' => (string)$prop['PROPERTY_TYPE'],
                ] : null,
            ];
        } catch (\Throwable $e) {
            return ['property' => null];
        }
    }

    /**
     * Нормализация символьного кода свойства: транслитерация (если код пуст),
     * все буквы заглавные, не начинается с цифры (префикс P),
     * прочерки заменяются на нижнее подчеркивание, допустимы [A-Z0-9_].
     * Уникальность кода в рамках инфоблока обеспечивается в createPropertyAction.
     *
     * @param string $name название свойства (для генерации кода)
     * @param string $code предложенный код
     * @return string
     */
    /**
     * Защита от редактирования профиля, пока он выполняется cron-процессом.
     * @param int $id ID профиля
     * @return bool true — профиль заблокирован (ошибка добавлена), редактирование запрещено
     */
    protected function cronLockedGuard(int $id): bool
    {
        if ($id <= 0 || !CronRunner::isProfileLocked($id)) {
            return false;
        }
        // блокировка без обновления статистики дольше 3 минут — cron считается
        // упавшим: редактирование разрешено, блокировка снимается
        if (!CronRunner::isCronAlive($id)) {
            CronRunner::releaseLock($id);
            CronRunner::resetCronFalls($id);
            return false;
        }
        $this->addError(new \Bitrix\Main\Error(
            Loc::getMessage('AWZ_ALLXLS_API_PROFILES_CRON_LOCKED'),
            'CRON_RUNNING'
        ));
        return true;
    }

    /**
     * Защита от изменения настроек профиля с незавершенной обработкой.
     *
     * Пока импорт/экспорт не завершен (PROCESS[.EXPORT].DONE != Y и есть
     * прогресс), сохранение настроек запрещено: работающий импорт перезаписывает
     * SETTINGS своим состоянием и изменения терялись бы молча. Сначала нужно
     * завершить/докрутить обработку (кнопка «Завершить обработку» на шаге 4).
     *
     * @param int $id ID профиля
     * @return bool true — обработка не завершена (ошибка добавлена), сохранение запрещено
     */
    protected function processNotDoneGuard(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $row = ProfilesTable::getRow([
                'select' => ['TYPE', 'SETTINGS'],
                'filter' => ['=ID' => $id],
            ]);
            if (!$row) return false;
            $st = is_array($row['SETTINGS'] ?? null) ? $row['SETTINGS'] : [];
            $procKey = (($row['TYPE'] ?? 'import') === 'export') ? 'PROCESS_EXPORT' : 'PROCESS';
            $proc = is_array($st[$procKey] ?? null) ? $st[$procKey] : [];
            $unfinished = (($proc['DONE'] ?? 'Y') !== 'Y')
                && ((int)($proc['PROCESSED'] ?? 0) > 0 || (string)($proc['SHEET'] ?? '') !== '');
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
     * Сохранение настроек запуска по расписанию (cron).
     *
     * Экшен: awz:allxls.api.profiles.cronsave
     *
     * @param int $id ID профиля
     * @param string $active Y|N — включение запуска по расписанию
     * @param string $schedule crontab-выражение (5 полей, например '0 3 * * *') либо @daily/@hourly
     * @param int $stepTime время одного шага запуска, сек (0 — обрабатывать до завершения за один вызов)
     * @param int $pause пауза после завершения прогона, сек (0 — выключено)
     * @param string $mode способ запуска: cron (общее cron-задание, `run.php all`) |
     *        cron_own (отдельное cron-задание, `run.php <id>`) | agent (агент Bitrix)
     * @param string $release Y — принудительно снять блокировку (процесс умер)
     * @param string $timeFrom время запуска «от» (HH:MM, пусто — без ограничения)
     * @param string $timeTo время запуска «до» (HH:MM, пусто — без ограничения)
     * @param string $nextRun время следующего запуска вручную (Y-m-dTH:i или Y-m-d H:i[:s], пусто — по расписанию);
     *        позволяет разово назначить ближайший запуск, не меняя паузу/расписание
     * @return array|null {id, active, schedule, step_time, next_run}
     */
    public function cronSaveAction(int $id = 0, string $active = 'N', string $schedule = '', int $stepTime = 0, int $pause = 0, string $mode = '', string $release = 'N', string $timeFrom = '', string $timeTo = '', string $nextRun = '')
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
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }

            // нормализация: неразрывные и прочие unicode-пробелы → обычные,
            // сжатие повторных пробелов (nbsp из браузера ломал валидацию);
            // снятие JS-экранирования "\/" → "/" (JSEscape в старых версиях UI)
            $schedule = str_replace('\\/', '/', (string)$schedule);
            $schedule = trim((string)preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{3000}]+/u', ' ', (string)$schedule));

            // при активном запуске менять настройки расписания нельзя
            // (допустимо только принудительное снятие блокировки)
            if ($release !== 'Y') {
                if ($this->cronLockedGuard($id)) return null;
            }

            if ($schedule !== '' && !CronSchedule::validate($schedule)) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_CRON_SCHEDULE_INVALID').' «'.(string)$schedule.'»',
                    'CRON_SCHEDULE_INVALID'
                ));
                return null;
            }

            if ($release === 'Y') {
                CronRunner::releaseLock($id);
            }



            if ($release !== 'Y') {
                // нормализация времени запуска «от/до»: только HH:MM либо пусто
                $norm = static function (string $v): string {
                    $v = trim($v);
                    if ($v === '') return '';
                    if (!preg_match('/^(\d{1,2}):(\d{1,2})$/', $v, $m)) return '';
                    $h = (int)$m[1];
                    $i = (int)$m[2];
                    if ($h > 23 || $i > 59) return '';
                    return sprintf('%02d:%02d', $h, $i);
                };
                $timeFrom = $norm($timeFrom);
                $timeTo = $norm($timeTo);
                // пауза после завершения прогона, сек (0 — выключено, минимум 5 мин)

                $pauseMin = max(0, min(604800, (int)$pause*60));
                if ($pauseMin > 0 && $pauseMin < 300) {
                    $pauseMin = 300;
                }

                $fields = [
                    'CRON_ACTIVE' => ($active === 'Y') ? 'Y' : 'N',
                    // пошаговый режим: время одного шага запуска, сек (0 — до завершения)
                    'CRON_HIT_TIME' => max(0, min(3600, (int)$stepTime)),
                    'CRON_PAUSE' => $pauseMin,
                ];
                // способ запуска и окно времени запуска хранятся в SETTINGS
                // (летучие ключи, на хеш настроек не влияют)
                $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
                $changed = false;
                if (in_array($mode, ['cron', 'cron_own', 'agent'], true)) {
                    $settings['CRON_MODE'] = $mode;
                    $changed = true;
                }
                if ($timeFrom !== '') {
                    $settings['CRON_TIME_FROM'] = $timeFrom;
                    $changed = true;
                } elseif (array_key_exists('CRON_TIME_FROM', $settings)) {
                    unset($settings['CRON_TIME_FROM']);
                    $changed = true;
                }
                if ($timeTo !== '') {
                    $settings['CRON_TIME_TO'] = $timeTo;
                    $changed = true;
                } elseif (array_key_exists('CRON_TIME_TO', $settings)) {
                    unset($settings['CRON_TIME_TO']);
                    $changed = true;
                }
                if ($changed) {
                    $fields['SETTINGS'] = $settings;
                }
            } else {
                // принудительное снятие блокировки: настройки не трогаем
                $fields = [];
            }
            if ($active === 'Y') {
                // действующее расписание: переданное либо сохраненное ранее
                $effective = ($schedule !== '') ? $schedule : (string)($profile['CRON_SCHEDULE'] ?? '');
                if ($effective === '') {
                    $this->addError(new \Bitrix\Main\Error(
                        Loc::getMessage('AWZ_ALLXLS_API_PROFILES_CRON_SCHEDULE_EMPTY'),
                        'CRON_SCHEDULE_EMPTY'
                    ));
                    return null;
                }
                if ($schedule !== '') {
                    $fields['CRON_SCHEDULE'] = $schedule;
                }
                $fields['DATE_NEXT_RUN'] = CronSchedule::getNextRun($effective);
                // ручное время следующего запуска: переопределяет расчет по
                // расписанию (разовый запуск в удобное время, независимо
                // от опции «пауза после завершения» и текущего расписания)
                $manualRun = trim(str_replace('T', ' ', (string)$nextRun));
                if ($manualRun !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $manualRun)) {
                    if (strlen($manualRun) === 16) $manualRun .= ':00';
                    $fields['DATE_NEXT_RUN'] = new \Bitrix\Main\Type\DateTime(
                        $manualRun, 'Y-m-d H:i:s'
                    );
                }
            } else {
                // выключение: расписание сохраняем, запуск сбрасываем
                if ($schedule !== '') {
                    $fields['CRON_SCHEDULE'] = $schedule;
                }
                $fields['DATE_NEXT_RUN'] = null;
            }

            $result = ProfilesTable::update($id, $fields);
            if (!$result->isSuccess()) {
                foreach ($result->getErrors() as $error) {
                    $this->addError($error);
                }
                return null;
            }

            return $this->cronStatusData($id, $profile, $fields);
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Статус cron-запуска профиля (для отображения в интерфейсе).
     *
     * Экшен: awz:allxls.api.profiles.cronstatus
     *
     * @param int $id ID профиля
     * @return array|null {id, active, schedule, schedule_valid, running, start_time, next_run, last_end, last_err, command}
     */
    public function cronStatusAction(int $id = 0)
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
            $profile = ProfilesTable::getById($id)->fetch();
            if (!$profile) {
                $this->addError(new \Bitrix\Main\Error(
                    Loc::getMessage('AWZ_ALLXLS_API_PROFILES_NOT_FOUND'),
                    'NOT_FOUND'
                ));
                return null;
            }
            return $this->cronStatusData($id, $profile);
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Данные статуса cron-запуска профиля.
     */
    protected function cronStatusData(int $id, array $profile, array $saved = []): array
    {
        $schedule = (string)($saved['CRON_SCHEDULE'] ?? $profile['CRON_SCHEDULE'] ?? '');
        $active = ($saved['CRON_ACTIVE'] ?? $profile['CRON_ACTIVE']) === 'Y';
        $nextRun = $saved['DATE_NEXT_RUN'] ?? $profile['DATE_NEXT_RUN'];

        $fmt = function ($value) {
            return ($value instanceof \Bitrix\Main\Type\DateTime) ? $value->format('d.m.Y H:i:s') : null;
        };

        // «живая» статистика cron-запуска (dumpLiveStats в Pimport/Pexport)
        $procSettings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
        $procKey = (($profile['TYPE'] ?? 'import') === 'export') ? 'PROCESS_EXPORT' : 'PROCESS';
        $statsView = is_array($procSettings[$procKey]['STATS_VIEW'] ?? null) ? $procSettings[$procKey]['STATS_VIEW'] : null;
        // контекст cron-прогона для JS: маркер CLI-запуска и признак завершения
        $source = (string)($procSettings[$procKey]['SOURCE'] ?? '');
        $done = (($procSettings[$procKey]['DONE'] ?? 'N') === 'Y');
        // время старта прогона (для отображения общего времени на клиенте)
        $runStarted = (int)($procSettings[$procKey]['RUN_STARTED'] ?? 0);

        $command = 'php '.rtrim(\Bitrix\Main\Application::getDocumentRoot(), '/\\').'bitrix/modules/awz.allxls/cron/run.php '.($id > 0 ? $id : 'all');

        return [
            'id' => $id,
            'active' => $active,
            'schedule' => $schedule,
            'step_time' => (int)($profile['CRON_HIT_TIME'] ?? 0),
            'pause' => (int)($profile['CRON_PAUSE'] ?? 0),
            'schedule_valid' => ($schedule === '') ? false : CronSchedule::validate($schedule),
            // способ запуска: cron (общее cron-задание) | cron_own (отдельное
            // cron-задание) | agent (агент Bitrix)
            'mode' => (string)($procSettings['CRON_MODE'] ?? 'agent'),
            // статус агента: существует/активен/дубликаты/отставание
            'agent' => CronAgents::status(),
            'running' => CronRunner::isProfileLocked($id),
            'start_time' => $fmt($profile['CRON_START_TIME'] ?? null),
            'next_run' => $fmt($nextRun),
            'last_end' => $fmt($profile['DATE_LAST_END'] ?? null),
            'last_err' => (string)($profile['CRON_LAST_ERR'] ?? ''),
            'stats_view' => $statsView,
            'source' => $source,
            'done' => $done,
            'run_started' => $runStarted,
            'command' => $command,
        ];
    }

    /**
     * Список профилей сторонних модулей.
     *
     * Экшен: awz:allxls.api.profiles.externals
     *
     * Модули-провайдеры отдают свои профили через событие D7
     * onAwzAllxlsGetExternalProfiles (модуль awz.allxls):
     * обработчик добавляет EventResult(SUCCESS, ['items' => [
     *   ['MODULE' => 'vendor.module', 'ID' => 'ид-профиля-провайдера', 'TYPE' => 'import|export', 'DESC' => '...'],
     * ]]).
     * Идентификатор и название профиля провайдера формируются его модулем
     * (см. externalProfileId/externalProfileName — заготовки на стороне awz.allxls).
     *
     * @return array {items: [{MODULE, ID, NAME, TYPE, DESC}], count}
     */
    public function externalsAction(): array
    {
        $items = [];
        try {
            $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetExternalProfiles', []);
            $event->send();
            foreach ($event->getResults() as $eventResult) {
                $params = $eventResult->getParameters();
                $eventItems = is_array($params['items'] ?? null) ? $params['items'] : [];
                foreach ($eventItems as $item) {
                    if (!is_array($item)) continue;
                    $items[] = [
                        'MODULE' => $this->externalProfileModule($item),
                        'ID' => $this->externalProfileId($item),
                        'NAME' => $this->externalProfileName($item),
                        'TYPE' => (string)($item['TYPE'] ?? 'import'),
                        'DESC' => (string)($item['DESC'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return [];
        }

        // встроенные провайдеры: kda.importexcel, esol.importexportexcel, esol.importxml
        foreach ([\Awz\Allxls\Helper\External\KdaImportexcel::class, \Awz\Allxls\Helper\External\EsolImportexportexcel::class, \Awz\Allxls\Helper\External\EsolImportxml::class] as $providerClass) {
            if ($providerClass::available()) {
                foreach ($providerClass::profiles() as $item) {
                    $items[] = [
                        'MODULE' => $this->externalProfileModule($item),
                        'ID' => $this->externalProfileId($item),
                        'NAME' => $this->externalProfileName($item),
                        'TYPE' => (string)($item['TYPE'] ?? 'import'),
                        'DESC' => (string)($item['DESC'] ?? ''),
                        'ACTIVE' => (string)($item['ACTIVE'] ?? 'Y'),
                        'SORT' => (int)($item['SORT'] ?? 0),
                    ];
                }
            }
        }

        return ['items' => $items, 'count' => count($items)];
    }

    /**
     * Детальная информация по профилю стороннего модуля.
     *
     * Экшен: awz:allxls.api.profiles.externalinfo
     *
     * Провайдер отдает детали через событие D7
     * onAwzAllxlsGetExternalProfile (модуль awz.allxls; параметры module/id):
     * обработчик добавляет EventResult(SUCCESS, ['profile' => [...]])
     * — первый непустой результат используется как ответ.
     *
     * @param string $module код модуля-провайдера
     * @param string $id идентификатор профиля у провайдера
     * @return array|null {module, id, profile}
     */
    public function externalinfoAction(string $module = '', string $id = '')
    {
        $module = trim((string)$module);
        $id = trim((string)$id);
        if ($module === '' || $id === '') {
            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_EXTERNAL_PARAMS'),
                'EXTERNAL_PARAMS'
            ));
            return null;
        }
        try {
            $event = new \Bitrix\Main\Event('awz.allxls', 'onAwzAllxlsGetExternalProfile', [
                'module' => $module,
                'id' => $id,
            ]);
            $event->send();
            foreach ($event->getResults() as $eventResult) {
                $params = $eventResult->getParameters();
                $profile = is_array($params['profile'] ?? null) ? $params['profile'] : [];
                if (empty($profile)) continue;
                return [
                    'module' => $module,
                    'id' => $id,
                    'profile' => $profile,
                ];
            }

            // встроенные провайдеры: kda.importexcel, esol.importexportexcel, esol.importxml
            foreach ([\Awz\Allxls\Helper\External\KdaImportexcel::class, \Awz\Allxls\Helper\External\EsolImportexportexcel::class, \Awz\Allxls\Helper\External\EsolImportxml::class] as $providerClass) {
                if ($module !== $providerClass::MODULE || !$providerClass::available()) continue;
                $profile = $providerClass::info($id);
                if (is_array($profile) && !empty($profile)) {
                    return [
                        'module' => $module,
                        'id' => $id,
                        'profile' => $profile,
                    ];
                }
            }

            $this->addError(new \Bitrix\Main\Error(
                Loc::getMessage('AWZ_ALLXLS_API_PROFILES_EXTERNAL_NOT_FOUND'),
                'EXTERNAL_NOT_FOUND'
            ));
            return null;
        } catch (\Throwable $e) {
            $this->addError(new \Bitrix\Main\Error($e->getMessage(), 'EXCEPTION'));
            return null;
        }
    }

    /**
     * Заготовка: код модуля-провайдера из элемента списка.
     * Формат элемента определяется провайдером — при необходимости
     * переопределите разбор здесь.
     *
     * @param array $item элемент из события onAwzAllxlsGetExternalProfiles
     * @return string
     */
    protected function externalProfileModule(array $item): string
    {
        // TODO: реализовать извлечение кода модуля-провайдера
        return (string)($item['MODULE'] ?? '');
    }

    /**
     * Заготовка: идентификатор профиля стороннего модуля.
     * Реализуйте приведение идентификатора провайдера к единому виду
     * (например, строковый ключ для дальнейшего вызова externalInfo).
     *
     * @param array $item элемент из события onAwzAllxlsGetExternalProfiles
     * @return string
     */
    protected function externalProfileId(array $item): string
    {
        // TODO: реализовать получение идентификатора профиля стороннего модуля
        return (string)($item['ID'] ?? '');
    }

    /**
     * Заготовка: название профиля стороннего модуля.
     * Реализуйте отображаемое название (с учетом языка/данных провайдера).
     *
     * @param array $item элемент из события onAwzAllxlsGetExternalProfiles
     * @return string
     */
    protected function externalProfileName(array $item): string
    {
        // TODO: реализовать получение названия профиля стороннего модуля
        return (string)($item['NAME'] ?? '');
    }

    /**
     * Нормализация списка кастомных полей: NAME, CODE, AUTO (Y/N).
     * Код генерируется транслитерацией названия, если не задан.
     *
     * @param array $customFields массив кастомных полей
     * @return array
     */
    protected function normalizeCustomFields(array $customFields): array
    {
        $normalized = [];
        foreach ($customFields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = trim((string)($field['NAME'] ?? ''));
            if ($name === '') {
                continue;
            }
            $code = trim((string)($field['CODE'] ?? ''));
            if ($code === '') {
                $code = self::normalizePropertyCode($name, '');
            }
            if ($code === '') {
                continue;
            }
            $normalized[$code] = [
                'NAME' => $name,
                'CODE' => $code,
            ];
        }
        return $normalized;
    }

    protected static function normalizePropertyCode(string $name, string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') {
            $code = ProfilesHelper::transliterate($name);
        }
        $code = str_replace(['-', ' ', '—', '–'], '_', $code);
        $code = preg_replace('/[^A-Za-z0-9_]/', '', $code);
        if ($code === '') {
            return '';
        }
        // все буквы заглавные
        $code = mb_strtoupper($code);
        // не начинается с цифры
        if (preg_match('/^\d/', $code)) {
            $code = 'P' . $code;
        }
        return $code;
    }
}
