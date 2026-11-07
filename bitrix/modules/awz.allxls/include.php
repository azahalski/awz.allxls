<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();

// Проверяем, знает ли уже Битрикс/проект про этот класс
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')
    && version_compare(PHP_VERSION, '8.2.0', '>=')
) {
    $moduleAutoloader = __DIR__ . '/vendor/autoload.php';
    if (file_exists($moduleAutoloader)) {
        require_once $moduleAutoloader;
    }
}

// собственные обработчики макросов правил:
// - onAwzAllxlsGetMacros — добавление макросов #ENTITY_<КОД># в список;
// - onAwzAllxlsExpandMacros — расчет макросов #ENTITY_<ПОЛЕ>#
\Bitrix\Main\EventManager::getInstance()->registerEventHandler(
    'awz.allxls', 'onAwzAllxlsGetMacros',
    'awz.allxls', '\Awz\Allxls\Handlers', 'onAwzAllxlsGetMacros'
);
\Bitrix\Main\EventManager::getInstance()->registerEventHandler(
    'awz.allxls', 'onAwzAllxlsExpandMacros',
    'awz.allxls', '\Awz\Allxls\Handlers', 'onAwzAllxlsExpandMacros'
);


// составное расширение для обратной совместимости (все шаги сразу)
\CJSCore::RegisterExt('awz_allxls', array(
    'js'   => [
        '/bitrix/js/awz.allxls/select2/select2.min.js',
        '/bitrix/js/awz.allxls/profiles_edit_step1.js',
        '/bitrix/js/awz.allxls/profiles_edit_step2.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3_export.js',
        '/bitrix/js/awz.allxls/profiles_edit_step4.js',
        '/bitrix/js/awz.allxls/profiles_edit_step4_export.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3_sample.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3_mapdialog.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3_newprop.js',
        '/bitrix/js/awz.allxls/profiles_edit_step3_export_ext.js'
    ],
    'css'  => ['/bitrix/css/awz.allxls/profiles_edit.css','/bitrix/js/awz.allxls/select2/select2.min.css'],
    'rel'  => ['jquery3','ajax'],
    // версия для сброса кеша браузера/ядра при изменении JS модуля
    'version' => '1.0.58',
));

