<?php

namespace Awz\Allxls\Tables\AdminPages;

use Awz\Admin\Helper;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IForm;
use Awz\Admin\IParams;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Tables\SectionsTable;
use Bitrix\Main\ORM\Query\Query;

Loc::loadMessages(__FILE__);

class ProfilesEdit extends IForm implements IParams {

    /** @var int ID профиля (0 — создание, этап 1) */
    protected $id = 0;

    public function __construct($params){
        parent::__construct($params);
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $this->id = (int)($request->get('ID') ?? 0);
        if(!$this->id) $this->id = (int)($request->get('id') ?? 0);
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_TITLE');
    }

    public static function getParams(): array
    {
        return ["ENTITY" => "\\Awz\\Allxls\\Tables\\ProfilesTable"];
    }

    /**
     * Данные профиля для формы (этап 1)
     */
    protected function getProfile(): array
    {
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        if ($this->id <= 0) {
            return [
                'ID' => 0,
                'NAME' => '',
                'ACTIVE' => 'Y',
                'SECTION_ID' => 0,
                'DESC' => '',
                'TYPE'=>$request->get('export') == 'Y' ? 'export' : 'import'
            ];
        }
        $row = ProfilesTable::getById($this->id)->fetch();
        if(!$row['TYPE']) $row['TYPE'] = 'import';
        if($row['TYPE'] &&
            (
                ($row['TYPE']=='import' && $request->get('import')!='Y')
                || ($row['TYPE']=='export' && $request->get('export')!='Y')
            )
        ){
            $url = '/bitrix/admin/awz_allxls_profiles_edit.php?lang='.LANGUAGE_ID.'&step='.(int)$request->get('step').'&'.$row['TYPE'].'=Y&ID='.$row['ID'];
            \LocalRedirect($url);
        }
        return is_array($row) ? $row : [];
    }

    /**
     * Список разделов для select
     */
    protected function getSections(): array
    {
        $result = [];
        $rs = SectionsTable::getList([
            'select' => ['ID', 'NAME'],
            'order' => ['NAME' => 'ASC']
        ]);
        while ($row = $rs->fetch()) {
            $result[(int)$row['ID']] = $row['NAME'];
        }
        return $result;
    }

    public function defaultInterface(){
        global $APPLICATION, $adminPage, $USER, $adminMenu, $adminChain, $POST_RIGHT;

        if(
            (defined("TIMELIMIT_EDITION") && TIMELIMIT_EDITION == "Y") ||
            (defined("DEMO") && DEMO == "Y")
        )
        {
            global $SiteExpireDate;
        }

        \CJSCore::Init(['ui.notification']);

        require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

        $profile = $this->getProfile();
        $sections = $this->getSections();
        $profileId = (int)($profile['ID'] ?? 0);

        // активный cron-запуск: редактирование профиля запрещено до завершения
        $cronRunning = false;
        if ($profileId > 0 && class_exists('\Awz\Allxls\Cron\Runner')) {
            try {
                $cronRunning = \Awz\Allxls\Cron\Runner::isProfileLocked($profileId);
            } catch (\Throwable $e) {
                $cronRunning = false;
            }
        }
        if ($cronRunning) {
            echo '<div class="adm-info-message adm-info-message-warning">'
                .Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_RUNNING')
                .'</div>';
        }
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $listUrl = '/bitrix/admin/awz_allxls_profiles_list.php?lang='.LANGUAGE_ID.'&'.$profile['TYPE'].'=Y';
        $step = (int)($request->get('step') ?? 1);
        if ($step < 1) $step = 1;
        if ($step > 4) $step = 4;
        if ($step > 1 && $profileId <= 0) $step = 1;

        // JS каждого шага подключается отдельным файлом
        $isExport = (($profile['TYPE'] ?? 'import') === 'export');
        \CJSCore::Init(['awz_allxls']);
        \Bitrix\Main\Page\Asset::getInstance()->addCss('/bitrix/css/awz.allxls/profiles_edit.css');

        if ($step === 2) {
            $settings = $profile['SETTINGS'] ?? null;
            if ($isExport) {
                // экспорт: файл выгрузки с автозаполнением пути
                $this->includeTemplate('step2_export', [
                    'profile' => $profile,
                    'settings' => $settings,
                    'profileId' => $profileId,
                    'listUrl' => $listUrl
                ]);
                $this->includeTemplate('script2_export', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl
                ]);
            } else {
                $this->includeTemplate('step2', [
                    'profile' => $profile,
                    'settings' => $settings,
                    'profileId' => $profileId,
                    'listUrl' => $listUrl
                ]);
                $this->includeTemplate('script2', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl,
                    'settings' => $settings
                ]);
            }
        } elseif ($step === 3) {
            $settings = $profile['SETTINGS'] ?? null;
            if ($isExport) {
                // экспорт: настройка колонок выгрузки по листам
                $this->includeTemplate('step3_export', [
                    'profile' => $profile,
                    'settings' => $settings,
                    'profileId' => $profileId,
                    'listUrl' => $listUrl
                ]);
                $this->includeTemplate('script3_export', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl,
                    'settings' => $settings
                ]);
            } else {
                $this->includeTemplate('step3', [
                    'profile' => $profile,
                    'settings' => $settings,
                    'profileId' => $profileId,
                    'listUrl' => $listUrl
                ]);
                $this->includeTemplate('script3', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl,
                    'filePath' => (string)($settings['FILE_PATH'] ?? '')
                ]);
            }
        } elseif ($step === 4) {
            $settings = $profile['SETTINGS'] ?? null;
            $this->includeTemplate('step4', [
                'profile' => $profile,
                'settings' => $settings,
                'profileId' => $profileId,
                'listUrl' => $listUrl
            ]);
            if ($isExport) {
                $this->includeTemplate('script4_export', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl,
                    'settings' => $settings
                ]);
            } else {
                $this->includeTemplate('script4', [
                    'profileId' => $profileId,
                    'listUrl' => $listUrl,
                    'settings' => $settings
                ]);
            }
        } else {
            $this->includeTemplate('step1', [
                'profile' => $profile,
                'sections' => $sections,
                'profileId' => $profileId,
                'listUrl' => $listUrl
            ]);

            $this->includeTemplate('script', [
                'profileId' => $profileId,
                'listUrl' => $listUrl
            ]);
        }

        require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php');
    }

    /**
     * Подключение html-шаблона из templates/<имя класса>/<шаг>.php
     * Переменные из $data доступны в шаблоне.
     */
    protected function includeTemplate(string $step, array $data = []): void
    {
        $shortName = (new \ReflectionClass(static::class))->getShortName();
        $file = __DIR__.'/templates/'.strtolower($shortName).'/'.$step.'.php';
        if (!file_exists($file)) {
            return;
        }
        extract($data, EXTR_SKIP);
        /** @noinspection PhpIncludeInspection */
        include $file;
    }
}
