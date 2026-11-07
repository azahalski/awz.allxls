<?php

namespace Awz\Allxls\Tables\AdminPages;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IList;
use Awz\Admin\IParams;
use Awz\Admin\Helper;

Loc::loadMessages(__FILE__);

class SectionsList extends IList implements IParams {

    public function __construct($params){
        parent::__construct($params);
        if(!AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)){
            $this->getAdminList()->arGroupErrors[] = ["Нет прав на просмотр разделов"];
        }
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_EDIT) ||
            AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_DELETE)
        ){
            global $POST_RIGHT;
            $POST_RIGHT = 'W';
        }
    }

    public function trigerGetRowListAdmin($row){
        Helper::viewListField($row, 'ID', ['type'=>'entity_link'], $this);
        Helper::viewListField($row, 'NAME', ['type'=>'entity_link'], $this);
        Helper::editListField($row, 'NAME', ['type'=>'string'], $this);
        Helper::editListField($row, 'CODE', ['type'=>'string'], $this);
        Helper::editListField($row, 'SORT', ['type'=>'string'], $this);
    }

    public function trigerInitFilter(){
        if(!AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)){
            $this->filter['<ID'] = 0;
        }
    }

    public function trigerGetRowListActions(array $actions): array
    {
        $editFile = $this->getParam("FILE_EDIT").'?'.$this->getParam("PRIMARY").'='.$this->arRes[$this->getParam("PRIMARY")].'&lang='.LANG;
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)
            && !AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_EDIT)
        ){
            $actions[] = array(
                "ICON"=>"view",
                "DEFAULT"=>true,
                "TEXT"=>'Посмотреть',
                "TITLE"=>'Посмотреть',
                "ACTION"=>$this->getAdminList()->ActionRedirect($editFile)
            );
        }
        return $actions;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_ALLXLS_S_SECTIONS_LIST_TITLE');
    }

    public static function getParams(): array
    {
        $btn = [];
        $ADD_GROUP_ACTIONS = [];
        $ADD_LIST_ACTIONS = [];

        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_EDIT)){
            $ADD_GROUP_ACTIONS[] = 'edit';
            $ADD_LIST_ACTIONS[] = 'edit';
        }
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_DELETE)){
            $ADD_GROUP_ACTIONS[] = 'delete';
            $ADD_LIST_ACTIONS[] = 'delete';
        }

        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_ADD)){
            $btn['add'] = [
                'TEXT' => "Добавить раздел",
                'ICON' => 'btn_new',
                'LINK' => 'awz_allxls_sections_edit.php'.'?lang='.LANG,
            ];
        }

        $arParams = array(
            "ENTITY" => "\\Awz\\Allxls\\Tables\\SectionsTable",
            "FILE_EDIT"=>"awz_allxls_sections_edit.php",
            "BUTTON_CONTEXTS"=>$btn,
            "ADD_GROUP_ACTIONS"=>$ADD_GROUP_ACTIONS,
            "ADD_LIST_ACTIONS"=>$ADD_LIST_ACTIONS,
            "FIND"=>array(),
            "FIND_FROM_ENTITY"=>array('NAME'=>[]),
        );
        return $arParams;
    }
}