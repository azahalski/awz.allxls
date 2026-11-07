<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 2 (выбор файла).
 * Доступные переменные:
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 * @var array  $settings  декодированные настройки профиля
 */

use Bitrix\Main\Localization\Loc;
?>
<script>
    BX.message({
        AWZ_ALLXLS_PROFILE_FILE_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_EMPTY'))?>',
        AWZ_ALLXLS_PROFILE_FILE_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_SAVED'))?>',
        AWZ_ALLXLS_PROFILE_DEST_IBLOCK_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_IBLOCK_EMPTY_MSG'))?>',
        AWZ_ALLXLS_PROFILE_ORM_FILE_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_ORM_FILE_EMPTY'))?>',
        AWZ_ALLXLS_PROFILE_XML_PATHS_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_XML_PATHS_EMPTY') ?: 'Укажите пути до элементов (например: items->item)')?>'
    });
    BX.ready(function () {
        window.awzAllxlsProfilesEditFile = new Awz.Allxls.ProfilesEditFile({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            action: 'awz:allxls.api.profiles.savefile',
            formId: 'awz_allxls_profile_file_form',
            saveBtnId: 'awz_allxls_profile_file_save',
            savedFilePath: '<?=\CUtil::JSEscape($settings['FILE_PATH'] ?? '')?>',
            savedExt: '<?=\CUtil::JSEscape(strtolower(trim((string)($settings['FILE_EXT'] ?? ''))))?>',
            savedXmlPaths: '<?=\CUtil::JSEscape($settings['XML_PATHS'] ?? '')?>'
        });
        window.awzAllxlsProfilesEditFile.init();
    });
</script>
