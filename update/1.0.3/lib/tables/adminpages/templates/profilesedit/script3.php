<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 3 (настройки листов).
 * Доступные переменные:
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;

// jQuery + select2 для селектов сопоставления (поиск, группировка)
// select2 (js+css) подключается через ext awz_allxls (include.php)
\Bitrix\Main\UI\Extension::load(['jquery3','ui.layout-form']);
$asset = \Bitrix\Main\Page\Asset::getInstance();
$asset->addString('<script>window.jQuery && jQuery(function($){ if($.fn.select2){ $.fn.select2.defaults.set("language","ru"); } });</script>');

if(\Bitrix\Main\Loader::includeModule('awz.admin')) {
    $arTreeDescr = [
            'js' => '/bitrix/js/awz.admin/core_tree.js',
            'css' => '/bitrix/panel/awz.admin/catalog_cond.css',
            'lang' => '/bitrix/modules/awz.admin/lang/ru/conditions.php',
            'rel' => [
                    'core',
                    'date',
                    'window',
            ],
    ];
    CJSCore::RegisterExt('awz_core_condtree', $arTreeDescr);
    CJSCore::Init(['awz_core_condtree','jquery3']);
}

?>
<script>
    BX.message({
        AWZ_ALLXLS_PROFILE_SETTINGS_SAMPLE_MAX: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_SAMPLE_MAX'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_ADD_SAMPLE_HINT: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_ADD_SAMPLE_HINT'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_HIDE_SAMPLE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_HIDE_SAMPLE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_SAVED'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_ERROR: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_ERROR'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_LOAD_ERROR: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_LOAD_ERROR'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_INCLUDE: '',
        AWZ_ALLXLS_PROFILE_SETTINGS_MANAGE_FIELDS: '',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_DRAG: '',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_LETTER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_COL_LETTER'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_MAP: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_COL_MAP'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_MAP_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_MAP_EMPTY'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_ADD_MAP: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_ADD_MAP'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_MAP_SETTINGS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_MAP_SETTINGS'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_MAP_DELETE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_MAP_DELETE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_MAP_CHOOSE_FIRST: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_MAP_CHOOSE_FIRST'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_SAVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_SAVE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_CANCEL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_CANCEL'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_ALIAS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_COL_ALIAS'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_ALIAS_HINT: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_ALIAS_HINT'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_COL_SAMPLE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_COL_SAMPLE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_HEADER_ROW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_HEADER_ROW'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_SHEET_ACTIVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_SHEET_ACTIVE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_SHOW_INACTIVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_SHOW_INACTIVE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_HIDE_INACTIVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_HIDE_INACTIVE'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_EXPAND: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPAND'))?>',
        AWZ_ALLXLS_PROFILE_SETTINGS_COLLAPSE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_COLLAPSE'))?>',
        AWZ_ALLXLS_PROFILE_FILE_CACHE_PENDING: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_CACHE_PENDING') ?: 'Файл по внешней ссылке еще не скачан. Скачивание выполняется на crontab/агенте — страница обновится автоматически после готовности файла.')?>'
    });
    BX.ready(function () {
        window.awzAllxlsProfilesEditSettings = new Awz.Allxls.ProfilesEditSettings({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            filePath: '<?=\CUtil::JSEscape($filePath ?? '')?>',
            loadAction: 'awz:allxls.api.profiles.settings',
            saveAction: 'awz:allxls.api.profiles.savesettings',
            cacheStatusAction: 'awz:allxls.api.profiles.filecachestatus',
            containerId: 'awz_allxls_profile_settings_container',
            loaderId: 'awz_allxls_profile_settings_loader',
            saveBtnId: 'awz_allxls_profile_settings_save',
            saveBtnIdNext: 'awz_allxls_profile_settings_save_next',
        });
        window.awzAllxlsProfilesEditSettings.init();
        //if (window.Awz.Allxls.Fly) new Awz.Allxls.Fly().init();
    });
</script>
