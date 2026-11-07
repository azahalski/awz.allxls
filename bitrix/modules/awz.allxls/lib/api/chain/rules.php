<?php
namespace Awz\Allxls\Api\Chain;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Request;
use Bitrix\Main\Loader;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Localization\Loc;
use Awz\Allxls\Tables\ProfilesTable;
use Awz\Allxls\Tables\ProfilesHashesTable;
use Awz\Allxls\MapRules;
use Awz\Allxls\Helper\FastXlsReader;
use Awz\Allxls\Helper\FastXmlReader;
use Awz\Allxls\Helper\ProfilesHelper;
use Awz\Allxls\Logger;
use Awz\Allxls\Handlers;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * РџСЂР°РІРёР»Р° СЃРѕРїРѕСЃС‚Р°РІР»РµРЅРёСЏ (MapRules): РїСЂРёРјРµРЅРµРЅРёРµ РїСЂР°РІРёР» Рє Р·РЅР°С‡РµРЅРёСЏРј РєРѕР»РѕРЅРѕРє,
 * РїСЂР°РІРёР»Р° РїСЂРѕРїСѓСЃРєР°/СѓРґР°Р»РµРЅРёСЏ СЃС‚СЂРѕРє.
 */
class Rules extends Hashes {

    /** @var bool флаг срабатывания правила пропуска строки (сбрасывается перед каждой строкой) */
    protected bool $rowSkipRules = false;

    /** @var bool сработало правило удаления элемента (TYPE=delete) — липкий флаг строки */
    protected bool $rowDeleteRules = false;

    /** @var string найден ли существующий элемент при поиске ('Y'|'N') — для макроса #ELEMENT_FOUND# */
    protected string $itemElementFound = 'N';

    /** @var array|null данные найденного при поиске элемента — для макросов #ENTITY_<ПОЛЕ># в правилах */
    protected ?array $itemEntityData = null;

    /** @var array|null сработавшее правило пропуска TYPE=skip (для логов) */
    protected ?array $rowSkipRuleInfo = null;

    /** @var string|null значение колонки, по которому сработало правило пропуска (для логов) */
    protected ?string $rowSkipRuleValue = null;

    /** @var array техническая информация последней обработанной строки (для ответа клиенту) */
    protected array $rowDebug = [];

    /** @var bool режим «импорт по одному элементу»: одна строка за запрос, дальше — по кнопке */
    protected bool $stepByStep = false;

    /** @var array список ошибок обработки */
    protected array $processErrors = [];


