<?php
namespace Awz\Allxls\Tables;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Bitrix\Main\ORM;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\ArrayField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * ORM сущность профилей экспорта/импорта xls
 */
class ProfilesTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_awz_allxls_profiles';
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
                ->configureTitle('Название профиля'),

            (new BooleanField('ACTIVE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('Y')
                ->configureTitle('Активность'),

            (new StringField('DESC'))
                ->configureNullable(true)
                ->configureSize(255)
                ->configureTitle('Описание'),

            (new StringField('TYPE'))
                ->configureRequired(true)
                ->configureSize(10)
                ->configureDefaultValue('import')
                ->configureTitle('Тип профиля (import/export)'),

            (new IntegerField('SECTION_ID'))
                ->configureDefaultValue(0)
                ->configureTitle('Раздел'),

            (new ArrayField('SETTINGS'))
                ->configureTitle('Настройки')
                ->configureSerializeCallback(function($value){
                    if (!is_array($value)) {
                        $value = [];
                    }
                    return base64_encode(gzcompress(Json::encode($value), 9));
                })
                ->configureUnserializeCallback(function($value){
                    if (empty($value)) {
                        return [];
                    }
                    $decrypted = base64_decode($value, true);
                    if ($decrypted === false) {
                        return [];
                    }
                    $json = @gzuncompress($decrypted);
                    if ($json === false) {
                        $json = $decrypted;
                    }
                    try {
                        $data = Json::decode($json);
                        return is_array($data) ? $data : [];
                    } catch (\Exception $e) {
                        return [];
                    }
                }),

            (new StringField('SETTINGS_HASH'))
                ->configureNullable(true)
                ->configureSize(32)
                ->configureTitle('Хеш настроек (кеш)'),

            (new IntegerField('RUN_COUNT'))
                ->configureDefaultValue(0)
                ->configureTitle('Количество запусков'),

            (new DatetimeField('DATE_UPDATE'))
                ->configureDefaultValue(function () {
                    return new DateTime();
                })
                ->configureTitle('Дата обновления'),

            (new IntegerField('UPDATED_BY'))
                ->configureDefaultValue(0)
                ->configureTitle('Кем обновлен (USER_ID)'),

            (new DatetimeField('DATE_LAST_RUN'))
                ->configureNullable(true)
                ->configureTitle('Дата последнего запуска'),

            (new DatetimeField('DATE_LAST_END'))
                ->configureNullable(true)
                ->configureTitle('Дата последнего завершения'),

            (new DatetimeField('DATE_NEXT_RUN'))
                ->configureNullable(true)
                ->configureTitle('Дата следующего запуска'),

            (new BooleanField('CRON_ACTIVE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('N')
                ->configureTitle('Запуск по расписанию (cron)'),

            (new StringField('CRON_SCHEDULE'))
                ->configureNullable(true)
                ->configureSize(50)
                ->configureTitle('Расписание cron (5 полей)'),

            (new IntegerField('CRON_HIT_TIME'))
                ->configureDefaultValue(0)
                ->configureTitle('Время одного шага cron-запуска, сек (0 — до завершения)'),

            (new IntegerField('CRON_PAUSE'))
                ->configureDefaultValue(0)
                ->configureTitle('Пауза после завершения прогона, сек (0 — выключено)'),

            (new IntegerField('CRON_FALLS'))
                ->configureDefaultValue(0)
                ->configureTitle('Счетчик подряд идущих падений cron-процесса'),

            (new BooleanField('CRON_RUNNING'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('N')
                ->configureTitle('Выполняется cron-процессом (блокировка)'),

            (new DatetimeField('CRON_START_TIME'))
                ->configureNullable(true)
                ->configureTitle('Время старта cron-запуска'),

            (new StringField('CRON_LAST_ERR'))
                ->configureNullable(true)
                ->configureSize(255)
                ->configureTitle('Ошибка последнего cron-запуска'),

            (new Reference('SECTION', SectionsTable::class, Join::on('this.SECTION_ID', 'ref.ID')))
                ->configureJoinType('left'),
        ];
    }

    public static function onBeforeAdd(ORM\Event $event): ORM\EventResult
    {
        $fields = $event->getParameter('fields');
        if($fields['TYPE']=='export' && !self::canCurrentUser(ActionDictionary::ACTION_EXPORT_ADD)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на добавление профилей экспорта", "ERR_RIGHT"));
            return $result;
        }
        if($fields['TYPE']=='import' && !self::canCurrentUser(ActionDictionary::ACTION_IMPORT_ADD)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на добавление профилей импорта", "ERR_RIGHT"));
            return $result;
        }
        return self::prepareUpdateFields($event, false);
    }

    public static function onBeforeUpdate(ORM\Event $event): ORM\EventResult
    {
        $fields = $event->getParameter('fields');
        $id = $event->getParameter('primary');
        if(is_array($id)) $id = $id['ID'];
        if(!$fields['TYPE']){
            $item = self::getById($id)->fetch();
            $fields['TYPE'] = $item['TYPE'];
        }
        if($fields['TYPE']=='export' && !self::canCurrentUser(ActionDictionary::ACTION_EXPORT_EDIT)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на изменение профилей экспорта", "ERR_RIGHT"));
            return $result;
        }
        if($fields['TYPE']=='import' && !self::canCurrentUser(ActionDictionary::ACTION_IMPORT_EDIT)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на изменение профилей импорта", "ERR_RIGHT"));
            return $result;
        }
        return self::prepareUpdateFields($event, true);
    }

    public static function OnBeforeDelete(ORM\Event $event): ORM\EventResult
    {
        $result = new ORM\EventResult;
        $fields = $event->getParameter('fields');
        $id = $event->getParameter('primary');

        if(is_array($id)) $id = $id['ID'];
        if(!$fields['TYPE']){
            $item = self::getById($id)->fetch();
            $fields['TYPE'] = $item['TYPE'];
        }

        if($fields['TYPE']=='export' && !self::canCurrentUser(ActionDictionary::ACTION_EXPORT_DELETE)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на удаление профилей экспорта", "ERR_RIGHT"));
            return $result;
        }
        if($fields['TYPE']=='import' && !self::canCurrentUser(ActionDictionary::ACTION_IMPORT_DELETE)){
            $result = new ORM\EventResult;
            $result->addError(new ORM\EntityError("нет прав на удаление профилей импорта", "ERR_RIGHT"));
            return $result;
        }

        return $result;
    }

    /**
     * Проверка прав на действие для текущего пользователя.
     * Без авторизованного пользователя (cron/CLI) проверка не выполняется —
     * иначе AccessController::can возвращает false и любое обновление профиля
     * (курсор импорта, состояние) молча не сохраняется.
     */
    private static function canCurrentUser(string $action): bool
    {
        $userId = (int)(\Bitrix\Main\Engine\CurrentUser::get()?->getId() ?? 0);
        if ($userId <= 0) {
            return true;
        }
        return AccessController::can($userId, $action);
    }

    private static function prepareUpdateFields(ORM\Event $event, bool $isUpdate): ORM\EventResult
    {
        $result = new ORM\EventResult();
        $fields = $event->getParameter('fields');
        $fields['DATE_UPDATE'] = new DateTime();
        $user = \Bitrix\Main\Engine\CurrentUser::get();
        if ($user && $user->getId() > 0) {
            $fields['UPDATED_BY'] = $user->getId();
        }
        unset($fields['ID']);

        // вычисляем хеш настроек
        $newHash = '';
        if (array_key_exists('SETTINGS', $fields)) {
            $settings = $fields['SETTINGS'];
            if (!is_array($settings)) {
                $settings = [];
            }
            // служебные параметры не участвуют в хеше: состояние импорта
            // и параметры запуска (время хита, макс. время скрипта, тип хеша,
            // журнал ошибок, режим дедупа, системные опции) не должны сбрасывать хеши строк
            $hashSettings = $settings;
            foreach (['PROCESS', 'HIT_TIME_LIMIT', 'MAX_EXECUTION_TIME', 'LOG_ERRORS', 'HASH_TYPE', 'HASH_MEM_CACHE', 'HASH_USED_COLUMNS', 'STEP_BY_STEP', 'SKIP_IF_NOT_FOUND', 'SYSTEM_REPLACE_DATE', 'SYSTEM_FACET', 'SYSTEM_META', 'SYSTEM_SEARCH_NEW', 'SYSTEM_SEARCH_UPDATE', 'SYSTEM_RESIZE_PICTURES', 'SYSTEM_UPDATE_ORDER', 'SYSTEM_IBLOCK_CACHE', 'FINISH_CLEAN_MODE', 'FINISH_CLEAN_SCOPE', 'FINISH_SET_PROP_CODE', 'FINISH_SET_PROP_VALUE', 'CRON_MODE', 'CAT_TREE'] as $volatileKey) {
                unset($hashSettings[$volatileKey]);
            }
            // рекурсивная сортировка ключей — стабильный хеш независимо от порядка сохранения
            $sortKeys = static function (array &$arr) use (&$sortKeys) {
                ksort($arr);
                foreach ($arr as &$value) {
                    if (is_array($value)) {
                        $sortKeys($value);
                    }
                }
                unset($value);
            };
            $sortKeys($hashSettings);
            $newHash = md5(Json::encode($hashSettings));
        }
        $fields['SETTINGS_HASH'] = $newHash;

        // при обновлении: если хеш настроек изменился - чистим хеши профиля
        if ($isUpdate) {
            $primary = $event->getParameter('id');
            $profileId = is_array($primary) ? (int)($primary['ID'] ?? 0) : (int)$primary;
            if ($profileId > 0) {
                $current = self::getRow([
                    'select' => ['SETTINGS_HASH'],
                    'filter' => ['=ID' => $profileId],
                ]);
                $oldHash = $current ? (string)$current['SETTINGS_HASH'] : '';
                if ($oldHash !== '' && $newHash !== '' && $oldHash !== $newHash) {
                    ProfilesHashesTable::clearProfileHashes($profileId);
                }
            }
        }

        $result->modifyFields($fields);
        return $result;
    }

}
