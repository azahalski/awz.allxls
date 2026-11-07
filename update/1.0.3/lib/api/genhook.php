<?php
namespace Awz\Allxls\Api;

use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;
use Awz\Allxls\Access\AccessController;

class Genhook extends \Bitrix\Main\Engine\Controller {

    public function __construct(Request $request = null)
    {
        Loader::includeModule('awz.allxls');
        parent::__construct($request);
    }

    public function configureActions()
    {
        $config = [
            'create'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
            'delete'=>[
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\CloseSession(),
                ]
            ],
        ];
        return $config;
    }

    /**
     * Создание хука и методов в awz.bxorm
     */
    public function createAction()
    {
        if(!AccessController::isEditSettings()){
            $this->addError(new Error(Loc::getMessage('ACCESS_DENIED'), 'ACCESS_DENIED'));
            return null;
        }
        global $USER;
        $userId = ($USER && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
        $result = \Awz\Allxls\Helper\GenHook::createHook($userId);
        if(!$result->isSuccess()){
            foreach($result->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }
        return $result->getData();
    }

    /**
     * Удаление сгенерированных хука и методов
     */
    public function deleteAction()
    {
        if(!AccessController::isEditSettings()){
            $this->addError(new Error(Loc::getMessage('ACCESS_DENIED'), 'ACCESS_DENIED'));
            return null;
        }
        $result = \Awz\Allxls\Helper\GenHook::deleteHook();
        if(!$result->isSuccess()){
            foreach($result->getErrors() as $err){
                $this->addError($err);
            }
            return null;
        }
        return $result->getData();
    }

}