    protected function isMapAllowed(string $entityId, array $col, array $settings, string $mode): bool
{
    switch ($mode) {
        case 'search':
            /** @var \Awz\Allxls\Services\iblock $iblockService */
            $iblockService = $this->resolveService($this->profileData['SETTINGS']['DEST_TYPE']);
            $search = $iblockService->getColumnSearch($entityId, [
                'SEARCH_USE' => $col['SEARCH_USE'] ?? 'N',
                'SEARCH_MODE' => $col['SEARCH_MODE'] ?? 'eq',
                'MAP_SETTINGS' => [$settings],
            ]);
            return $search !== null;
        case 'update':
            return (string)($settings['IS_NEW'] ?? '') !== 'Y';
        case 'new':
            return (string)($settings['IS_NEW'] ?? '') !== 'U';
        default:
            return true;
    }
}
    protected function applyMaps(string $value, array $col, array $rowValues = [], string $fromKey = '', array $columns = [], string $iblockId = ''): string
{
    // флаг правила «не обрабатывать значение» — на каждый вызов (по полю)
    MapRules::$lastUnset = false;
    // поле назначения сопоставления — для проверки существования значения (#VALUE_FOUND#)
    $field = (string)(($col['MAPS'] ?? [])[0] ?? '');
    $result = MapRules::applyMaps($value, $col, $rowValues, [
        'columns' => $columns,
        'from' => $fromKey,
        'iblock_id' => $iblockId,
        // найден ли существующий элемент (Y|N) — макрос #ELEMENT_FOUND#
        // ('N' в полной сборке: элемент еще не искался на этот момент)
        'element_found' => $this->itemElementFound,
        // данные найденного элемента — макросы #ENTITY_<ПОЛЕ># (условия и действия)
        'entity_data' => $this->itemEntityData,
        // контекст проверки значения — макрос #VALUE_FOUND# (списки/привязки)
        'value_check' => ['iblock_id' => (int)$iblockId, 'field' => $field],
    ]);
    // фиксируем срабатывание правила пропуска строки (флаг липкий до сброса перед следующей строкой)
    if (MapRules::$lastSkip) {
        $this->rowSkipRules = true;
        // запоминаем какое правило пропуска сработало и значение сравнения
        $this->rowSkipRuleInfo = MapRules::$lastSkipRule;
        $this->rowSkipRuleValue = MapRules::$lastSkipValue;
    }
    // правило удаления элемента (TYPE=delete): строка пропускается,
    // найденный элемент удаляется (importStep после сборки item)
    if (MapRules::$lastDelete) {
        $this->rowDeleteRules = true;
    }
    // вопрос «найден ли элемент» актуален только в сборке этапов
    // поиска/сохранения (processItem) — в полной сборке элемент еще не искался
    return $result;
}
    protected function describeRule(array $entry): string
{
    $rule = is_array($entry['rule'] ?? null) ? $entry['rule'] : [];
    $agg = (string)($rule['AGG'] ?? 'AND');
    $conds = isset($rule['CONDITIONS']) && is_array($rule['CONDITIONS']) ? $rule['CONDITIONS'] : [];
    $condText = $conds
        ? implode(' ' . $agg . ' ', array_map([$this, 'describeCondition'], $conds))
        : 'без условий (всегда)';

    $type = (string)($entry['type'] ?? 'transform');
    if ($type === 'skip') {
        return $condText . ' → пропуск строки';
    }
    if ($type === 'delete') {
        return $condText . ' → удаление элемента';
    }
    if ($type === 'unset') {
        return $condText . ' → значение не загружается';
    }
    $text = $condText . ' → ' . (string)($entry['action'] ?? '');
    $aval = (string)($entry['aval'] ?? '');
    if ($aval !== '') {
        $text .= ' "' . $aval . '"';
    }
    return $text;
}
    protected function describeCondition(array $cond): string
{
    if (isset($cond['CHILDREN']) && is_array($cond['CHILDREN'])) {
        $childAgg = (string)($cond['AGG'] ?? 'AND');
        return '(' . implode(' ' . $childAgg . ' ',
            array_map([$this, 'describeCondition'], $cond['CHILDREN'])) . ')';
    }
    static $ops = [
        'eq' => '=',
        'ne' => '≠',
        'gt' => '>',
        'lt' => '<',
        'ge' => '>=',
        'le' => '<=',
        'between' => 'между',
        'contains' => 'содержит',
        'not_contains' => 'не содержит',
        'begins' => 'начинается с',
        'ends' => 'заканчивается на',
        'empty' => 'пусто',
        'not_empty' => 'не пусто',
        'regex' => 'regex',
        'not_regex' => 'не regex',
        'any' => 'любое значение',
    ];
    $op = (string)($cond['OP'] ?? 'eq');
    $opText = $ops[$op] ?? $op;
    $source = (string)($cond['SOURCE'] ?? '');
    $sourceText = $source !== '' ? $source : 'значение колонки';
    $value = (string)($cond['VALUE'] ?? '');
    $text = $sourceText . ' ' . $opText . ' "' . $value . '"';
    if ($op === 'between') {
        $text .= ' и "' . (string)($cond['VALUE2'] ?? '') . '"';
    }
    return $text;
}
    protected function deleteRowByRule(
    int $id, array $settings, string $sheetName, int $rowNum, int $processed,
    array $item, string $rowHashRaw, string $itemHash, int $entityId,
    array $rawRow, array $sheetConfig, array $colStats, array $sheetTotals,
    int $memStart, int $memPeakStart, int $bytesRead, float $percent, int $fileSize
): array {
    $deleted = false;
    $deletedId = 0;
    $filter = [];
    try {
        /** @var \Awz\Allxls\Services\iblock $entityService */
        $entityService = $this->resolveService($this->profileData['SETTINGS']['DEST_TYPE']);
        // сборка поисковых полей и фильтр поиска элемента.
        // флаги пропуска/удаления уже выставлены правилами в полной сборке
        // (правило delete выставляет и rowSkipRules): здесь их сбрасываем,
        // иначе buildItem('search') оборвется на первой колонке и фильтр
        // поиска останется пустым — элемент не найдется и не удалится
        $skipBackup = $this->rowSkipRules;
        $deleteBackup = $this->rowDeleteRules;
        MapRules::$lastSkip = false;
        MapRules::$lastDelete = false;
        $this->rowSkipRules = false;
        $this->rowDeleteRules = false;
        $searchItem = $this->buildItem((string)$entityId, $rawRow, $sheetConfig, $colStats, 'search');
        // восстанавливаем флаги: решение о пропуске уже принято в importStep
        $this->rowSkipRules = $skipBackup;
        $this->rowDeleteRules = $deleteBackup;
        $filter = $entityService->buildFilter((string)$entityId, $searchItem, $sheetConfig, (int)$entityId);
        if (!empty($filter)) {
            $existing = $entityService->findElement((string)$entityId, $filter);
            if ($existing) {
                $f = $existing;
                $deletedId = (int)$f['ID'];
                // удаление самим импортом — обработчикам событий запрещено
                // трогать хеши элемента (иначе строка вернется как новая)
                // замер времени удаления — в импорт-классе
                $deleted = (bool)$this->svcTime('delete', function () use ($entityService, $deletedId, $entityId) {
                    return Handlers::withImportFlag(function () use ($entityService, $deletedId, $entityId) {
                        return $entityService->deleteElement((string)$entityId, $deletedId);
                    }, (string)$entityId);
                })[0];
            }
        }
    } catch (\Throwable $e) {
        Logger::error('Ошибка удаления элемента по правилу', [
            'profile_id' => $id, 'row_num' => $rowNum,
            'filter' => $filter, 'error' => $e->getMessage(),
        ]);
    }
    if ($deleted) {
        Logger::info('Элемент удален по правилу импорта', [
            'profile_id' => $id, 'row_num' => $rowNum,
            'element_id' => $deletedId, 'filter' => $filter,
            'rule' => $this->rowSkipRuleInfo, 'rule_value' => $this->rowSkipRuleValue,
        ]);
    }
    // обе метки хешей — с маркером skip: строка больше не обрабатывается
    self::saveHashSafe($id, $rowHashRaw, self::HASH_SKIP_MARK, (string)$entityId);
    if ($itemHash !== '') {
        self::saveHashSafe($id, $itemHash, self::HASH_SKIP_MARK, (string)$entityId);
    }
    return $this->skipRow(
        $id, $settings, $sheetName, $rowNum, 0, $processed,
        $deleted ? 'Удалено по правилу' : 'Ошибки удаления по правилу',
        $item, $memStart, $memPeakStart, $bytesRead, $percent,
        $fileSize, 0, $colStats, $sheetTotals
    );
}
    protected function skipRow(
    int $id, array $settings, string $sheetName, int $rowNum, int $offset,
    int $processed, string $statKey, ?array $item,
    int $memStart, int $memPeakStart, int $bytesRead, float $percent,
    int $fileSize, int $readPos, array $colStats, array $sheetTotals
): array {
    $__perfT0 = microtime(true);
    // замер памяти сразу после чтения, пока объекты не уничтожены GC
    $memEnd = memory_get_usage(false);
    $memPeakEnd = memory_get_peak_usage(false);
    $this->processStats[$statKey] = (int)($this->processStats[$statKey] ?? 0) + 1;
    // курсор строки сдвигается — коммит байтовой позиции XML-курсора листа
    $this->commitXmlPos($sheetName);
    // причина пропуска — для технической информации (результат колонок)
    $this->rowDebug['skip_type'] = $statKey;
    // причина пропуска строки в PSR-лог — с прореживанием: на каждую 500-ю
    // строку и первую; построчная запись на десятки тысяч пропусков съедала
    // больше времени, чем сама проверка хеша
    static $skipLogCounter = 0;
    $skipLogCounter++;
    if ($skipLogCounter === 1 || $skipLogCounter % 500 === 0) {
        Logger::debug(
            'Строка пропущена: '.$statKey,
            [
                'profile_id' => $id,
                'sheet' => $sheetName,
                'row_num' => $rowNum,
                'rule' => $this->rowSkipRuleInfo,
                'rule_value' => $this->rowSkipRuleValue,
                'item' => $item,
                'skip_count' => $skipLogCounter,
            ]
        );
    }
    // строки, пропущенные по хешу, учитываются в обработанных
    if ($statKey === 'Пропущено по хешу') {
        $processed++;
    }
    $this->processStats['Текущий лист'] = $sheetName;
    $this->processStats['Текущая строка'] = $rowNum;
    $this->processStats['Обработано строк'] = $processed;
    $this->processStats['Прогресс файла, %'] = floor($percent * 10) / 10;
    $this->saveState(
        $id, $settings, $sheetName, $rowNum + 1, $offset, $processed,
        $this->processStats, $this->processErrors, false,
        $colStats, $sheetTotals
    );
    $resp = $this->buildResponse(
        $processed, false, $item, $this->processErrors, $this->processStats,
        $memStart, $memPeakStart, $bytesRead, $percent, $fileSize, $readPos,
        $memEnd, $memPeakEnd
    );
    $resp['columns'] = $this->topColumns($colStats);
    // техническая информация пропущенной строки (только режим «по одному элементу»)
    if ($this->stepByStep) {
        $this->rowDebug['sheet'] = $sheetName;
        $this->rowDebug['row'] = $rowNum;
        $resp['debug'] = $this->rowDebug;
    }
    self::perfAccum('skip_row', microtime(true) - $__perfT0);
    return $resp;
}
}
