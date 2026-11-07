<?php
use Bitrix\Main\Localization\Loc,
    Bitrix\Main\EventManager,
    Bitrix\Main\ModuleManager,
	Bitrix\Main\Config\Option,
    Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class awz_allxls extends CModule
{
	var $MODULE_ID = "awz.allxls";
	var $MODULE_VERSION;
	var $MODULE_VERSION_DATE;
	var $MODULE_NAME;
	var $MODULE_DESCRIPTION;

    public function __construct()
	{
        $arModuleVersion = array();
        include(__DIR__.'/version.php');

        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];

        $this->MODULE_NAME = Loc::getMessage("AWZ_ALLXLS_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage("AWZ_ALLXLS_MODULE_DESCRIPTION");
		$this->PARTNER_NAME = Loc::getMessage("AWZ_PARTNER_NAME");
		$this->PARTNER_URI = "https://zahalski.dev/";

		return true;
	}

    function DoInstall()
    {
        global $APPLICATION, $step;

        $this->InstallFiles();
        $this->InstallDB();
        $this->checkOldInstallTables();
        $this->InstallEvents();
        $this->createAgents();

        ModuleManager::RegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage("AWZ_ALLXLS_MODULE_NAME"),
            $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'. $this->MODULE_ID .'/install/solution.php'
        );

        return true;
    }

    function DoUninstall()
    {
        global $APPLICATION, $step;

        $step = intval($step);
        if($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('AWZ_ALLXLS_INSTALL_TITLE'),
                $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'. $this->MODULE_ID .'/install/unstep.php'
            );
        }
        elseif($step == 2) {
            if($_REQUEST['save'] != 'Y' && !isset($_REQUEST['save'])) {
                $this->UnInstallDB();
            }
            $this->UnInstallFiles();
            $this->UnInstallEvents();
            $this->deleteAgents();

            if($_REQUEST['saveopts'] != 'Y' && !isset($_REQUEST['saveopts'])) {
                \Bitrix\Main\Config\Option::delete($this->MODULE_ID);
            }

            ModuleManager::UnRegisterModule($this->MODULE_ID);
            return true;
        }
		
    }

    function InstallDB()
    {
        global $DB, $DBType, $APPLICATION;
        $connection = \Bitrix\Main\Application::getConnection();
        $this->errors = false;
        if(!$this->errors) {
            $this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/" . $this->MODULE_ID . "/install/db/".$connection->getType()."/install.sql");
        }
        if (!$this->errors) {
            return true;
        } else {
            $APPLICATION->ThrowException(implode("", $this->errors));
            return $this->errors;
        }
    }

    function UnInstallDB()
    {
        global $DB, $DBType, $APPLICATION;
        $connection = \Bitrix\Main\Application::getConnection();
        $this->errors = false;
        if (!$this->errors) {
            $this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/" . $this->MODULE_ID . "/install/db/" . $connection->getType() . "/uninstall.sql");
        }
        if (!$this->errors) {
            return true;
        }
        else {
            $APPLICATION->ThrowException(implode("", $this->errors));
            return $this->errors;
        }
    }

    function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnAfterUserUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnAfterUserAdd',
            $this->MODULE_ID, '\\Awz\\Allxls\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->registerEventHandlerCompatible(
            'iblock', 'OnAfterIBlockElementUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnAfterIBlockElementUpdate'
        );
        $eventManager->registerEventHandlerCompatible(
            'iblock', 'OnBeforeIBlockElementDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforeIBlockElementDelete'
        );
        if (ModuleManager::isModuleInstalled('catalog')) {
            $eventManager->registerEventHandlerCompatible(
                'catalog', 'OnProductUpdate',
                $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnProductUpdate'
            );
            $eventManager->registerEventHandlerCompatible(
                'catalog', 'OnBeforeProductDelete',
                $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforeProductDelete'
            );
            $eventManager->registerEventHandlerCompatible(
                'catalog', 'OnPriceAdd',
                $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnPriceAdd'
            );
            $eventManager->registerEventHandlerCompatible(
                'catalog', 'OnPriceUpdate',
                $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnPriceUpdate'
            );
            $eventManager->registerEventHandlerCompatible(
                'catalog', 'OnBeforePriceDelete',
                $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforePriceDelete'
            );
            // D7-события цены (ORM PriceTable)
            $eventManager->addEventHandler(
                'catalog', '\Bitrix\Catalog\PriceTable::onBeforeAdd',
                ['\\Awz\\Allxls\\Handlers', 'onPriceD7']
            );
            $eventManager->addEventHandler(
                'catalog', '\Bitrix\Catalog\PriceTable::onBeforeUpdate',
                ['\\Awz\\Allxls\\Handlers', 'onPriceD7']
            );
            $eventManager->addEventHandler(
                'catalog', '\Bitrix\Catalog\PriceTable::onBeforeDelete',
                ['\\Awz\\Allxls\\Handlers', 'onPriceD7']
            );
            // D7-события товара (ORM ProductTable)
            $eventManager->addEventHandler(
                'catalog', '\Bitrix\Catalog\ProductTable::onBeforeUpdate',
                ['\\Awz\\Allxls\\Handlers', 'onProductD7']
            );
            $eventManager->addEventHandler(
                'catalog', '\Bitrix\Catalog\ProductTable::onBeforeDelete',
                ['\\Awz\\Allxls\\Handlers', 'onProductD7']
            );
        }
        return true;
    }

    function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->unRegisterEventHandler(
            'main', 'OnAfterUserUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnAfterUserAdd',
            $this->MODULE_ID, '\\Awz\\Allxls\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'iblock', 'OnAfterIBlockElementUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnAfterIBlockElementUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'iblock', 'OnBeforeIBlockElementDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforeIBlockElementDelete'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', 'OnProductUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnProductUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', 'OnBeforeProductDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforeProductDelete'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', 'OnPriceAdd',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnPriceAdd'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', 'OnPriceUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnPriceUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', 'OnBeforePriceDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'OnBeforePriceDelete'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', '\Bitrix\Catalog\PriceTable::onBeforeAdd',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'onPriceD7'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', '\Bitrix\Catalog\PriceTable::onBeforeUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'onPriceD7'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', '\Bitrix\Catalog\PriceTable::onBeforeDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'onPriceD7'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', '\Bitrix\Catalog\ProductTable::onBeforeUpdate',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'onProductD7'
        );
        $eventManager->unRegisterEventHandler(
            'catalog', '\Bitrix\Catalog\ProductTable::onBeforeDelete',
            $this->MODULE_ID, '\\Awz\\Allxls\\Handlers', 'onProductD7'
        );
        return true;
    }

    function InstallFiles()
    {
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/components/allxls.config.permissions/",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/components/awz/allxls.config.permissions",
            true, true
        );
        // гаджет админки: активные импорты/экспорты
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/gadgets",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/gadgets",
            true, true
        );
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/js",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/js/".$this->MODULE_ID,
            true, true
        );
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/css",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/css/".$this->MODULE_ID,
            true, true
        );
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/admin",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/admin",
            true, true
        );
        return true;
    }

    function UnInstallFiles()
    {
        DeleteDirFilesEx("/bitrix/components/awz/allxls.config.permissions");
        DeleteDirFilesEx("/bitrix/gadgets/".$this->MODULE_ID);
        DeleteDirFilesEx("/bitrix/js/".$this->MODULE_ID);
        DeleteDirFilesEx("/bitrix/css/".$this->MODULE_ID);
        DeleteDirFiles(
            $_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/".$this->MODULE_ID."/install/admin",
            $_SERVER["DOCUMENT_ROOT"]."/bitrix/admin");
        return true;
    }

    function createAgents() {
        // агент запуска импортов/экспортов по расписанию (без crontab);
        // классы lib еще не автозагружаются до RegisterModule — подключаем напрямую
        $agentsFile = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$this->MODULE_ID.'/lib/cron/agents.php';
        if (file_exists($agentsFile)) {
            require_once($agentsFile);
            \Awz\Allxls\Cron\Agents::register();
        }
        return true;
    }

    function deleteAgents() {
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        return true;
    }

    function checkOldInstallTables()
    {
        // миграции: добавление колонок cron-запуска в b_awz_allxls_profiles
        $connection = \Bitrix\Main\Application::getConnection();
        $helper = $connection->getSqlHelper();
        $table = $helper->quote('b_awz_allxls_profiles');

        $existing = [];
        try {
            if ($connection->getType() === 'pgsql') {
                $rs = $connection->query("SELECT column_name FROM information_schema.columns WHERE table_name='b_awz_allxls_profiles'");
                while ($row = $rs->fetch()) {
                    $existing[strtolower($row['COLUMN_NAME'])] = true;
                }
            } else {
                $rs = $connection->query("SHOW COLUMNS FROM ".$table);
                while ($row = $rs->fetch()) {
                    $existing[strtolower($row['Field'] ?? $row['field'] ?? '')] = true;
                }
            }
        } catch (\Exception $e) {
            return true;
        }

        $pgType = ($connection->getType() === 'pgsql');
        $columns = [
            'CRON_ACTIVE' => $pgType ? "char(1) NOT NULL DEFAULT 'N'" : "char(1) NOT NULL DEFAULT 'N'",
            'CRON_SCHEDULE' => 'varchar(50) DEFAULT NULL',
            'CRON_HIT_TIME' => ($pgType ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
            'CRON_PAUSE' => ($pgType ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
            'CRON_FALLS' => ($pgType ? 'integer NOT NULL DEFAULT 0' : "int NOT NULL DEFAULT '0'"),
            'CRON_RUNNING' => $pgType ? "char(1) NOT NULL DEFAULT 'N'" : "char(1) NOT NULL DEFAULT 'N'",
            'CRON_START_TIME' => ($pgType ? 'timestamp' : 'datetime').' DEFAULT NULL',
            'CRON_LAST_ERR' => 'varchar(255) DEFAULT NULL',
        ];
        foreach ($columns as $name => $sqlType) {
            if (!isset($existing[strtolower($name)])) {
                try {
                    $connection->queryExecute("ALTER TABLE ".$table." ADD COLUMN ".$helper->quote($name)." ".$sqlType);
                } catch (\Exception $e) {
                    // колонка могла быть добавлена параллельным процессом
                }
            }
        }
        // индекс по расписанию
        if (!isset($existing['CRON_ACTIVE'])) {
            try {
                $connection->queryExecute("CREATE INDEX IX_AWZ_ALLXLS_PROFILES_CRON ON ".$table." (".$helper->quote('CRON_ACTIVE').", ".$helper->quote('DATE_NEXT_RUN').")");
            } catch (\Exception $e) {
            }
        }

        return true;
    }

}