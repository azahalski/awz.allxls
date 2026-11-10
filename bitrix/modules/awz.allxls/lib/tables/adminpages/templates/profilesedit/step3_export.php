<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Шаг 3 (экспорт) — настройка колонок выгрузки по листам.
 *
 * @var array  $profile    данные профиля
 * @var array  $settings  декодированные настройки профиля
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;

// конструктор правил BX.TreeConditions (как в импорте — ext модуля awz.admin)
if (\Bitrix\Main\Loader::includeModule('awz.admin')) {
    $arTreeDescr = [
        'js' => '/bitrix/js/awz.admin/core_tree.js',
        'css' => '/bitrix/panel/awz.admin/catalog_cond.css',
        'lang' => '/bitrix/modules/awz.admin/lang/ru/conditions.php',
        'rel' => ['core']
    ];
    \CJSCore::RegisterExt('awz_core_condtree', $arTreeDescr);
    \CJSCore::Init(['awz_core_condtree']);
}
?>
<div class="awz-allxls-page">

    <?php $currentStep = 3; ?>
    <?php include __DIR__.'/_steps.php'; ?>

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_TITLE')?></div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_SUB')?></div>
        </div>

        <div id="awz_allxls_profile_export_loader" class="awz-allxls-row__desc" style="padding:20px;">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_LOADING')?>
        </div>

        <div id="awz_allxls_profile_export_container" style="padding:0 20px;"></div>

        <?php
        // JSON-шаблон корня выгрузки — только для формата .json
        $exportFileExt = strtolower(trim((string)($settings['EXPORT_FILE_EXT'] ?? '')));
        if ($exportFileExt === '') {
            $exportFileExt = strtolower(pathinfo((string)($settings['FILE_PATH'] ?? ''), PATHINFO_EXTENSION));
        }
        $isJsonExport = ($exportFileExt === 'json');
        ?>
        <div class="awz-allxls-row" id="awz_allxls_profile_export_json_template_row"
             style="<?=$isJsonExport ? '' : 'display:none;';?>padding:0 20px 16px;">
            <div class="awz-allxls-row__label">JSON-шаблон выгрузки</div>
            <div class="awz-allxls-row__ctrl">
                <textarea class="awz-allxls-textarea" id="AWZ_ALLXLS_PROFILE_EXPORT_JSON_TEMPLATE"
                          style="min-height:90px;font-family:Consolas,monospace;"
                          placeholder='{"products":{"sheet1":#SHEET1#,"sheet2":#SHEET2#}}'
                ><?=htmlspecialcharsbx((string)($settings['EXPORT_JSON_TEMPLATE'] ?? ''))?></textarea>
                <div class="awz-allxls-row__desc">
                    Корневая структура итогового JSON. Макросы <b>#&lt;ИМЯ ЛИСТА&gt;#</b> (например #SHEET1#)
                    заменяются на JSON-массив строк соответствующего листа. Пусто — массив объектов первого листа.
                </div>
            </div>
        </div>
    </div>

    <div class="awz-allxls-footer">
        <a href="/bitrix/admin/awz_allxls_profiles_edit.php?id=<?=$profileId?>&step=2&lang=<?=LANGUAGE_ID?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            &larr; <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_BACK')?>
        </a>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--ghost" id="awz_allxls_profile_export_save">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_SAVE')?>
        </button>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_export_save_next">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_NEXT')?>
        </button>
        <a href="<?=$listUrl?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_CANCEL')?>
        </a>
    </div>
</div>
