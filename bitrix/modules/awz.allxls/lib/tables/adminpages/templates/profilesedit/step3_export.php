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
?>
<div class="awz-allxls-page">

    <?php $currentStep = 3; ?>
    <?php include __DIR__.'/_steps.php'; ?>

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_TITLE')?></div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_EXPORT_SUB')?></div>
        </div>

        <div id="awz_allxls_profile_export_loader" class="awz-allxls-row__desc">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_LOADING')?>
        </div>

        <div id="awz_allxls_profile_export_container" style="padding:0 20px;"></div>
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
