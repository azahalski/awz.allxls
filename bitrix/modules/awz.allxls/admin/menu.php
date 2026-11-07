<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
Loc::loadMessages(__FILE__);
$module_id = "awz.allxls";
if(!Loader::includeModule($module_id)) return;

$items = [];
if(AccessController::isViewSettings() || AccessController::isViewRight()){
    $level2 = [];
    if(AccessController::isViewSettings()){
        $level2[] = [
            "text" => Loc::getMessage('AWZ_ADMIN_MENU_NAME_SETT_1'),
            "url" => "settings.php?lang=".LANGUAGE_ID.'&mid='.$module_id.'&mid_menu=1'
        ];
    }
    if(AccessController::isViewRight()){
        $level2[] = [
            "text" => Loc::getMessage('AWZ_ADMIN_MENU_NAME_SETT_2'),
            "url" => "javascript:BX.SidePanel.Instance.open('/bitrix/admin/settings.php?mid=".$module_id."&lang=".LANGUAGE_ID."&mid_menu=1');"
        ];
    }
    $items[] = [
        "text" => Loc::getMessage('AWZ_ADMIN_MENU_NAME_SETT'),
        "items_id" => str_replace('.','_',$module_id).'_sett',
        "items"=>$level2
    ];
}

$level2 = [];
if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)
|| AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_ADD)
){
    $level2[] = [
        "text" => "Разделы профилей",
        "url" => "/bitrix/admin/awz_allxls_sections_list.php?lang=".LANGUAGE_ID,
        "more_url" => ["/bitrix/admin/awz_allxls_sections_edit.php?lang=".LANGUAGE_ID],
    ];
}
if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)){
    $level2[] = [
        "text" => "Профили импорта",
        "url" => "/bitrix/admin/awz_allxls_profiles_list.php?lang=".LANGUAGE_ID.'&import=Y',
        "more_url" => ["/bitrix/admin/awz_allxls_profiles_edit.php?lang=".LANGUAGE_ID.'&import=Y'],
    ];
}
if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)){
    $level2[] = [
        "text" => "Профили экспорта",
        "url" => "/bitrix/admin/awz_allxls_profiles_list.php?lang=".LANGUAGE_ID.'&export=Y',
        "more_url" => ["/bitrix/admin/awz_allxls_profiles_edit.php?lang=".LANGUAGE_ID.'&export=Y'],
    ];
}
if(!empty($level2)){
    $items[] = [
        "text" => "Профили",
        "items_id" => str_replace('.','_',$module_id).'_profiles',
        "items"=>$level2
    ];
}

if(empty($items)) return;
$aMenu[] = array(
    "parent_menu" => "global_menu_content",
    "section" => str_replace('.','_',$module_id),
    "sort" => 100,
    "module_id" => $module_id,
    "text" => Loc::getMessage('AWZ_ADMIN_MENU_NAME_AWZ_ALLXLS'),
    "title" => Loc::getMessage('AWZ_ADMIN_MENU_NAME_AWZ_ALLXLS'),
    "items_id" => str_replace('.','_',$module_id),
    "items" => $items,
);
return $aMenu;