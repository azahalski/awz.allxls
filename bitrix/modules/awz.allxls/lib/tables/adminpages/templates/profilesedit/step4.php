<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Шаг 4 — запуск обработки профиля с прогресс-баром.
 * Доступные переменные (передаются из ProfilesEdit::defaultInterface()):
 *
 * @var array  $profile    данные профиля
 * @var array  $settings  декодированные настройки профиля
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;
?>
<div class="awz-allxls-page">

    <?php $currentStep = 4; ?>
    <?php include __DIR__.'/_steps.php'; ?>

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_TITLE')?></div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SUB')?></div>
        </div>

        <div id="awz_allxls_profile_process_container" style="padding:20px;">
            <?php // две колонки: слева опции (каждая на новой строке), справа статистика ?>
            <div style="display:flex;gap:32px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px;">
                <div class="awz-allxls-options" style="flex:1 1 auto;min-width:300px;max-width:460px;display:flex;flex-direction:column;">
                    <?php // строка опции: текст 60% / контрол 40%, select на всю ширину контрола ?>
                    <style>
                    .awz-opt-row { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #e5e8ec; }
                    .awz-opt-row:last-child { border-bottom:none; }
                    .awz-opt-row__label { flex:0 0 50%; min-width:0; user-select:none; }
                    .awz-opt-row__control { flex:1 1 50%; display:flex; justify-content:flex-end; align-items:center; gap:8px; min-width:0; }
                    .awz-opt-row__control select { width:100%; max-width:none; }
                    .awz-opt-row__control input[type=number] { width:80px; }
                    .awz-help { position:relative; display:inline-flex; align-items:center; justify-content:center;
                        width:15px; height:15px; margin-left:5px; flex:none; border-radius:50%;
                        background:#2067b0; color:#fff; font-size:10px; font-weight:bold; line-height:1;
                        cursor:help; vertical-align:middle; }
                    .awz-help:hover, .awz-help:focus { background:#0d4d94; }
                    .awz-help .awz-help__popup { display:none; position:absolute; left:-10px; bottom:22px;
                        width:340px; max-width:70vw; padding:10px 12px; box-sizing:border-box;
                        background:#fff; color:#333; font-size:12px; font-weight:normal; line-height:1.45;
                        border:1px solid #d5dbe1; border-radius:6px; box-shadow:0 6px 18px rgba(0,0,0,.18);
                        text-align:left; white-space:normal; z-index:1000; }
                    .awz-help .awz-help__popup:before { content:""; position:absolute; left:14px; bottom:-6px; transform:rotate(45deg);
                        width:10px; height:10px; background:#fff; border-right:1px solid #d5dbe1; border-bottom:1px solid #d5dbe1; }
                    .awz-help:hover .awz-help__popup, .awz-help:focus .awz-help__popup { display:block; }
                    /* всплывашка не должна обрезаться карточкой */
                    .awz-allxls-card:has(.awz-help) { overflow:visible; }
                    </style>
                    <?php
                    // иконка «?» с всплывающим описанием параметра (чистый CSS: hover/focus)
                    $helpIcon = function (string $key) {
                        $text = trim((string)Loc::getMessage($key));
                        if ($text === '') return '';
                        return '<span class="awz-help" tabindex="0"><span class="awz-help__popup">'
                            .htmlspecialcharsbx($text).'</span>?</span>';
                    };
                    ?>
                    <?php
                    // экспорт: на финальном этапе остаются только время хита,
                    // пауза между запросами, экспорт по одному элементу и ошибки.
                    // Ключи настроек у экспорта свои (EXPORT_*)
                    $isExport = (($profile['TYPE'] ?? 'import') === 'export');
                    $hitKey = $isExport ? 'EXPORT_HIT_TIME_LIMIT' : 'HIT_TIME_LIMIT';
                    $delayKey = $isExport ? 'EXPORT_REQUEST_DELAY' : 'REQUEST_DELAY';
                    $logKey = $isExport ? 'EXPORT_LOG_ERRORS' : 'LOG_ERRORS';
                    $sbsKey = $isExport ? 'EXPORT_STEP_BY_STEP' : 'STEP_BY_STEP';
                    ?>
                    <style>
                    .awz-switch { position:relative; display:inline-block; width:34px; height:18px; flex:none; }
                    .awz-switch input { position:absolute; opacity:0; width:0; height:0; }
                    .awz-switch span { position:absolute; inset:0; cursor:pointer; background:#b3b3b3; border-radius:9px; transition:background .2s; }
                    .awz-switch span:before { content:""; position:absolute; width:14px; height:14px; left:2px; top:2px; background:#fff; border-radius:50%; transition:transform .2s; }
                    .awz-switch input:checked + span { background:#2f9cf6; }
                    .awz-switch input:checked + span:before { transform:translateX(16px); }
                    .awz-switch input:focus-visible + span { outline:2px solid #2067b0; outline-offset:1px; }
                    .awz-allxls-switch__label { user-select:none; }
                    </style>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HIT_TIME')?><?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_HIT_TIME')?></span>
                        <div class="awz-opt-row__control">
                            <input type="number" id="awz_allxls_profile_process_hit_time"
                                   min="0" value="<?=($settings[$hitKey] ?? 7)?>"> <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SEC')?>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_REQ_DELAY')?><?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_REQ_DELAY')?></span>
                        <div class="awz-opt-row__control">
                            <input type="number" id="awz_allxls_profile_process_request_delay"
                                   min="0" step="1" value="<?=max(0, (int)($settings[$delayKey] ?? 0))?>"> <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SEC')?>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage($isExport
                            ? 'AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_EXP_STEP_BY_STEP'
                            : 'AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STEP_BY_STEP')?>
                            <?=$helpIcon($isExport
                                ? 'AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_EXP_STEP_BY_STEP'
                                : 'AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_STEP_BY_STEP')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_step_by_step"
                                       <?=($settings[$sbsKey] ?? 'N') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <?php if (!$isExport): ?>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_TYPE')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_HASH_TYPE')?></span>
                        <div class="awz-opt-row__control">
                            <?php $hashType = (string)($settings['HASH_TYPE'] ?? 'item'); ?>
                            <select id="awz_allxls_profile_process_hash_type">
                                <option value="row"<?=$hashType === 'row' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_ROW')?></option>
                                <option value="item"<?=$hashType === 'item' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_ITEM')?></option>
                                <option value="no"<?=$hashType === 'no' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_OFF')?></option>
                            </select>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_MEM')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_HASH_MEM')?></span>
                        <div class="awz-opt-row__control">
                            <?php $hashMem = (string)($settings['HASH_MEM_CACHE'] ?? 'N'); ?>
<select id="awz_allxls_profile_process_hash_mem">
                            <option value="Y"<?=$hashMem === 'Y' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_MEM_ON')?></option>
                            <option value="N"<?=$hashMem === 'N' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_MEM_DB')?></option>
                        </select>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_USED')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_HASH_USED')?></span>
                        <div class="awz-opt-row__control">
                            <?php $hashUsed = (string)($settings['HASH_USED_COLUMNS'] ?? 'N'); ?>
                            <select id="awz_allxls_profile_process_hash_used">
                                <option value="N"<?=$hashUsed === 'N' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_USED_ALL')?></option>
                                <option value="Y"<?=$hashUsed === 'Y' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HASH_USED_ON')?></option>
                            </select>
                        </div>
                    </div>
                    <?php // чекбоксы-переключатели: собственный CSS-свитч (инпут сохраняет прежние id — JS читает checked) ?>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SKIP_NOT_FOUND')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SKIP_NOT_FOUND')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_skip_not_found"
                                       <?=($settings['SKIP_IF_NOT_FOUND'] ?? 'N') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_REPLACE_DATE')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_REPLACE_DATE')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_replace_date"
                                       <?=($settings['SYSTEM_REPLACE_DATE'] ?? 'N') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <?php if ((string)($settings['DEST_TYPE'] ?? '') === 'iblock'): ?>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_FACET')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_FACET')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_facet"
                                       <?=($settings['SYSTEM_FACET'] ?? 'Y') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_META')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_META')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_meta"
                                       <?=($settings['SYSTEM_META'] ?? 'Y') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_SEARCH_NEW')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_SEARCH_NEW')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_search_new"
                                       <?=($settings['SYSTEM_SEARCH_NEW'] ?? 'Y') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_SEARCH_UPDATE')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_SEARCH_UPDATE')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_search_update"
                                       <?=($settings['SYSTEM_SEARCH_UPDATE'] ?? 'Y') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_RESIZE')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_RESIZE')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_sys_resize"
                                       <?=($settings['SYSTEM_RESIZE_PICTURES'] ?? 'N') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                        </div>
                    </div>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_UPDATE_ORDER')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_SYS_UPDATE_ORDER')?></span>
                        <div class="awz-opt-row__control">
                            <?php $updateOrder = (string)($settings['SYSTEM_UPDATE_ORDER'] ?? 'fields_first'); ?>
                            <select id="awz_allxls_profile_process_sys_update_order">
                                <option value="fields_first"<?=$updateOrder === 'fields_first' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_UO_FIELDS')?></option>
                                <option value="props_first"<?=$updateOrder === 'props_first' ? ' selected' : ''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SYS_UO_PROPS')?></option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; // !isExport ?>
                    <div class="awz-opt-row">
                        <span class="awz-opt-row__label awz-allxls-switch__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_LOG_ERRORS')?>
                            <?=$helpIcon('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_HELP_LOG_ERRORS')?></span>
                        <div class="awz-opt-row__control">
                            <label class="awz-switch">
                                <input type="checkbox" id="awz_allxls_profile_process_log_errors"
                                       <?=($settings[$logKey] ?? 'N') === 'Y' ? 'checked' : ''?>>
                                <span></span>
                            </label>
                            <a href="/bitrix/admin/event_log.php?PAGEN_1=1&SIZEN_1=20&lang=<?=LANGUAGE_ID?>&set_filter=Y&adm_filter_applied=0&find=<?=urlencode(($isExport ? 'AWZ_ALLXLS_PEXPORT_' : 'AWZ_ALLXLS_PIMPORT_').$profileId)?>&find_type=audit_type_id&by=ID&order=desc"
                               target="_blank" style="white-space:nowrap;">
                                <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_LOG_LINK')?>
                            </a>
                        </div>
                    </div>
                    <div style="margin-top:14px;">
                        <button type="button" class="awz-allxls-btn awz-allxls-btn--ghost" id="awz_allxls_profile_process_save">
                            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVE')?>
                        </button>
                    </div>
                </div>
                <div class="awz-allxls-stats" style="flex:1 1 480px;min-width:340px;">
                    <div id="awz_allxls_profile_process_stats"></div>
                    <div id="awz_allxls_profile_process_columns" style="margin-top:12px;"></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:16px;">
                <div id="awz_allxls_profile_process_bar" style="flex:1;min-width:0;"></div>
                <a href="#" id="awz_allxls_profile_process_stop" style="display:none;white-space:nowrap;">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STOP')?>
                </a>
            </div>
            <div id="awz_allxls_profile_process_time" style="margin-top:8px;font-weight:bold;"></div>
            <div id="awz_allxls_profile_process_status" class="awz-allxls-row__desc" style="margin-top:12px;"></div>
            <div id="awz_allxls_profile_process_item" style="margin-top:12px;"></div>
            <div id="awz_allxls_profile_process_debug" style="margin-top:12px;"></div>
            <div id="awz_allxls_profile_process_errors" style="margin-top:12px;"></div>
        </div>
    </div>

    <?php
    // Опции после завершения импорта: список и разметка определяются
    // сервисом-приемником (getFinishOptionsHtml), применяются один раз
    // по окончании импорта (done/finishAction). Для экспорта — нет.
    $finishOptionsHtml = '';
    if (($profile['TYPE'] ?? 'import') !== 'export') {
        try {
            $destType = (string)($settings['DEST_TYPE'] ?? '');
            if ($destType !== '') {
                $finishService = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('awz.allxls.'.$destType);
                if (method_exists($finishService, 'getFinishOptionsHtml')) {
                    $finishOptionsHtml = (string)$finishService->getFinishOptionsHtml($settings);
                }
            }
        } catch (\Throwable $e) {
            $finishOptionsHtml = '';
        }
    }
    if ($finishOptionsHtml !== ''):
    ?>
    <div class="awz-allxls-card" id="awz_allxls_finish_card">
        <div class="awz-allxls-card__head" id="awz_allxls_finish_card_head" style="cursor:pointer;user-select:none;"
             title="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_FINISH_TITLE'))?>">
            <div class="awz-allxls-card__title">
                <span id="awz_allxls_finish_card_icon" style="display:inline-block;margin-right:6px;font-size:11px;transition:transform .2s;">▶</span>
                <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_FINISH_TITLE')?>
            </div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_FINISH_SUB')?></div>
        </div>
        <div id="awz_allxls_finish_card_body" style="display:none;padding:20px;">
            <?=$finishOptionsHtml?>
        </div>
    </div>
    <script>
    (function(){
        var head = document.getElementById('awz_allxls_finish_card_head');
        var body = document.getElementById('awz_allxls_finish_card_body');
        var icon = document.getElementById('awz_allxls_finish_card_icon');
        if (!head || !body) return;
        head.addEventListener('click', function(){
            var open = body.style.display !== 'none';
            body.style.display = open ? 'none' : '';
            if (icon) icon.textContent = open ? '▶' : '▼';
        });
    })();
    </script>
    <?php endif; ?>

    <?php
    // Расписание запуска (cron). Данные хранятся в профиле (в БД).
    // Простой режим: готовые интервалы + время шага; экспертный режим —
    // crontab-выражение вручную.
    $cronSchedule = (string)($profile['CRON_SCHEDULE'] ?? '');
    $cronActive = ($profile['CRON_ACTIVE'] ?? 'N') === 'Y';
    $cronStepTime = (int)($profile['CRON_HIT_TIME'] ?? 0);
    $cronCommand = 'php '.rtrim(\Bitrix\Main\Application::getDocumentRoot(), '/\\').'/bitrix/modules/awz.allxls/cron/run.php '.($profileId > 0 ? $profileId : 'all');
    $cronCommandAll = 'php '.rtrim(\Bitrix\Main\Application::getDocumentRoot(), '/\\').'/bitrix/modules/awz.allxls/cron/run.php all';
    ?>
    <div class="awz-allxls-card" id="awz_allxls_cron_card">
        <div class="awz-allxls-card__head" id="awz_allxls_cron_card_head" style="cursor:pointer;user-select:none;" title="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_TITLE'))?>">
            <div class="awz-allxls-card__title">
                <span id="awz_allxls_cron_card_icon" style="display:inline-block;margin-right:6px;font-size:11px;transition:transform .2s;"><?= $cronActive ? '▼' : '▶' ?></span>
                <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_TITLE')?>
            </div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_SUB')?></div>
        </div>
        <div id="awz_allxls_cron_card_body" style="<?=$cronActive ? '' : 'display:none;'?>padding:20px;">
            <div class="awz-opt-row">
                <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_ACTIVE')?></span>
                <div class="awz-opt-row__control">
                    <label class="awz-switch">
                        <input type="checkbox" id="awz_allxls_cron_active" <?=$cronActive ? 'checked' : ''?>>
                        <span></span>
                    </label>
                </div>
            </div>
            <div class="awz-opt-row">
                <span class="awz-opt-row__label">Способ запуска</span>
                <div class="awz-opt-row__control">
                    <?php $cronMode = (string)($settings['CRON_MODE'] ?? 'agent'); ?>
                    <label style="white-space:nowrap;">
                        <input type="radio" name="awz_allxls_cron_mode" id="awz_allxls_cron_mode_cron" value="cron"
                               <?=($cronMode === 'cron') ? 'checked' : ''?>> общее cron задание
                    </label>
                    <label style="white-space:nowrap;">
                        <input type="radio" name="awz_allxls_cron_mode" id="awz_allxls_cron_mode_cron_own" value="cron_own"
                               <?=($cronMode === 'cron_own') ? 'checked' : ''?>> отдельное cron задание
                    </label>
                    <label style="white-space:nowrap;">
                        <input type="radio" name="awz_allxls_cron_mode" id="awz_allxls_cron_mode_agent" value="agent"
                               <?=($cronMode !== 'cron' && $cronMode !== 'cron_own') ? 'checked' : ''?>> агент Bitrix
                    </label>
                </div>
            </div>
            <div class="awz-allxls-row__desc" style="margin-top:2px;max-width:100%;word-break:break-word;">
                Способ запуска задается для каждого профиля отдельно. <b>Общее cron задание</b> — один вызов <code>run.php all</code> обрабатывает все профили с этой опцией (по умолчанию cron выключен). <b>Отдельное cron задание</b> — профиль запускается только собственной строкой crontab с передачей ID (<code>run.php <?= (int)$profileId ?></code>); скрипт проверяет эту опцию в настройках профиля. <b>Агент Bitrix</b> зависит от хитов посетителей сайта, может отставать или дублироваться — рекомендуется cron.
            </div>
            <div class="awz-opt-row" style="margin-top:10px;">
                <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_INTERVAL')?></span>
                <div class="awz-opt-row__control">
                    <?php
                    // из готового расписания вычисляем начальное значение паузы в минутах
                    $intervalMinutes = '';
                    if (preg_match('~^\*/(\d+)\s+\*\s+\*\s+\*\s+\*$~', $cronSchedule, $m)) {
                        $intervalMinutes = (int)$m[1];
                    } elseif (preg_match('~^0\s+\*/(\d+)\s+\*\s+\*\s+\*$~', $cronSchedule, $m)) {
                        $intervalMinutes = (int)$m[1] * 60;
                    }
                    ?>
                    <input type="number" min="1" step="1" style="width:120px;" id="awz_allxls_cron_interval_min"
                           value="<?=$intervalMinutes !== '' ? (int)$intervalMinutes : 5?>"
                           placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_INTERVAL_MINUTES'))?>">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_INTERVAL_MINUTES_HINT')?>
                </div>
            </div>
            <div class="awz-opt-row">
                <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_STEP_TIME')?></span>
                <div class="awz-opt-row__control">
                    <input type="number" min="0" max="3600" step="1" style="width:120px;" id="awz_allxls_cron_step"
                           value="<?=$cronStepTime > 0 ? (int)$cronStepTime : 0?>"> <span>сек</span>
                    <span style="opacity:.6;">(0 — <?=htmlspecialcharsbx('обработка до завершения')?>)</span>
                </div>
            </div>
            <div class="awz-opt-row">
                <span class="awz-opt-row__label">Время запуска</span>
                <div class="awz-opt-row__control">
                    <?php
                    $settings = is_array($profile['SETTINGS'] ?? null) ? $profile['SETTINGS'] : [];
                    $cronTimeFrom = (string)($settings['CRON_TIME_FROM'] ?? '');
                    $cronTimeTo = (string)($settings['CRON_TIME_TO'] ?? '');
                    ?>
                    <span>от</span>
                    <input type="time" style="width:110px;" id="awz_allxls_cron_time_from" value="<?=htmlspecialcharsbx($cronTimeFrom)?>">
                    <span>до</span>
                    <input type="time" style="width:110px;" id="awz_allxls_cron_time_to" value="<?=htmlspecialcharsbx($cronTimeTo)?>">
                    <span style="opacity:.6;">(пусто — без ограничения)</span>
                </div>
            </div>
            <div class="awz-opt-row">
                <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_PAUSE')?></span>
                <div class="awz-opt-row__control">
                    <?php $cronPause = (int)($profile['CRON_PAUSE'] ?? 0); ?>
                    <input type="number" id="awz_allxls_cron_pause" min="0" step="1" style="width:120px;"
                           value="<?=$cronPause > 0 ? (int)($cronPause / 60) : 0?>" autocomplete="off"
                           title="минимум 5 мин">
                    <span style="margin-left:6px;">мин</span>
                    <span style="opacity:.6;">(минимум 5 мин)</span>
                </div>
            </div>
            <?php
            // ручное время следующего запуска: разовый запуск в назначенное время
            // без изменения расписания и паузы после завершения
            $nextRun = $profile['DATE_NEXT_RUN'] ?? null;
            $nextRunLocal = ($nextRun instanceof \Bitrix\Main\Type\DateTime)
                ? $nextRun->format('Y-m-d\TH:i') : '';
            ?>
            <div class="awz-opt-row">
                <span class="awz-opt-row__label">Время следующего запуска</span>
                <div class="awz-opt-row__control">
                    <input type="datetime-local" id="awz_allxls_cron_next_run" style="width:190px;"
                           value="<?=htmlspecialcharsbx($nextRunLocal)?>" autocomplete="off">
                    <span style="opacity:.6;">(пусто — по расписанию)</span>
                </div>
            </div>
            <div id="awz_allxls_cron_panel" style="display:none;margin-top:12px;">
                <div class="awz-opt-row" style="display:none;">
                    <span class="awz-opt-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_SCHEDULE')?></span>
                    <div class="awz-opt-row__control">
                        <input type="text" id="awz_allxls_cron_schedule" style="width:160px;" value="<?=htmlspecialcharsbx($cronSchedule)?>"
                               placeholder="0 3 * * *" autocomplete="off">
                    </div>
                </div>
                <div class="awz-allxls-row__desc" style="margin-top:8px;">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_SCHEDULE_HINT')?>
                </div>
                <div class="awz-allxls-row__desc" style="margin-top:4px;">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_STEP_HINT')?>
                </div>
                <div class="awz-allxls-row__desc" style="margin-top:4px;">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_PAUSE_HINT')?>
                </div>
                <div style="margin-top:12px;">
                    <div style="margin-bottom:4px;font-weight:bold;">Общее cron задание (рекомендуется, раз в 3 минуты, все профили с опцией «общее cron задание»):</div>
                    <code style="display:block;white-space:pre-wrap;word-break:break-all;background:#f2f4f7;padding:8px;border-radius:4px;">*/3 * * * * <?=htmlspecialcharsEx($cronCommandAll)?></code>
                    <div style="margin-top:8px;margin-bottom:4px;font-weight:bold;">Отдельное cron задание для этого профиля (только при опции «отдельное cron задание»):</div>
                    <code style="display:block;white-space:pre-wrap;word-break:break-all;background:#f2f4f7;padding:8px;border-radius:4px;">*/3 * * * * <?=htmlspecialcharsEx($cronCommand)?></code>
                    <div class="awz-allxls-row__desc" style="margin-top:4px;">
                        Пошаговый режим и блокировки защищают от двойного запуска — частый вызов безопасен. Общее задание (<code>all</code>) не обрабатывает профили с опцией «отдельное cron задание» и наоборот.
                    </div>
                </div>
            </div>
            <div style="margin-top:12px;display:flex;gap:12px;align-items:center;">
                <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_cron_save">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_SAVE')?>
                </button>
                <button type="button" class="awz-allxls-btn awz-allxls-btn--ghost" id="awz_allxls_cron_release" style="display:none;">
                    <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_RELEASE')?>
                </button>
            </div>
            <div id="awz_allxls_cron_status" style="margin-top:12px;"></div>
        </div>
    </div>
    <script>
    (function () {
        'use strict';
        function el(id) { return document.getElementById(id); }
        var profileId = <?= (int)$profileId ?>;
        var statusBox = null;
        // интервал фонового опроса cronstatus (активен только при running)
        var pollIntervalId = null;

        function escHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function renderStatus(data, errors) {
            if (!statusBox) return;
            if (errors && errors.length) {
                statusBox.innerHTML = '<span style="color:#b00;">' +
                    errors.map(function (e) { return escHtml(e.message || e.code || e); }).join('<br>') +
                    '</span>';
                return;
            }
            var parts = [];
            parts.push(data.active === 'Y'
                ? '<span style="color:#2a7d2a;font-weight:bold;">Расписание включено</span>'
                : 'Расписание выключено');
            if (data.schedule) parts.push('Расписание: <code>' + escHtml(data.schedule) + '</code>');
            if (data.step_time > 0) parts.push('Шаг: ' + escHtml(data.step_time) + ' сек');
            if (data.pause > 0) parts.push('Пауза после завершения: ' + escHtml(Math.round(data.pause / 60)) + ' мин');
            // способ запуска (per-profile) + статус агента (только для режима «агент»)
            if (data.mode === 'cron') {
                parts.push('Запуск через общее cron задание (run.php all)');
            } else if (data.mode === 'cron_own') {
                parts.push('Запуск через отдельное cron задание (run.php #ID#)');
            } else {
                var ag = data.agent || {};
                if (!ag.exists) {
                    parts.push('<span style="color:#b00;">Агент не зарегистрирован — переустановите модуль или создайте его в /bitrix/admin/agent_list.php</span>');
                } else if (!ag.active) {
                    parts.push('<span style="color:#b00;">Агент деактивирован — импорты не будут запускаться</span>');
                } else if (ag.overdue) {
                    parts.push('<span style="color:#b00;">Агент не выполняется дольше 10 минут — проверьте хиты сайта / crontab на bitrix/modules/main/tools.php?agent</span>');
                } else {
                    parts.push('Агент: активен' + (ag.next_exec ? ', следующий запуск: ' + escHtml(ag.next_exec) : ''));
                }
                if (ag.duplicates) {
                    parts.push('<span style="color:#b00;">Найдено несколько копий агента — удалите дубликаты в /bitrix/admin/agent_list.php (дубли вызывают параллельные запуски и падения импортов)</span>');
                }
            }
            if (data.next_run && data.active === 'Y') {
                parts.push('Следующий запуск: ' + escHtml(String(data.next_run).replace('T', ' ')));
            }
            if (data.last_end) parts.push('Последнее завершение: ' + escHtml(String(data.last_end).replace('T', ' ')));
            if (data.last_err) parts.push('<span style="color:#b00;">Последняя ошибка: ' + escHtml(data.last_err) + '</span>');
            statusBox.innerHTML = parts.join(' &nbsp;·&nbsp; ');
            var running = data.running === true || data.running === 'Y';
            var release = el('awz_allxls_cron_release');
            if (release) release.style.display = running ? '' : 'none';
            renderCronLive(data, running);
        }

        // «живой» прогресс cron-запуска: статистика периодически дампится
        // cron-процессом в профиль (dumpLiveStats), здесь отображается
        function renderCronLive(data, running) {
            var statusEl = el('awz_allxls_profile_process_status');
            var statsEl = el('awz_allxls_profile_process_stats');
            var barEl = el('awz_allxls_profile_process_bar');
            var timeEl = el('awz_allxls_profile_process_time');
            var banner = '<span style="color:#2067b0;font-weight:bold;">' +
                '<?= \CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CRON_LIVE')) ?></span>';
            if (statusEl && running) statusEl.innerHTML = banner;
            if (!running) return;
            var sv = data.stats_view;
            if (timeEl) timeEl.textContent = sv && sv['Общее время работы']
                ? '<?= \CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_TIME')) ?>: ' + sv['Общее время работы']
                : '';
            if (barEl && sv && typeof sv['Прогресс, %'] !== 'undefined') {
                var pct = Math.max(0, Math.min(100, parseFloat(sv['Прогресс, %']) || 0));
                barEl.innerHTML = '<div style="background:#e5e8ec;border-radius:4px;height:16px;overflow:hidden;">' +
                    '<div style="background:#2f9cf6;height:100%;width:' + pct + '%;transition:width .4s;"></div></div>' +
                    '<div style="margin-top:4px;font-size:12px;color:#555;">' + pct + '%</div>';
            }
            if (statsEl && sv) {
                var rows = [];
                for (var k in sv) {
                    if (!Object.prototype.hasOwnProperty.call(sv, k) || k === 'Прогресс, %' || k === 'Общее время работы') continue;
                    rows.push('<tr><td style="padding:2px 12px 2px 0;color:#666;">' + escHtml(k) + '</td>' +
                        '<td style="padding:2px 0;font-weight:bold;">' + escHtml(String(sv[k])) + '</td></tr>');
                }
                statsEl.innerHTML = rows.length
                    ? '<div style="font-weight:bold;margin-bottom:6px;"><?= \CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_STATS')) ?></div>' +
                      '<table style="font-size:13px;border-collapse:collapse;">' + rows.join('') + '</table>'
                    : '';
            }
        }

        function loadStatus() {
            BX.ajax.runAction('awz:allxls.api.profiles.cronstatus', {
                data: { id: profileId }
            }).then(function (res) {
                applyStatus(res.data || {});
            }, function (res) {
                applyStatus({}, res.errors);
            });
        }

        // применение статуса + управление фоновым опросом:
        // интервал cronstatus активен только когда профиль
        // реально запущен на crontab или агенте (running)
        function applyStatus(data, errors) {
            renderStatus(data, errors);
            var running = !!(data && (data.running === true || data.running === 'Y'));
            if (running && pollIntervalId === null) {
                // периодический опрос статуса: живой прогресс cron-запуска.
                // Интерфейс перерисовывается только когда запуск реально выполняется,
                // чтобы фоновые опросы не затирали статус/ошибки сохранения
                pollIntervalId = setInterval(function () {
                    BX.ajax.runAction('awz:allxls.api.profiles.cronstatus', {
                        data: { id: profileId }
                    }).then(function (res) {
                        var d = res.data || {};
                        if (d.running === true || d.running === 'Y') {
                            renderStatus(d);
                        } else {
                            // профиль перестал выполняться — опрос не нужен
                            stopPolling();
                            renderStatus(d);
                        }
                    }, function () {});
                }, 15000);
            } else if (!running && pollIntervalId !== null) {
                stopPolling();
            }
        }

        function stopPolling() {
            if (pollIntervalId !== null) {
                clearInterval(pollIntervalId);
                pollIntervalId = null;
            }
        }

        function collectSchedule() {
            var input = el('awz_allxls_cron_schedule');
            // nbsp → обычный пробел (браузер/автозаполнение могли вставить)
            var v = input ? String(input.value || '').replace(/\u00A0/g, ' ').trim() : '';
            if (v !== '') return v;
            // поле crontab скрыто и заполняется по событию change (на blur);
            // если пользователь изменил интервал и сразу нажал «Сохранить»,
            // change ещё не сработал — вычисляем расписание из интервала
            var intervalMin = el('awz_allxls_cron_interval_min');
            var n = intervalMin ? (parseInt(intervalMin.value, 10) || 0) : 0;
            if (n >= 1) {
                return (n < 60) ? ('*/' + n + ' * * * *') : ('0 */' + Math.floor(n / 60) + ' * * *');
            }
            return '';
        }

        function collectMode() {
            var cronMode = el('awz_allxls_cron_mode_cron');
            var cronOwnMode = el('awz_allxls_cron_mode_cron_own');
            var agentMode = el('awz_allxls_cron_mode_agent');
            if (agentMode && agentMode.checked) return 'agent';
            if (cronOwnMode && cronOwnMode.checked) return 'cron_own';
            if (cronMode && cronMode.checked) return 'cron';
            return 'agent';
        }

        function saveCron() {
            var active = el('awz_allxls_cron_active');
            var step = el('awz_allxls_cron_step');
            var pause = el('awz_allxls_cron_pause');
            var timeFrom = el('awz_allxls_cron_time_from');
            var timeTo = el('awz_allxls_cron_time_to');
            var nextRun = el('awz_allxls_cron_next_run');
            statusBox = el('awz_allxls_cron_status');
            statusBox.innerHTML = 'Сохранение...';
            BX.ajax.runAction('awz:allxls.api.profiles.cronsave', {
                data: {
                    id: profileId,
                    active: (active && active.checked) ? 'Y' : 'N',
                    schedule: collectSchedule(),
                    stepTime: step ? (parseInt(step.value, 10) || 0) : 0,
                    pause: pause ? Math.max(0, (parseInt(pause.value, 10) || 0) > 0 ? Math.max(5, parseInt(pause.value, 10)) : 0) : 0,
                    timeFrom: timeFrom ? (timeFrom.value || '') : '',
                    timeTo: timeTo ? (timeTo.value || '') : '',
                    mode: collectMode(),
                    // ручное время следующего запуска: пусто — по расписанию
                    nextRun: nextRun ? (nextRun.value || '') : ''
                }
            }).then(function (res) {
                renderStatus(res.data || {});
            }, function (res) {
                renderStatus({}, res.errors);
            });
        }

        BX.ready(function () {
            var active = el('awz_allxls_cron_active');
            var panel = el('awz_allxls_cron_panel');
           
            var intervalMin = el('awz_allxls_cron_interval_min');
            var schedule = el('awz_allxls_cron_schedule');
            var saveBtn = el('awz_allxls_cron_save');
            var releaseBtn = el('awz_allxls_cron_release');
            if (!active || !saveBtn) return;

            function applyActive() {
                if (panel) panel.style.display = active.checked ? '' : 'none';
                // синхронизация иконки раскрытия с текущим состоянием карточки
                applyBody();
            }

            // сворачивание/разворачивание карточки «Расписание запуска (cron)»:
            // по умолчанию скрыта, раскрыта если запуск уже активен
            var cardHead = el('awz_allxls_cron_card_head');
            var cardBody = el('awz_allxls_cron_card_body');
            var cardIcon = el('awz_allxls_cron_card_icon');
            function applyBody() {
                if (!cardBody || !cardIcon) return;
                var open = cardBody.style.display !== 'none';
                cardIcon.textContent = open ? '▼' : '▶';
            }
            if (cardHead && cardBody) {
                BX.bind(cardHead, 'click', function (e) {
                    // клики по контролам внутри шапки не переключают раскрытие
                    if (e.target && e.target.tagName && /INPUT|SELECT|LABEL|A/.test(e.target.tagName)) return;
                    cardBody.style.display = (cardBody.style.display === 'none') ? '' : 'none';
                    applyBody();
                });
                applyBody();
            }

            
            if (intervalMin) {
                BX.bind(intervalMin, 'change', function () {
                    // пауза в минутах пересчитывается в cron-расписание
                    var n = parseInt(intervalMin.value, 10) || 0;
                    if (n < 1 || !schedule) return;
                    if (n < 60) {
                        schedule.value = '*/' + n + ' * * * *';
                    } else {
                        schedule.value = '0 */' + Math.floor(n / 60) + ' * * *';
                    }
                });
            }
            BX.bind(active, 'change', applyActive);
            BX.bind(saveBtn, 'click', function (e) { e.preventDefault(); saveCron(); return false; });
            if (releaseBtn) {
                BX.bind(releaseBtn, 'click', function (e) {
                    e.preventDefault();
                    BX.ajax.runAction('awz:allxls.api.profiles.cronsave', {
                        data: { id: profileId, release: 'Y' }
                    }).then(function (res) {
                        renderStatus(res.data || {});
                    }, function (res) {
                        renderStatus({}, res.errors);
                    });
                    return false;
                });
            }

            // расписание формируется из интервала
            applyActive();
            loadStatus();
        });
    })();
    </script>

    <div class="awz-allxls-footer">
        <a href="/bitrix/admin/awz_allxls_profiles_edit.php?id=<?=$profileId?>&step=3&lang=<?=LANGUAGE_ID?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            &larr; <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_BACK')?>
        </a>
        <?php
        // прерванный прогон (импорт или экспорт): показываем кнопку продолжения
        $isExportProfile = (($profile['TYPE'] ?? 'import') === 'export');
        $process = is_array($settings[($isExportProfile ? 'PROCESS_EXPORT' : 'PROCESS')] ?? null)
            ? $settings[($isExportProfile ? 'PROCESS_EXPORT' : 'PROCESS')]
            : [];
        $interrupted = ($process['DONE'] ?? 'Y') !== 'Y' && (int)($process['PROCESSED'] ?? 0) > 0;
        ?>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_process_start"
                style="<?=$interrupted ? 'display:none;' : ''?>">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_START')?>
        </button>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_process_continue"
                style="<?=$interrupted ? '' : 'display:none;'?>">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_CONTINUE')?>
        </button>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_process_next"
                style="display:none;">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_NEXT')?>
        </button>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--ghost" id="awz_allxls_profile_process_finish"
                style="<?=$interrupted ? '' : 'display:none;'?>">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_FINISH')?>
        </button>
        <a href="<?=$listUrl?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_CANCEL')?>
        </a>
    </div>
    <?//echo'<pre>';print_r($profile['SETTINGS']);echo'</pre>';?>
</div>
