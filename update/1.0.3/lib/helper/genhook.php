<?php
namespace Awz\Allxls\Helper;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Security\Random;

Loc::loadMessages(__FILE__);

/**
 * Генерация хука и методов в модуле awz.bxorm для ИИ агентов.
 *
 * Добавленные данные определяются по именованию:
 * имя хука и методов начинается с маркера GenHook::MARKER ("from awz.allxls").
 */
class GenHook {

    /** маркер в начале имени методов и хука */
    const MARKER = 'from awz.allxls';

    /** код хука (имя) */
    const HOOK_NAME = 'from awz.allxls: хук для ИИ агентов';

    /**
     * Список методов для генерации (прописывается вручную).
     *
     * Формат элемента:
     * [
     *   'NAME'    => 'from awz.allxls: Название метода',
     *   'CODE'    => 'УникальныйКод',
     *   'ENTITY'  => '\Namespace\EntityTable',  // ORM-класс или контроллер
     *   'MODULES' => ['iblock'],                 // модули, подключаемые перед вызовом
     *   'PARAMS'  => [
     *       'fields' => [ // параметры полей сущности (isActive/type/...)
     *           'ID' => ['isActive'=>'Y','type'=>'primary','isRequired'=>'N','isReadonly'=>'Y','isSortable'=>'Y','title'=>'ID'],
     *       ],
     *       'methods' => ['list'=>'Y','get'=>'N','add'=>'N','update'=>'N','delete'=>'N','fields'=>'N'],
     *   ],
     * ]
     */
    const METHODS = [
        [
            'NAME'    => 'from awz.allxls: Импорт',
            'CODE'    => 'awzaiimport',
            'ENTITY'  => '\\Awz\\Allxls\\Api\\Profiles',
            'MODULES' => ['main', 'awz.allxls', 'awz.bxorm', 'iblock'],
            'PARAMS'  => [
                'methods' => [
                    'save'=>'Y',
                    'savefile'=>'Y',
                    'settings'=>'Y',
                    'savesettings'=>'Y',
                    'fields'=>'Y',
                    'createproperty'=>'Y',
                    'propertycheck'=>'Y',
                    'externals'=>'Y',
                    'externalinfo'=>'Y',
                    'externals'=>'Y',
                    'externalinfo'=>'Y',
                ],
            ],
        ],
        [
            'NAME'    => 'from awz.allxls: Технические методы',
            'CODE'    => 'awzaitech',
            'ENTITY'  => '\\Awz\\Allxls\\Api\\Tech',
            'MODULES' => ['main', 'awz.allxls', 'awz.bxorm', 'iblock'],
            'PARAMS'  => [
                'methods' => [
                    'upload'=>'Y',
                    'iblocks'=>'Y'
                ],
            ],
        ],
        [
            'NAME'    => 'from awz.allxls: Запуск импорта',
            'CODE'    => 'awzairun',
            'ENTITY'  => '\\Awz\\Allxls\\Api\\Pimport',
            'MODULES' => ['main', 'awz.allxls', 'awz.bxorm', 'iblock'],
            'PARAMS'  => [
                'methods' => [
                    'import'=>'Y',
                    'saveparams'=>'Y',
                    'finish'=>'Y'
                ],
            ],
        ],
    ];

    /**
     * Установлен ли модуль awz.bxorm
     * @return bool
     */
    public static function isBxOrmInstalled(): bool
    {
        return Loader::includeModule('awz.bxorm');
    }

    /**
     * Существует ли сгенерированный хук
     * @return bool
     */
    public static function hasHook(): bool
    {
        if(!self::isBxOrmInstalled()) return false;
        $r = \Awz\BxOrm\HooksTable::getList([
            'select'=>['ID'],
            'filter'=>['%=NAME'=>self::MARKER.'%'],
            'limit'=>1
        ]);
        return (bool)$r->fetch();
    }

    /**
     * Ссылки на модуль awz.bxorm
     * @return array
     */
    public static function getModuleLinks(): array
    {
        return [
            'https://github.com/azahalski/awz.bxorm',
            'https://marketplace.1c-bitrix.ru/solutions/awz.bxorm/',
            'https://zahalski.dev/modules/awz.bxorm/',
        ];
    }

