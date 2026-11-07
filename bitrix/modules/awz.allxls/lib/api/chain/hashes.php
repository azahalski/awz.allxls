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
 * Р Р°Р±РѕС‚Р° СЃ С…РµС€Р°РјРё РёРјРїРѕСЂС‚Р° (РґРµРґСѓРїР»РёРєР°С†РёСЏ СЃС‚СЂРѕРє): РїРѕРёСЃРє/СЃРѕС…СЂР°РЅРµРЅРёРµ/СѓРґР°Р»РµРЅРёРµ
 * С…РµС€РµР№, С…РµС€ РІ РїР°РјСЏС‚Рё РїСЂРѕС„РёР»СЏ.
 */
class Hashes extends Stats {

    /** @var array|null набор ROW_HASH профиля в памяти (опция HASH_MEM_CACHE=Y, null — не загружен); ключ ROW_HASH => ELEMENT_ID */
    protected ?array $hashMemSet = null;

    /** @var string маркер ELEMENT_ID для строк, пропущенных по правилам сопоставления (элемент не создается) */
    protected const HASH_SKIP_MARK = 'skip';


    protected function touchHashElement(int $profileId, string $rowHash, array $settings): void
{
    if ((string)($settings['SYSTEM_REPLACE_DATE'] ?? 'N') !== 'Y') {
        return;
    }
    $__perfT0 = microtime(true);
    try {
        if ($this->hashMemSet !== null) {
            // ELEMENT_ID из набора в памяти (без запроса в БД на каждую строку)
            $elementId = (int)($this->hashMemSet[$rowHash] ?? 0);
        } else {
            $elementId = (int)ProfilesHashesTable::getRowElementId($profileId, $rowHash);
        }
        if ($elementId <= 0) {
            // ELEMENT_ID в хеше пуст или это маркер пропуска по правилам
            // (HASH_SKIP_MARK): элемента нет — обновлять нечего
            Logger::debug(
                'ELEMENT_ID не найден в хеше строки — дата не обновлена',
                ['profile_id' => $profileId, 'row_hash' => $rowHash]
            );
            return;
        }
        $destType = (string)($this->profileData['SETTINGS']['DEST_TYPE'] ?? '');
        /** @var \Awz\Allxls\Services\Base $entityService */
        $entityService = $this->resolveService($destType);
        // замер времени обновления даты — в импорт-классе
        $this->svcTime('touch', function () use ($entityService, $settings, $elementId) {
            Handlers::withImportFlag(function () use ($entityService, $settings, $elementId) {
                $entityService->touchElement(
                    (string)($settings['IBLOCK_ID'] ?? ''),
                    $elementId
                );
            }, (string)($settings['IBLOCK_ID'] ?? ''));
        });
    } catch (\Throwable $e) {
        Logger::error(
            'Ошибка обновления даты неизмененного элемента',
            ['profile_id' => $profileId, 'error' => $e->getMessage()]
        );
    }
    self::perfAccum('touch_hash', microtime(true) - $__perfT0);
}
    protected function checkHashSafe(int $profileId, string $rowHash): bool
{
    if ($this->hashMemSet !== null) {
        return isset($this->hashMemSet[$rowHash]);
    }
    try {
        $__perfT0 = microtime(true);
        $result = ProfilesHashesTable::checkRowHash($profileId, $rowHash);
        self::perfAccum('hash_check', microtime(true) - $__perfT0);
        return $result;
    } catch (\Throwable $e) {
        Logger::error(
            'Ошибка проверки хеша строки (дедуп отключен)',
            ['profile_id' => $profileId, 'error' => $e->getMessage()]
        );
        return false;
    }
}
    protected function isHashFinal(int $profileId, string $rowHash): bool
{
    if ($this->hashMemSet !== null) {
        return (string)($this->hashMemSet[$rowHash] ?? '') !== '';
    }
    try {
        $__perfT0 = microtime(true);
        $result = ProfilesHashesTable::getRowElementId($profileId, $rowHash) !== '';
        self::perfAccum('hash_final', microtime(true) - $__perfT0);
        return $result;
    } catch (\Throwable $e) {
        Logger::error(
            'Ошибка чтения ELEMENT_ID хеша строки (строка будет обработана повторно)',
            ['profile_id' => $profileId, 'error' => $e->getMessage()]
        );
        return false;
    }
}
    protected function saveHashSafe(int $profileId, string $rowHash, string $elementId = '', string $entityId = ''): void
{
    try {
        // при активном наборе в памяти: если хеш уже сохранен с тем же
        // ELEMENT_ID — запись в БД не нужна (повторные прогоны не тратят
        // SELECT+INSERT на каждую строку)
        if ($this->hashMemSet !== null && $rowHash !== ''
            && (string)($this->hashMemSet[$rowHash] ?? null) === $elementId) {
            return;
        }
        $__perfT0 = microtime(true);
        ProfilesHashesTable::saveRowHash($profileId, $rowHash, $elementId, $entityId);
        self::perfAccum('hash_save', microtime(true) - $__perfT0);
        if ($this->hashMemSet !== null && $rowHash !== '') {
            $this->hashMemSet[$rowHash] = $elementId;
        }
    } catch (\Throwable $e) {
        Logger::error(
            'Ошибка сохранения хеша строки (дедуп не работает)',
            ['profile_id' => $profileId, 'element_id' => $elementId, 'entity_id' => $entityId, 'error' => $e->getMessage()]
        );
    }
}
    protected function deleteHashSafe(int $profileId, string $rowHash): void
{
    try {
        ProfilesHashesTable::deleteRowHash($profileId, $rowHash);
        if ($this->hashMemSet !== null) {
            unset($this->hashMemSet[$rowHash]);
        }
    } catch (\Throwable $e) {
        Logger::error(
            'Ошибка удаления хеша строки',
            ['profile_id' => $profileId, 'error' => $e->getMessage()]
        );
    }
}
    protected function usedRowHash(array $row, array $sheetConfig): string
{
    $columns = is_array($sheetConfig['COLUMNS'] ?? null) ? $sheetConfig['COLUMNS'] : [];
    $used = [];
    foreach ($columns as $key => $col) {
        if (($col['INCLUDE'] ?? 'Y') === 'N') continue;
        $maps = is_array($col['MAPS'] ?? null) ? $col['MAPS'] : [];
        $hasMaps = false;
        foreach ($maps as $field) {
            if (trim((string)$field) !== '') {
                $hasMaps = true;
                break;
            }
        }
        if (!$hasMaps) continue;
        $index = (int)filter_var($key, FILTER_SANITIZE_NUMBER_INT);
        $used[$key] = (string)($row[$index] ?? '');
    }
    return $this->rowHash($used);
}
    protected function rowHash(array $row): string
{
    // рекурсивная нормализация: сортировка ключей, приведение к строкам
    $normalize = static function ($value) use (&$normalize) {
        if (is_array($value)) {
            ksort($value);
            return array_map($normalize, $value);
        }
        return (string)$value;
    };
    $json = json_encode($normalize($row), JSON_UNESCAPED_UNICODE);
    return md5($json === false ? serialize($row) : $json);
}
}
