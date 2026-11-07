<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация редактора профиля.
 * Доступные переменные:
 *
 * @var int    $profileId ID профиля (0 — создание)
 * @var string $listUrl   URL списка профилей
 */
use Bitrix\Main\Localization\Loc;
?>
<script>
    BX.message({
        AWZ_ALLXLS_PROFILE_NAME_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_NAME_EMPTY'))?>',
        AWZ_ALLXLS_PROFILE_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SAVED'))?>'
    });
    BX.ready(function () {
        window.awzAllxlsProfilesEdit = new Awz.Allxls.ProfilesEdit({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            action: 'awz:allxls.api.profiles.save'
        });
        window.awzAllxlsProfilesEdit.init();
    });
</script>
