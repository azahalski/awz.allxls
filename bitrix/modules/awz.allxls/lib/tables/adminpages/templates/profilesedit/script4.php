<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 4 (запуск обработки с прогресс-баром).
 * Доступные переменные:
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;

// стандартный прогресс-бар Битрикса
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
        AWZ_ALLXLS_PROFILE_PROCESS_ITEM: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_ITEM'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_STATS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STATS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_ERRORS_TITLE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_ERRORS_TITLE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_PROCESSED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_PROCESSED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_RESUME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_RESUME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_TIME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_TIME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COLUMNS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COLUMNS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COL_ALIAS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COL_ALIAS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COL_NAME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COL_NAME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COL_FIELDS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COL_FIELDS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COL_TIME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COL_TIME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_COL_COUNT: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_COL_COUNT'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_STEP_STOPPED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STEP_STOPPED'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RAW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_RAW'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_VALUE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_VALUE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_FINAL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_FINAL'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RULES_COL: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_RULES_COL'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_ROW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_SKIP_ROW'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_HASH: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_SKIP_HASH'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RULES: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_RULES'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_NONE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_NONE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_SKIP'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_VALUE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_SKIP_VALUE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MACROS: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_MACROS'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SEARCH: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_SEARCH'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_MODE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_NEW: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_MODE_NEW'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_UPDATE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_MODE_UPDATE'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_FILTER: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_FILTER'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_TIME: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_TIME'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_T_ADD: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_T_ADD'))?>',
        AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_T_UPDATE: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_DEBUG_T_UPDATE'))?>',
        AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED'))?>'
    });
    BX.ready(function () {
        // прерванный импорт: состояние на момент обрыва
        var processState = <?=json_encode($settings['PROCESS'] ?? null, JSON_UNESCAPED_UNICODE) ?: 'null'?>;
        window.awzAllxlsProfilesEditProcess = new Awz.Allxls.ProfilesEditProcess({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            processAction: 'awz:allxls.api.pimport.import',
            barId: 'awz_allxls_profile_process_bar',
            statusId: 'awz_allxls_profile_process_status',
            itemId: 'awz_allxls_profile_process_item',
            statsId: 'awz_allxls_profile_process_stats',
            columnsId: 'awz_allxls_profile_process_columns',
            errorsId: 'awz_allxls_profile_process_errors',
            startBtnId: 'awz_allxls_profile_process_start',
            continueBtnId: 'awz_allxls_profile_process_continue',
            finishBtnId: 'awz_allxls_profile_process_finish',
            nextBtnId: 'awz_allxls_profile_process_next',
            debugId: 'awz_allxls_profile_process_debug',
            saveParamsAction: 'awz:allxls.api.pimport.saveparams',
            finishAction: 'awz:allxls.api.pimport.finish',
            interrupted: processState
        });
        window.awzAllxlsProfilesEditProcess.init();
    });

    // Расписание запуска (cron) обрабатывается встроенным скриптом step4.php
</script>
