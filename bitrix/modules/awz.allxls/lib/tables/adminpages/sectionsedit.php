<?php

namespace Awz\Allxls\Tables\AdminPages;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Awz\Admin\Helper;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IForm;
use Awz\Admin\IParams;

Loc::loadMessages(__FILE__);

class SectionsEdit extends IForm implements IParams {

    public function __construct($params){
        parent::__construct($params);
        global $POST_RIGHT;
        $POST_RIGHT = 'D';
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)){
            $POST_RIGHT = 'R';
        }
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_EDIT) ||
            AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_DELETE)
        ){
            $POST_RIGHT = 'W';
        }
    }


    public function trigerCheckActionAdd($func){
        return $func;
    }

    public function trigerCheckActionUpdate($func){
        return $func;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_ALLXLS_S_SECTIONS_EDIT_TITLE');
    }

    public static function getParams(): array
    {
        $BUTTON_CONTEXTS = array('btn_new'=>false,'btn_delete'=>false);
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_DELETE)){
            unset($BUTTON_CONTEXTS['btn_delete']);
        }
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_ADD)){
            unset($BUTTON_CONTEXTS['btn_new']);
        }
        $arParams = array(
            "ENTITY" => "\\Awz\\Allxls\\Tables\\SectionsTable",
            "BUTTON_CONTEXTS"=>$BUTTON_CONTEXTS,
            "LIST_URL"=>'/bitrix/admin/awz_allxls_sections_list.php',
            "TABS"=>array(
                "edit1" => array(
                    "NAME"=>Loc::getMessage('AWZ_ALLXLS_S_SECTIONS_EDIT_EDIT1'),
                    "FIELDS" => array(
                        "NAME",
                        "CODE",
                        "SORT"
                    )
                )
            )
        );
        return $arParams;
    }
}