<?php
namespace Awz\Allxls\Services;

use Bitrix\Main\Loader;

use Awz\Allxls\Helper\ProfilesHelper;

/**
 * Сервис-источник экспорта: элементы инфоблока.
 *
 * Читает порции элементов инфоблока для пошаговой выгрузки (Pexport).
 * Получается через ServiceLocator:
 *  \Bitrix\Main\DI\ServiceLocator::getInstance()->get('awz.allxls.iblockexport')
 */
class iblockexport implements ExportBase
{
    /**
     * @inheritDoc
     */
    public function getFields(string $entityId): array
    {
        return ProfilesHelper::getFieldGroups(
            ['SETTINGS' => ['IBLOCK_ID' => (int)$entityId, 'DEST_TYPE' => 'iblock']],
            (int)$entityId
        );
    }

    /**
     * @inheritDoc
     */
    public function countElements(string $entityId, array $filter = []): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }
        $filter = $this->prepareFilter((int)$entityId, $filter);
        $res = \CIBlockElement::GetList([], $filter, false, false, ['ID']);
        $count = 0;
        while ($res->Fetch()) {
            $count++;
        }
        return $count;
    }

    /**
     * @inheritDoc
     */
    public function getElements(string $entityId, $lastId, int $limit, array $select, array $filter = []): array
    {
        if (!Loader::includeModule('iblock') || $limit <= 0) {
            return [];
        }
        $filter = $this->prepareFilter((int)$entityId, $filter, $lastId);

        $rows = [];
        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => $limit],
            $select
        );
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $fields['PREVIEW_PICTURE'] = $fields['PREVIEW_PICTURE'] ? \CFile::GetPath($fields['PREVIEW_PICTURE']) : '';
            $fields['DETAIL_PICTURE'] = $fields['DETAIL_PICTURE'] ? \CFile::GetPath($fields['DETAIL_PICTURE']) : '';
            $properties = $ob->GetProperties();
            foreach($properties as $prop){
                if($prop['PROPERTY_TYPE'] == 'F'){
                    if(is_array($prop['VALUE'])){
                        foreach($prop['VALUE'] as &$v){
                            $v = $v ? \CFile::GetPath($v) : '';
                        }
                        unset($v);
                    }else{
                        $prop['VALUE'] = $prop['VALUE'] ? \CFile::GetPath($prop['VALUE']) : '';
                    }
                }
                $fields['PROPERTY_'.($prop['CODE'] ? $prop['CODE'] : $prop['ID'])] = $prop['VALUE'];
            }
            $rows[] = $fields;
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function getRecordId(array $record)
    {
        return (int)($record['ID'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function extractFieldValue(array $record, string $field, string $delimiter = ', '): string
    {
        if ($field === '') return '';
        $delimiter = $delimiter !== '' ? $delimiter : ', ';
        if (strpos($field, 'PROPERTY_') === 0) {
            $value = $record[$field.'_VALUE'] ?? ($record[$field] ?? '');
        } else {
            $value = $record[$field] ?? '';
        }
        if (is_array($value)) {
            $value = implode($delimiter, array_map('strval', $value));
        }
        return (string)$value;
    }

    /**
     * Сборка фильтра выборки элементов: инфоблок + курсор >ID + JSON-фильтр профиля.
     *
     * @param int $entityId ID инфоблока
     * @param array $filter пользовательский фильтр (JSON-фильтр профиля)
     * @param string|int $lastId курсор выборки
     * @return array
     */
    protected function prepareFilter(int $entityId, array $filter, $lastId = 0): array
    {
        $result = ['IBLOCK_ID' => $entityId];
        if ((int)$lastId > 0) {
            $result['>ID'] = (int)$lastId;
        }
        foreach ($filter as $key => $value) {
            if (is_string($key) && $key !== '') {
                $result[$key] = $value;
            }
        }
        // права не должны влиять на выборку при экспорте
        $result['CHECK_PERMISSIONS'] = 'N';
        return $result;
    }
}
