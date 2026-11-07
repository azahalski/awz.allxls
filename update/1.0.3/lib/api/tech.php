<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File;
use Bitrix\Main\Engine\ActionFilter;
use Awz\Allxls\Helper\ProfilesHelper;

/**
 * Технические методы API: загрузка файлов в /upload/awz.allxls/import/,
 * скачивание файла по внешней ссылке, список инфоблоков.
 *
 * Экшены: awz:allxls.api.tech.<action>
 */
class Tech extends \Bitrix\Main\Engine\Controller {

    /** Допустимые расширения итогового файла (gz — с распаковкой на сервере) */
    protected array $allowExtensions = ['csv', 'xlsx', 'xls', 'gz'];

    /** Расширения после распаковки .gz */
    protected array $unpackedExtensions = ['csv', 'xlsx', 'xls'];

    /**
     * Constructor Controller.
     * @param Request|null $request
     */
    public function __construct(Request $request = null)
    {
        \Bitrix\Main\Loader::includeModule('awz.allxls');
        parent::__construct($request);
    }

    public function configureActions()
    {
        $config = [
            'upload'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'iblocks'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'forward'=> [
                'prefilters' => []
            ]
        ];

        return $config;
    }

    /**
     * Загрузка файла в /upload/awz.allxls/import/ (folder можно переопределить).
     * Путь результата пригоден для saveFileAction (этап 2) без изменений.
     *
     * Два режима:
     *  - multipart: поле формы file (PHP upload; лимиты upload_max_filesize/post_max_size — на 60 МБ проверьте настройки сервера);
     *  - по ссылке: параметр url — сервер сам скачивает файл (опционально через proxy вида http(s)://user:pass@host:port).
     *
     * Поддерживается .gz: файл распаковывается на сервере (gzdecode) и
     * сохраняется с итоговым расширением csv/xlsx/xls (price.csv.gz -> price.csv).
     *
     * Экшен: awz:allxls.api.tech.upload
     *
     * @param array $file загруженный файл (multipart) — массив {name, type, tmp_name, size, error}
     * @param string $url внешняя ссылка на файл (http/https), если multipart не используется
     * @param string $folder подпапка внутри /upload/awz.allxls/ (по умолчанию import; транслит, [a-z0-9_-])
     * @param string $proxy прокси для скачивания по url — http(s)://user:pass@host:port
     * @return array|null {file_path, name, size}
     */
    public function uploadAction(array $file = [], string $url = '', string $folder = '', string $proxy = '')
    {
        $isUrl = (trim((string)$url) !== '');
        $tmpPath = '';
        $origName = '';

        if ($isUrl) {
            // режим скачивания по внешней ссылке
            $url = trim((string)$url);
            if (!preg_match('#^https?://#i', $url)) {
                $this->addError(new Error('Допустимы только ссылки http/https', 'URL_INVALID'));
                return null;
            }
            $folder = ($folder !== '') ? $folder : 'import';
            $tmpPath = tempnam(sys_get_temp_dir(), 'awzxls_');
            if (!$tmpPath) {
                $this->addError(new Error('Не удалось создать временный файл', 'TMP_FAIL'));
                return null;
            }
            $httpCode = 0;
            if (!$this->downloadUrl($url, $tmpPath, (string)$proxy, $httpCode)) {
                @unlink($tmpPath);
                $this->addError(new Error('Не удалось скачать файл по ссылке (HTTP '.$httpCode.')', 'URL_DOWNLOAD_FAIL'));
                return null;
            }
            $size = (int)filesize($tmpPath);
            if ($size <= 0) {
                @unlink($tmpPath);
                $this->addError(new Error('Скачан пустой файл', 'FILE_EMPTY'));
                return null;
            }
            // имя: из ссылки (query/якорь отбрасываются)
            $pathPart = parse_url($url, PHP_URL_PATH) ?: '';
            $origName = basename($pathPart);
            if ($origName === '' || $origName === '/') {
                $origName = 'download_'.date('YmdHis');
            }
        } else {
            // режим multipart
            if (!is_array($file) || empty($file['tmp_name'])) {
                $this->addError(new Error('Не передан файл (поле file, multipart) или url', 'FILE_EMPTY'));
                return null;
            }
            $err = (int)($file['error'] ?? \UPLOAD_ERR_OK);
            if ($err !== \UPLOAD_ERR_OK) {
                $this->addError(new Error('Ошибка загрузки файла, код '.$err, 'FILE_UPLOAD_ERROR'));
                return null;
            }
            if (!is_uploaded_file((string)$file['tmp_name'])) {
                $this->addError(new Error('Некорректная загрузка файла', 'FILE_NOT_UPLOADED'));
                return null;
            }
            $size = (int)($file['size'] ?? 0);
            if ($size <= 0) {
                $this->addError(new Error('Пустой файл', 'FILE_EMPTY'));
                return null;
            }
            $folder = ($folder !== '') ? $folder : 'import';
            $tmpPath = (string)$file['tmp_name'];
            $origName = (string)($file['name'] ?? '');
        }

        // расширение: по оригинальному имени, безопасный список
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowExtensions, true)) {
            if ($isUrl) @unlink($tmpPath);
            $this->addError(new Error(
                'Недопустимое расширение файла. Допустимо: '.implode(', ', $this->allowExtensions),
                'FILE_EXT_INVALID'
            ));
            return null;
        }

        // имя файла: транслит + безопасные символы
        $name = ProfilesHelper::transliterate(pathinfo($origName, PATHINFO_FILENAME));
        $name = strtolower(preg_replace('/[^a-z0-9_-]/i', '_', (string)$name));
        $name = trim($name, '_');
        if ($name === '') {
            $name = 'file_'.date('YmdHis');
        }

        // распаковка .gz: итоговое расширение — из имени без .gz
        $needGunzip = false;
        if ($ext === 'gz') {
            $innerExt = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($innerExt !== '' && in_array($innerExt, $this->unpackedExtensions, true)) {
                $name = pathinfo($name, PATHINFO_FILENAME);
                $ext = $innerExt;
            } else {
                $ext = 'csv'; // без известного внутреннего расширения считаем CSV
            }
            $needGunzip = true;
        }

        // папка: /upload/awz.allxls/<folder>/ — только один уровень
        $safeFolder = strtolower(preg_replace('/[^a-z0-9_-]/i', '_', (string)$folder));
        $relDir = '/upload/awz.allxls/'.($safeFolder !== '' ? $safeFolder.'/' : '');
        $absDir = \Bitrix\Main\Application::getDocumentRoot().$relDir;
        if (!Directory::isDirectoryExists($absDir)) {
            Directory::createDirectory($absDir);
        }

        // уникальность имени: суффикс _1, _2...
        $fileName = $name.'.'.$ext;
        $base = $name;
        $i = 0;
        while (File::isFileExists($absDir.$fileName)) {
            $i++;
            $fileName = $base.'_'.$i.'.'.$ext;
        }
        $absPath = $absDir.$fileName;

        if ($needGunzip) {
            // gzdecode в итоговый файл
            $content = @file_get_contents('compress.zlib://'.$tmpPath);
            if ($isUrl) @unlink($tmpPath);
            if ($content === false) {
                $this->addError(new Error('Не удалось распаковать .gz файл', 'GUNZIP_FAIL'));
                return null;
            }
            if (@file_put_contents($absPath, $content) === false) {
                $this->addError(new Error('Не удалось сохранить файл', 'FILE_SAVE_FAIL'));
                return null;
            }
            unset($content);
        } elseif ($isUrl) {
            // скачивание во временный файл уже выполнено — переносим в upload
            if (!@rename($tmpPath, $absPath)) {
                $ok = @copy($tmpPath, $absPath);
                @unlink($tmpPath);
                if (!$ok) {
                    $this->addError(new Error('Не удалось сохранить файл', 'FILE_SAVE_FAIL'));
                    return null;
                }
            }
        } else {
            if (!move_uploaded_file($tmpPath, $absPath)) {
                $this->addError(new Error('Не удалось сохранить файл', 'FILE_SAVE_FAIL'));
                return null;
            }
        }

        return [
            'file_path' => $relDir.$fileName,
            'name' => $fileName,
            'size' => (int)filesize($absPath),
        ];
    }

    /**
     * Скачивание файла по внешней ссылке в указанный локальный путь.
     * Поддержка прокси: http(s)://user:pass@host:port (как PROXY в профиле).
     *
     * @param string $url ссылка
     * @param string $destPath абсолютный локальный путь назначения
     * @param string $proxy опциональный прокси
     * @param int &$httpCode код HTTP-ответа
     * @return bool
     */
    protected function downloadUrl(string $url, string $destPath, string $proxy = '', int &$httpCode = 0): bool
    {
        $out = @fopen($destPath, 'wb');
        if (!$out) {
            return false;
        }
        $ch = curl_init((string)$url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'awz.allxls',
        ]);
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, str_starts_with(strtolower($proxy), 'https://') ? CURLPROXY_HTTPS : CURLPROXY_HTTP);
        }
        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);
        curl_close($ch);
        fclose($out);
        if ($curlErr !== 0 || ($httpCode > 0 && $httpCode >= 400)) {
            return false;
        }
        return filesize($destPath) > 0;
    }

    /**
     * Список инфоблоков (для выбора IBLOCK_ID на этапе 2).
     *
     * Экшен: awz:allxls.api.tech.iblocks
     *
     * @param string $filter подстрока для поиска по названию/коду инфоблока
     * @param string $type фильтр по типу инфоблока (IBLOCK_TYPE_ID)
     * @param string $activeOnly Y - только активные инфоблоки (по умолчанию)
     * @return array|null {items: [{ID, NAME, CODE, IBLOCK_TYPE_ID, ACTIVE, LID, ELEMENTS_CNT}], count}
     */
    public function iblocksAction(string $filter = '', string $type = '', string $activeOnly = 'Y')
    {
        $filter = trim((string)$filter);
        $type = trim((string)$type);

        if (!Loader::includeModule('iblock')) {
            $this->addError(new Error('Модуль iblock не установлен', 'NO_MODULE'));
            return null;
        }

        $arParams = ['CHECK_PERMISSIONS' => 'N'];
        if ($activeOnly !== 'N') {
            $arParams['ACTIVE'] = 'Y';
        }
        if ($type !== '') {
            $arParams['IBLOCK_TYPE_ID'] = $type;
        }
        if ($filter !== '') {
            $arParams['?NAME'] = $filter;
        }

        $items = [];
        $res = \CIBlock::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            $arParams,
            true
        );
        while ($row = $res->Fetch()) {
            $items[] = [
                'ID' => (int)$row['ID'],
                'NAME' => (string)$row['NAME'],
                'CODE' => (string)($row['CODE'] ?? ''),
                'IBLOCK_TYPE_ID' => (string)$row['IBLOCK_TYPE_ID'],
                'ACTIVE' => (string)$row['ACTIVE'],
                'LID' => (string)($row['LID'] ?? ''),
                'ELEMENTS_CNT' => (int)($row['ELEMENT_CNT'] ?? 0),
            ];
        }

        return [
            'items' => $items,
            'count' => count($items),
        ];
    }
}
