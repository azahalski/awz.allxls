<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 3 (экспорт).
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;
?>
<script>
    BX.message({
        AWZ_ALLXLS_EXPORT_SHEET: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_SHEET'))?>',
        AWZ_ALLXLS_EXPORT_ADD_COL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_ADD_COL'))?>',
        AWZ_ALLXLS_EXPORT_DEL_COL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_DEL_COL'))?>',
        AWZ_ALLXLS_EXPORT_DEL_SHEET: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_DEL_SHEET'))?>',
        AWZ_ALLXLS_EXPORT_ADD_SHEET: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_ADD_SHEET'))?>',
        AWZ_ALLXLS_EXPORT_COL_NAME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_NAME'))?>',
        AWZ_ALLXLS_EXPORT_COL_FIELD: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_FIELD'))?>',
        AWZ_ALLXLS_EXPORT_FIELD_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_FIELD_EMPTY'))?>',
        AWZ_ALLXLS_EXPORT_SETTINGS_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_SAVED'))?>',
        AWZ_ALLXLS_EXPORT_SETTINGS_ERROR: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_ERROR'))?>',
        AWZ_ALLXLS_EXPORT_SETTINGS_LOAD_ERROR: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SETTINGS_LOAD_ERROR'))?>',
        AWZ_ALLXLS_EXPORT_SHEETS_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_SHEETS_EMPTY'))?>',
        AWZ_ALLXLS_EXPORT_COL_SETTINGS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_SETTINGS'))?>',
        AWZ_ALLXLS_EXPORT_COL_SETTINGS_SAVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_SETTINGS_SAVE'))?>',
        AWZ_ALLXLS_EXPORT_COL_SETTINGS_CANCEL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_SETTINGS_CANCEL'))?>',
        AWZ_ALLXLS_EXPORT_COL_TRIM: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_TRIM'))?>',
        AWZ_ALLXLS_EXPORT_COL_CASE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_CASE'))?>',
        AWZ_ALLXLS_EXPORT_COL_CASE_NONE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_CASE_NONE'))?>',
        AWZ_ALLXLS_EXPORT_COL_CASE_LOWER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_CASE_LOWER'))?>',
        AWZ_ALLXLS_EXPORT_COL_CASE_UPPER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_CASE_UPPER'))?>',
        AWZ_ALLXLS_EXPORT_COL_CASE_FIRSTUPPER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_CASE_FIRSTUPPER'))?>',
        AWZ_ALLXLS_EXPORT_COL_DELIMETER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_DELIMETER'))?>',
        AWZ_ALLXLS_EXPORT_COL_RULES: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_RULES'))?>',
        AWZ_ALLXLS_EXPORT_COL_RULE_SKIP_ROW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_RULE_SKIP_ROW'))?>',
        AWZ_ALLXLS_EXPORT_COL_ACTIVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_ACTIVE'))?>',
        AWZ_ALLXLS_EXPORT_COL_MOVE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_COL_MOVE'))?>'
    });
    BX.ready(function () {
        window.awzAllxlsProfilesEditExportSettings = new Awz.Allxls.ProfilesEditExportSettings({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            loadAction: 'awz:allxls.api.pexport.settings',
            saveAction: 'awz:allxls.api.pexport.savesettings',
            containerId: 'awz_allxls_profile_export_container',
            loaderId: 'awz_allxls_profile_export_loader',
            saveBtnId: 'awz_allxls_profile_export_save',
            saveBtnNextId: 'awz_allxls_profile_export_save_next',
            singleSheet: <?=((string)($settings['FILE_PATH'] ?? '') !== '' && strtolower(pathinfo((string)$settings['FILE_PATH'], PATHINFO_EXTENSION)) === 'csv') ? 'true' : 'false'?>
        });
        window.awzAllxlsProfilesEditExportSettings.init();
    });
</script>
