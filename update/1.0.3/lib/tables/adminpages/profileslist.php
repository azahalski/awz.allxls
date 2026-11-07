<?php

namespace Awz\Allxls\Tables\AdminPages;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Bitrix\Main\Localization\Loc;
use Awz\Admin\IList;
use Awz\Admin\IParams;
use Awz\Admin\Helper;

Loc::loadMessages(__FILE__);

class ProfilesList extends IList implements IParams {

    public $type_profile = '';

    public static $sections = [];

    public static $lastId;

    public function __construct($params){
        global $POST_RIGHT;
        parent::__construct($params);
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $filter = [];
        if($request->get('export')=='Y'){
            $this->type_profile = 'export';
            $filter['=TYPE'] = 'export';
            if(!AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)){
                $this->getAdminList()->arGroupErrors[] = ["Нет прав на просмотр профилей экспорта"];
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT) ||
                AccessController::can(0,ActionDictionary::ACTION_EXPORT_DELETE)
            ){
                $POST_RIGHT = 'W';
            }
        }else{
            $this->type_profile = 'import';
            $filter['=TYPE'] = 'import';
            if(!AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)){
                $this->getAdminList()->arGroupErrors[] = ["Нет прав на просмотр профилей импорта"];
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT) ||
                AccessController::can(0,ActionDictionary::ACTION_IMPORT_DELETE)
            ){
                $POST_RIGHT = 'W';
            }
        }
        $this->addFilter($filter);
    }

    public function trigerGetRowListAdmin($row){
        Helper::viewListField($row, 'ID', ['type'=>'entity_link'], $this);
        Helper::viewListField($row, 'NAME', ['type'=>'entity_link'], $this);
        Helper::editListField($row, 'NAME', ['type'=>'string'], $this);
        Helper::editListField($row, 'DESC', ['type'=>'string'], $this);
        Helper::editListField($row, 'ACTIVE', ['type'=>'checkbox'], $this);
        $row->AddSelectField('SECTION_ID', self::$sections, []);
        self::$lastId = $row->arRes['ID'];
    }

    public function trigerInitFilter(){
        if($this->type_profile == 'export' && !AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)){
            $this->filter['<ID'] = 0;
        }
        if($this->type_profile == 'import' && !AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)){
            $this->filter['<ID'] = 0;
        }
        if($this->type_profile == 'export'){
            $this->filter['=TYPE'] = 'export';
        }
        if($this->type_profile == 'import'){
            $this->filter['!TYPE'] = 'export';
        }
    }

    public function trigerGetRowListActions(array $actions): array
    {
        $editFile = $this->getParam("FILE_EDIT").'?'.$this->getParam("PRIMARY").'='.self::$lastId.'&lang='.LANG;
        if(
            ($this->type_profile == 'export' && AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)
            && !AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT))
            ||
            ($this->type_profile == 'import' && AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)
                && !AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT))
        ){
            $actions[] = array(
                "ICON"=>"view",
                "DEFAULT"=>true,
                "TEXT"=>'Посмотреть',
                "TITLE"=>'Посмотреть',
                "ACTION"=>$this->getAdminList()->ActionRedirect($editFile)
            );
        }
        if(
            ($this->type_profile == 'export' && AccessController::can(0,ActionDictionary::ACTION_EXPORT_LOAD))
            ||
            ($this->type_profile == 'import' && AccessController::can(0,ActionDictionary::ACTION_IMPORT_LOAD))
        ){
            $actions[] = array(
                "ICON"=>"view",
                "DEFAULT"=>true,
                "TEXT"=>'Запустить',
                "TITLE"=>'Запустить',
                "ACTION"=>$this->getAdminList()->ActionRedirect($editFile.'&'.$this->type_profile.'=Y&step=4')
            );
        }
        return $actions;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_ALLXLS_S_PROFILES_LIST_TITLE');
    }

    public static function getParams(): array
    {

        $sections = ['0'=>'Без раздела'];
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)){
            $r = \Awz\Allxls\Tables\SectionsTable::getList(['select'=>['ID','NAME']]);
            while($data = $r->fetch()){
                $sections[$data['ID']] = $data['NAME'];
            }
        }
        self::$sections = $sections;

        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $btn = [];
        $ADD_GROUP_ACTIONS = [];
        $ADD_LIST_ACTIONS = [];

        if($request->get('export')=='Y'){
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT)){
                $ADD_GROUP_ACTIONS[] = 'edit';
                $ADD_LIST_ACTIONS[] = 'edit';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_DELETE)){
                $ADD_GROUP_ACTIONS[] = 'delete';
                $ADD_LIST_ACTIONS[] = 'delete';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_ADD)){
                $btn['add'] = [
                    'TEXT' => "Новый профиль экспорта",
                    'ICON' => 'btn_new',
                    'LINK' => 'awz_allxls_profiles_edit.php'.'?lang='.LANG.'&export=Y',
                ];
            }
        }else{
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT)){
                $ADD_GROUP_ACTIONS[] = 'edit';
                $ADD_LIST_ACTIONS[] = 'edit';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_DELETE)){
                $ADD_GROUP_ACTIONS[] = 'delete';
                $ADD_LIST_ACTIONS[] = 'delete';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_ADD)){
                $btn['add'] = [
                    'TEXT' => "Новый профиль импорта",
                    'ICON' => 'btn_new',
                    'LINK' => 'awz_allxls_profiles_edit.php'.'?lang='.LANG.'&import=Y',
                ];
            }
        }

        $arParams = array(
            "PRIMARY"=>'ID',
            "ENTITY" => "\\Awz\\Allxls\\Tables\\ProfilesTable",
            "FILE_EDIT"=>"awz_allxls_profiles_edit.php",
            "BUTTON_CONTEXTS"=>$btn,
            "ADD_GROUP_ACTIONS"=>$ADD_GROUP_ACTIONS,
            "ADD_LIST_ACTIONS"=>$ADD_LIST_ACTIONS,
            "FIND"=>array(
                'SECTION_ID'=>[
                    'id'=>'SECTION_ID',
                    'realId'=>'SECTION_ID',
                    'name'=>'Раздел',
                    'type'=>'list',
                    "items" => $sections ?? [],
                    "params"=>["multiple"=>"Y"]
                ]
            ),
            "FIND_FROM_ENTITY"=>array(
                'NAME'=>[],
                'ACTIVE'=>[],
            ),
        );
        return $arParams;
    }
}