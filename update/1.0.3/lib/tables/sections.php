<?php
namespace Awz\Allxls\Tables;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Awz\Allxls\Api\Profiles;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * ORM сущность разделов профилей
 */
class SectionsTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_awz_allxls_sections';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true),

            (new StringField('NAME'))
                ->configureRequired(true)
                ->configureSize(255)
                ->configureTitle('Название раздела'),

            (new StringField('CODE'))
                ->configureSize(50)
                ->configureTitle('Код раздела'),

            (new IntegerField('SORT'))
                ->configureDefaultValue(100)
                ->configureTitle('Сортировка'),

            (new DatetimeField('DATE_UPDATE'))
                ->configureDefaultValue(function () {
                    return new DateTime();
                })
                ->configureTitle('Дата обновления'),

            (new IntegerField('UPDATED_BY'))
                ->configureDefaultValue(0)
                ->configureTitle('Кем обновлен (USER_ID)'),
        ];
    }

    public static function onBeforeAdd(ORM\Event $event): ORM\EventResult
    {
        if(!AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_ADD)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на добавление раздела", "ERR_RIGHT"));
            return $result;
        }
        return self::prepareUpdateFields($event);
    }

    public static function onBeforeUpdate(ORM\Event $event): ORM\EventResult
    {
        if(!AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_EDIT)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на редактирование раздела", "ERR_RIGHT"));
            return $result;
        }
        return self::prepareUpdateFields($event);
    }
    public static function OnBeforeDelete(ORM\Event $event)
    {
        $result = new ORM\EventResult;
        $fields = $event->getParameter('fields');
        $id = $event->getParameter('primary');
        if(!AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_DELETE)){
            $result->addError(new ORM\EntityError("нет прав на удаление раздела", "ERR_RIGHT"));
            return $result;
        }
        $profiles = ProfilesTable::getList([
            'select'=>['ID'],
            'filter'=>['SECTION_ID'=>is_array($id) ? $id['ID'] : $id]
        ])->fetchAll();
        $ids = [];
        foreach($profiles as $data){
            $ids[] = $data['ID'];
        }
        if(!empty($ids)){
            $result->addError(new ORM\EntityError("Раздел привязан к профилям: ".implode(", ", $ids), "ERR_DATA"));
        }
        return $result;
    }

    private static function prepareUpdateFields(ORM\Event $event): ORM\EventResult
    {
        $result = new ORM\EventResult();
        $fields = $event->getParameter('fields');
        $fields['DATE_UPDATE'] = new DateTime();
        $user = \Bitrix\Main\Engine\CurrentUser::get();
        if ($user && $user->getId() > 0) {
            $fields['UPDATED_BY'] = $user->getId();
        }
        unset($fields['ID']);

        $fields['SORT'] = (int)$fields['SORT']??0;

        $result->modifyFields($fields);

        return $result;
    }
}
