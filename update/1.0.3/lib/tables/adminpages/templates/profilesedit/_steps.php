<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Общий блок этапов в шапке мастера профиля.
 * Все 4 этапа выводятся всегда, каждый — ссылка (переход без сохранения).
 *
 * Доступные переменные (передаются из step*.php):
 *
 * @var array  $profile     данные профиля
 * @var int    $profileId   ID профиля (0 — создание)
 * @var int    $currentStep номер текущего шага (1-4)
 */

use Bitrix\Main\Localization\Loc;

// шаг 2/3/4 доступны только для существующего профиля
$stepUrl = function (int $step) use ($profile, $profileId): string {
    $type = (($profile['TYPE'] ?? 'import') === 'export') ? 'export' : 'import';
    return '/bitrix/admin/awz_allxls_profiles_edit.php?id=' . $profileId
        . '&step=' . $step . '&lang=' . LANGUAGE_ID . '&' . $type . '=Y';
};

$stepNames = [
    1 => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP1_NAME'),
    2 => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP2_NAME'),
    3 => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP3_NAME'),
    4 => Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_NAME'),
];

$hasProfile = $profileId > 0;
?>
<div class="awz-allxls-steps">
    <?php foreach ($stepNames as $num => $name): ?>
        <?php if ($num > 1): ?><div class="awz-allxls-step__line"></div><?php endif; ?>
        <?php
        // текущий этап подсвечивается, но остаётся ссылкой;
        // этапы 2-4 без ID профиля выводятся как некликабельные
        $isCurrent = ($num === $currentStep);
        $classes = ['awz-allxls-step'];
        if ($isCurrent) $classes[] = 'awz-allxls-step--active';
        if ($num < $currentStep) $classes[] = 'awz-allxls-step--done';
        if (!$hasProfile) $classes[] = 'awz-allxls-step--disabled';
        ?>
        <?php if ($hasProfile): ?>
            <a href="<?=$stepUrl($num)?>" class="<?=implode(' ', $classes)?> awz-allxls-step--link">
                <span class="awz-allxls-step__circle"><?=$num?></span>
                <span class="awz-allxls-step__label"><?=htmlspecialcharsbx($name)?></span>
            </a>
        <?php else: ?>
            <div class="<?=implode(' ', $classes)?>">
                <span class="awz-allxls-step__circle"><?=$num?></span>
                <span class="awz-allxls-step__label"><?=htmlspecialcharsbx($name)?></span>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
