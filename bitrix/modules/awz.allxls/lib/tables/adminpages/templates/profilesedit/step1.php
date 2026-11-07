<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Шаг 1 — карточка с формой профиля и футером.
 * Доступные переменные (передаются из ProfilesEdit::defaultInterface()):
 *
 * @var array  $profile   данные профиля
 * @var array  $sections  список разделов [ID => NAME]
 * @var int    $profileId ID профиля (0 — создание)
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;
?>
<div class="awz-allxls-page">

    <?php $currentStep = 1; ?>
    <?php include __DIR__.'/_steps.php'; ?>

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title">
                <?=$profileId > 0
                    ? Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP1_EDIT')
                    : Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP1_NEW')?>
            </div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP1_SUB')?></div>
        </div>

        <form id="awz_allxls_profile_form" onsubmit="return false;">
            <input type="hidden" name="TYPE" value="<?=htmlspecialcharsbx($profile['TYPE'])?>">
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_NAME')?></div>
                <div class="awz-allxls-row__ctrl">
                    <input class="awz-allxls-input awz-allxls-input--w" type="text" name="NAME"
                           value="<?=htmlspecialcharsbx($profile['NAME'] ?? '')?>"
                           placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_NAME_PH'))?>">
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_NAME_DESC')?></div>
                </div>
            </div>

            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_ACTIVE')?></div>
                <div class="awz-allxls-row__ctrl">
                    <label class="awz-switch">
                        <input type="checkbox" value="Y" name="ACTIVE"
                            <?=(($profile['ACTIVE'] ?? 'Y') === 'Y') ? 'checked' : ''?>>
                        <span class="awz-switch__slider"></span>
                    </label>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_ACTIVE_DESC')?></div>
                </div>
            </div>

            <?if(false){?>
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_TYPE')?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-select" name="TYPE">
                        <option value="import"<?=(($profile['TYPE'] ?? 'import') !== 'export') ? ' selected' : ''?>>
                            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_TYPE_IMPORT')?>
                        </option>
                        <option value="export"<?=(($profile['TYPE'] ?? '') === 'export') ? ' selected' : ''?>>
                            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_TYPE_EXPORT')?>
                        </option>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_TYPE_DESC')?></div>
                </div>
            </div>
            <?}?>

            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SECTION')?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-select" name="SECTION_ID">
                        <option value="0"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SECTION_EMPTY')?></option>
                        <?php foreach ($sections as $sid => $sname): ?>
                            <option value="<?=$sid?>"<?=(int)($profile['SECTION_ID'] ?? 0) === $sid ? ' selected' : ''?>>
                                <?=htmlspecialcharsbx($sname)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SECTION_DESC')?></div>
                </div>
            </div>

            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DESC')?></div>
                <div class="awz-allxls-row__ctrl">
                    <textarea class="awz-allxls-textarea" name="DESC"
                              placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DESC_PH'))?>"><?=htmlspecialcharsbx($profile['DESC'] ?? '')?></textarea>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DESC_DESC')?></div>
                </div>
            </div>
        </form>
    </div>

    <div class="awz-allxls-footer">
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_save">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_NEXT') ?: 'Следующий шаг'?>
        </button>
        <a href="<?=$listUrl?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_CANCEL')?>
        </a>
    </div>
</div>