    /**
     * Создание хука и методов (idempotent — существующие не дублируются)
     * @param int $userId ID пользователя, для которого генерируется ключ
     * @return Result
     */
    public static function createHook(int $userId): Result
    {
        $result = new Result();
        if(!self::isBxOrmInstalled()){
            $result->addError(new Error(Loc::getMessage('AWZ_ALLXLS_GENHOOK_ERR_NO_MODULE')));
            return $result;
        }
        if($userId <= 0){
            $result->addError(new Error(Loc::getMessage('AWZ_ALLXLS_GENHOOK_ERR_NO_USER')));
            return $result;
        }

        // существующие сгенерированные данные
        $existingMethods = [];
        $r = \Awz\BxOrm\MethodsTable::getList([
            'select'=>['ID','NAME','CODE'],
            'filter'=>['%=NAME'=>self::MARKER.'%']
        ]);
        while($row = $r->fetch()){
            $existingMethods[$row['CODE']] = $row['ID'];
        }

        $methodIds = [];
        foreach(self::METHODS as $method){
            $code = (string)($method['CODE'] ?? '');
            if($code === '' || isset($existingMethods[$code])) continue;
            $add = \Awz\BxOrm\MethodsTable::add([
                'NAME'    => (string)$method['NAME'],
                'CODE'    => $code,
                'ENTITY'  => (string)$method['ENTITY'],
                'ACTIVE'  => 'Y',
                'MODULES' => (array)($method['MODULES'] ?? []),
                'PARAMS'  => (array)($method['PARAMS'] ?? []),
            ]);
            if($add->isSuccess()){
                $methodIds[] = $add->getId();
            }
        }

        // хук создается один раз
        $r = \Awz\BxOrm\HooksTable::getList([
            'select'=>['ID'],
            'filter'=>['%=NAME'=>self::MARKER.'%'],
            'limit'=>1
        ]);
        $hook = $r->fetch();
        if($hook){
            // дополняем хук новыми методами
            if(!empty($methodIds)){
                $hookFull = \Awz\BxOrm\HooksTable::getRowById($hook['ID']);
                $methods = is_array($hookFull['METHODS']) ? $hookFull['METHODS'] : [];
                foreach($methodIds as $mid){
                    if(!in_array($mid, $methods)) $methods[] = $mid;
                }
                \Awz\BxOrm\HooksTable::update($hook['ID'], ['METHODS'=>$methods]);
            }
            $result->setData(['HOOK_ID'=>$hook['ID'], 'METHODS'=>$methodIds]);
            return $result;
        }

        // ключ в формате "ID пользователя|токен" (длина ограничена полем TOKEN)
        $token = Random::getStringByAlphabet(
            max(16, 22 - mb_strlen((string)$userId)),
            Random::ALPHABET_NUM|Random::ALPHABET_ALPHALOWER|Random::ALPHABET_ALPHAUPPER,
            true
        );

        // все сгенерированные методы (включая ранее созданные) в хук
        $allMethodIds = array_values($existingMethods);
        foreach($methodIds as $mid){
            $allMethodIds[] = $mid;
        }

        $add = \Awz\BxOrm\HooksTable::add([
            'NAME'    => self::HOOK_NAME,
            'TOKEN'   => $userId.'|'.$token,
            'ACTIVE'  => 'Y',
            'METHODS' => $allMethodIds,
        ]);
        if(!$add->isSuccess()){
            foreach($add->getErrors() as $err){
                $result->addError($err);
            }
            return $result;
        }

        $result->setData(['HOOK_ID'=>$add->getId(), 'METHODS'=>$methodIds, 'TOKEN'=>$userId.'|'.$token]);
        return $result;
    }

    /**
     * Удаление сгенерированных хука и методов (по именованию)
     * @return Result
     */
    public static function deleteHook(): Result
    {
        $result = new Result();
        if(!self::isBxOrmInstalled()){
            $result->addError(new Error(Loc::getMessage('AWZ_ALLXLS_GENHOOK_ERR_NO_MODULE')));
            return $result;
        }

        $deletedHooks = 0;
        $deletedMethods = 0;

        $r = \Awz\BxOrm\HooksTable::getList([
            'select'=>['ID'],
            'filter'=>['%=NAME'=>self::MARKER.'%']
        ]);
        while($row = $r->fetch()){
            $del = \Awz\BxOrm\HooksTable::delete($row['ID']);
            if($del->isSuccess()){
                $deletedHooks++;
            }else{
                foreach($del->getErrors() as $err){
                    $result->addError($err);
                }
            }
        }

        $r = \Awz\BxOrm\MethodsTable::getList([
            'select'=>['ID'],
            'filter'=>['%=NAME'=>self::MARKER.'%']
        ]);
        while($row = $r->fetch()){
            $del = \Awz\BxOrm\MethodsTable::delete($row['ID']);
            if($del->isSuccess()){
                $deletedMethods++;
            }else{
                foreach($del->getErrors() as $err){
                    $result->addError($err);
                }
            }
        }

        $result->setData(['HOOKS'=>$deletedHooks, 'METHODS'=>$deletedMethods]);
        return $result;
    }

}
