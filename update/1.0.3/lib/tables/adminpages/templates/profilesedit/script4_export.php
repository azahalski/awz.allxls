<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 4 (экспорт).
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 * @var array  $settings  настройки профиля
 */

use Bitrix\Main\Localization\Loc;

\Bitrix\Main\UI\Extension::load('ui.progressbar');
?>
<script>
    BX.message({
        AWZ_ALLXLS_PROFILE_PROCESS_START: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_START'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_RUNNING: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_RUNNING'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_STOP: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STOP'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_STOPPED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STOPPED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DONE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DONE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_FINISHED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_FINISHED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_INTERRUPTED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_CRON: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_CRON'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_CRON_WAIT: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_CRON_WAIT'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_ERROR: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_ERROR'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_STATS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STATS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_ERRORS_TITLE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_ERRORS_TITLE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_PROCESSED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_PROCESSED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_RESUME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_RESUME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_TIME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_TIME'))?>',
        AWZ_ALLXLS_EXPORT_FILE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXPORT_FILE'))?>',
        AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_ITEM: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_ITEM'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_ITEM_SKIPPED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_ITEM_SKIPPED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_COL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG_COL'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_FIELD: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG_FIELD'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_RAW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG_RAW'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_VALUE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG_VALUE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_ELEM: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_DEBUG_ELEM'))?>'
    });
    BX.ready(function () {
        var processState = <?=json_encode($settings['PROCESS_EXPORT'] ?? null, JSON_UNESCAPED_UNICODE) ?: 'null'?>;
        window.awzAllxlsProfilesEditExportProcess = new Awz.Allxls.ProfilesEditExportProcess({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            processAction: 'awz:allxls.api.pexport.export',
            barId: 'awz_allxls_profile_process_bar',
            statusId: 'awz_allxls_profile_process_status',
            itemId: 'awz_allxls_profile_process_item',
            statsId: 'awz_allxls_profile_process_stats',
            errorsId: 'awz_allxls_profile_process_errors',
            startBtnId: 'awz_allxls_profile_process_start',
            continueBtnId: 'awz_allxls_profile_process_continue',
            finishBtnId: 'awz_allxls_profile_process_finish',
            saveParamsAction: 'awz:allxls.api.pexport.saveparams',
            finishAction: 'awz:allxls.api.pexport.finish',
            interrupted: processState
        });
        window.awzAllxlsProfilesEditExportProcess.init();
    });

    // Расписание запуска (cron) обрабатывается встроенным скриптом step4.php
</script>
