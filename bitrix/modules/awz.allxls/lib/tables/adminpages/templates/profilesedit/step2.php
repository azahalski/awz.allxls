<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Шаг 2 — выбор файла источника.
 * Доступные переменные (передаются из ProfilesEdit::defaultInterface()):
 *
 * @var array  $profile    данные профиля
 * @var array  $settings  декодированные настройки профиля
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;

$filePath = (string)($settings['FILE_PATH'] ?? '');
$proxy = (string)($settings['PROXY'] ?? '');
$csvDelimiter = (string)($settings['CSV_DELIMITER'] ?? ',');
if ($csvDelimiter === '') $csvDelimiter = ',';
$isCustomDelimiter = !in_array($csvDelimiter, [',', ';', "\t"], true);
$destType = (string)($settings['DEST_TYPE'] ?? 'iblock');
if (!in_array($destType, ['iblock', 'orm'], true)) $destType = 'iblock';
$iblockId = (int)($settings['IBLOCK_ID'] ?? 0);
$ormFile = (string)($settings['ORM_FILE'] ?? '');
$xmlPaths = [];
if (is_array($settings['XML_PATHS'] ?? null)) {
    foreach ($settings['XML_PATHS'] as $xmlPath) {
        if (is_array($xmlPath) && !empty($xmlPath['PATH'])) {
            $xmlPaths[] = (string)$xmlPath['PATH'];
        } elseif (is_string($xmlPath) && $xmlPath !== '') {
            $xmlPaths[] = $xmlPath;
        }
    }
}
$xmlPathsValue = implode(', ', $xmlPaths);
$fileCacheLifetime = max(0, (int)($settings['FILE_CACHE_LIFETIME'] ?? 7200));

// формат источника по расширению пути к файлу
$fileFormat = '';
$fileExt = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$formatMap = [
    'csv' => 'CSV', 'txt' => 'CSV',
    'xls' => 'Excel (xls)', 'xlsx' => 'Excel (xlsx)',
    'xml' => 'XML', 'yml' => 'YML', 'json' => 'JSON',
];
if (isset($formatMap[$fileExt])) {
    $fileFormat = $formatMap[$fileExt];
}
$explicitExt = strtolower(trim((string)($settings['FILE_EXT'] ?? '')));
$isXmlSource = in_array($explicitExt, ['xml', 'yml', 'json'], true)
    || in_array($fileExt, ['xml', 'yml', 'json'], true);
// путь к файлу: URL или локальный путь
$fileSource = '';
if ($filePath !== '') {
    $fileSource = preg_match('#^https?://#i', $filePath) ? 'Внешняя ссылка' : 'Локальный путь';
}

// инфоблоки, сгруппированные по типам
$iblockGroups = [];
if (\Bitrix\Main\Loader::includeModule('iblock')) {
    $iblockTypes = [];
    $rsTypes = \CIBlockType::GetList(['SORT' => 'ASC'], []);
    while ($type = $rsTypes->Fetch()) {
        $typeName = (string)($type['ID'] ?? '');
        if ($typeName === '') continue;
        $typeLang = \CIBlockType::GetByID($typeName)->Fetch();
        $iblockTypes[$typeName] = (string)($typeLang['NAME'] ?? $typeName);
    }
    $rsIblocks = \CIBlock::GetList(['SORT' => 'ASC'], ['CHECK_PERMISSIONS' => 'N']);
    while ($iblock = $rsIblocks->Fetch()) {
        $iblockGroups[(string)$iblock['IBLOCK_TYPE_ID']][] = [
            'ID' => (int)$iblock['ID'],
            'NAME' => $iblock['NAME'] . ' [' . $iblock['ID'] . ']',
        ];
    }
}
?>
<div class="awz-allxls-page">

    <?php $currentStep = 2; ?>
    <?php include __DIR__.'/_steps.php'; ?>

        <?php if ($profileId > 0): ?>
        <div class="awz-allxls-card" style="background:#f2f4f7;">
            <div style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:24px;font-size:13px;">
                <div><b>Название профиля:</b> <?=htmlspecialcharsbx($profile['NAME'] ?? '')?></div>
                <div><b>Формат:</b> <?=($fileFormat !== '' ? htmlspecialcharsbx($fileFormat) : '—')?></div>
                <?php $filePathDisplay = htmlspecialcharsbx($filePath); ?>
                <div style="word-break:break-all;"><b>Источник:</b> <?=($fileSource !== '' ? $fileSource : '—')?><?=(($fileSource !== '') ? ' — ' : '')?><?=(($filePath !== '') ? '<a href="'.$filePathDisplay.'" target="_blank" rel="noopener noreferrer">'.$filePathDisplay.'</a>' : '')?></div>
            </div>
        </div>
        <?php endif; ?>

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP2_TITLE')?></div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP2_SUB')?></div>
        </div>

        <form id="awz_allxls_profile_file_form" onsubmit="return false;">
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_TYPE')?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-input" name="DEST_TYPE" id="AWZ_ALLXLS_PROFILE_DEST_TYPE">
                        <?php foreach (\Awz\Allxls\Helper\ProfilesHelper::getDestTypes('import') as $dtCode => $dtTitle): ?>
                        <option value="<?=htmlspecialcharsbx($dtCode)?>" <?=$destType===$dtCode?'selected':''?>><?=htmlspecialcharsbx($dtTitle)?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_TYPE_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_dest_iblock_row" style="<?=$destType==='iblock'?'':'display:none;'?>">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_IBLOCK')?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-input awz-allxls-input--w" name="IBLOCK_ID" id="AWZ_ALLXLS_PROFILE_IBLOCK_ID">
                        <option value=""><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_IBLOCK_EMPTY')?></option>
                        <?php foreach ($iblockGroups as $typeName => $items):?>
                            <optgroup label="<?=htmlspecialcharsbx($iblockTypes[$typeName] ?? $typeName)?>">
                                <?php foreach ($items as $item):?>
                                    <option value="<?=$item['ID']?>" <?=$iblockId===$item['ID']?'selected':''?>><?=htmlspecialcharsbx($item['NAME'])?></option>
                                <?php endforeach;?>
                            </optgroup>
                        <?php endforeach;?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_IBLOCK_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_max_params_row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_MAX_PARAMS') ?: 'Макс. количество колонок из файла'?></div>
                <div class="awz-allxls-row__ctrl">
                    <input class="awz-allxls-input" type="text" name="MAX_PARAMS"
                           id="AWZ_ALLXLS_PROFILE_MAX_PARAMS" inputmode="numeric"
                           value="<?=htmlspecialcharsbx((string)((int)($settings['MAX_PARAMS'] ?? 0)))?>">
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_MAX_PARAMS_DESC') ?: 'Ограничение количества колонок (параметров) из файла импорта в настройках сопоставления (шаг 3). 0 — без ограничения'?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_dest_orm_row" style="<?=$destType==='orm'?'':'display:none;'?>">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_ORM_FILE')?></div>
                <div class="awz-allxls-row__ctrl">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input class="awz-allxls-input awz-allxls-input--w" type="text" name="ORM_FILE"
                               id="AWZ_ALLXLS_PROFILE_ORM_FILE"
                               value="<?=htmlspecialcharsbx($ormFile)?>"
                               placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_ORM_FILE_PH'))?>">
                        <input type="button" value="..." onClick="window.AWZ_ALLXLS_PROFILE_ORM_FILE()">
                    </div>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_ORM_FILE_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_PATH')?></div>
                <div class="awz-allxls-row__ctrl">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input class="awz-allxls-input awz-allxls-input--w" type="text" name="FILE_PATH"
                               id="AWZ_ALLXLS_PROFILE_FILE_PATH"
                               value="<?=htmlspecialcharsbx($filePath)?>"
                               placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_PATH_PH'))?>">
                        <input type="button" value="..." onClick="window.AWZ_ALLXLS_PROFILE_FILE_PATH()">
                    </div>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_PATH_DESC')?></div>
                </div>
            </div>
            <?php $fileExt = strtolower(trim((string)($settings['FILE_EXT'] ?? ''))); ?>
            <div class="awz-allxls-row" id="awz_allxls_profile_file_ext_row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_EXT') ?: 'Тип файла'?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-input" name="FILE_EXT" id="AWZ_ALLXLS_PROFILE_FILE_EXT">
                        <option value="" <?=$fileExt===''?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_EXT_AUTO') ?: 'Авто (по расширению)'?></option>
                        <?php foreach (['csv' => 'CSV', 'xlsx' => 'XLSX', 'xls' => 'XLS (Excel 97-2003)', 'xml' => 'XML', 'yml' => 'YML (Yandex Market)', 'json' => 'JSON'] as $extCode => $extTitle): ?>
                            <option value="<?=$extCode?>" <?=$fileExt===$extCode?'selected':''?>><?=$extTitle?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_EXT_DESC') ?: 'Для ссылок и FTP без расширения в пути укажите формат файла явно (например, ссылка отдает CSV, но адрес этого не показывает)'?></div>
                </div>
            </div>
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_PROXY')?></div>
                <div class="awz-allxls-row__ctrl">
                    <input class="awz-allxls-input awz-allxls-input--w" type="text" name="PROXY"
                           id="AWZ_ALLXLS_PROFILE_PROXY"
                           value="<?=htmlspecialcharsbx($proxy)?>"
                           placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_PROXY_PH'))?>">
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_PROXY_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_file_ttl_row" style="display:none;">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_TTL') ?: 'Время жизни кеша файла'?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-input" name="FILE_CACHE_LIFETIME" id="AWZ_ALLXLS_PROFILE_FILE_CACHE_LIFETIME">
                        <?php foreach (\Awz\Allxls\Helper\ProfilesHelper::fileCacheLifetimes() as $ttlValue => $ttlTitle): ?>
                        <option value="<?=$ttlValue?>" <?=$ttlValue===((int)($settings['FILE_CACHE_LIFETIME'] ?? 7200))?'selected':''?>><?=htmlspecialcharsbx($ttlTitle)?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_TTL_DESC') ?: 'Только для внешних ссылок. Файл скачивается в кеш на cron/агенте и обновляется не чаще указанного времени'?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_csv_delimiter_row" style="display:none;">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIMITER')?></div>
                <div class="awz-allxls-row__ctrl">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select class="awz-allxls-input" name="CSV_DELIMITER_TYPE" id="AWZ_ALLXLS_PROFILE_CSV_DELIMITER_TYPE">
                            <option value="," <?=$csvDelimiter===','?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIM_COMMA')?></option>
                            <option value=";" <?=$csvDelimiter===';'?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIM_SEMICOLON')?></option>
                            <option value="\t" <?=$csvDelimiter==='\t'?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIM_TAB')?></option>
                            <option value="other" <?=$isCustomDelimiter?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIM_OTHER')?></option>
                        </select>
                        <input class="awz-allxls-input" type="text" name="CSV_DELIMITER_CUSTOM"
                               id="AWZ_ALLXLS_PROFILE_CSV_DELIMITER_CUSTOM" maxlength="1"
                               value="<?=htmlspecialcharsbx($isCustomDelimiter ? $csvDelimiter : '')?>"
                               placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIM_OTHER_PH'))?>"
                               style="width:80px;<?=$isCustomDelimiter?'':'display:none;'?>">
                    </div>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_DELIMITER_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_xml_paths_row" style="">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_XML_PATHS') ?: 'Пути до элементов'?></div>
                <div class="awz-allxls-row__ctrl">
                    <input class="awz-allxls-input awz-allxls-input--w" type="text" name="XML_PATHS"
                           id="AWZ_ALLXLS_PROFILE_XML_PATHS"
                           value="<?=htmlspecialcharsbx($xmlPathsValue)?>"
                           placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_XML_PATHS_PH') ?: 'items->item, categories->section, offers->offer')?>">
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_XML_PATHS_DESC') ?: 'Пути до повторяющихся элементов через -> или /. Каждый путь — отдельный лист. Пример: items->item, offers->offer'?></div>
                </div>
            </div>
        </form>
    </div>

    <div class="awz-allxls-footer">
        <a href="/bitrix/admin/awz_allxls_profiles_edit.php?id=<?=$profileId?>&step=1&lang=<?=LANGUAGE_ID?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            &larr; <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_BACK')?>
        </a>
        <button type="button" class="awz-allxls-btn awz-allxls-btn--primary" id="awz_allxls_profile_file_save">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_NEXT') ?: 'Следующий шаг'?>
        </button>
        <a href="<?=$listUrl?>" class="awz-allxls-btn awz-allxls-btn--ghost">
            <?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_BTN_CANCEL')?>
        </a>
    </div>
</div>

<?\CAdminFileDialog::ShowScript(array(
    "event" => "AWZ_ALLXLS_PROFILE_FILE_PATH",
    "arResultDest" => array("ELEMENT_ID" => "AWZ_ALLXLS_PROFILE_FILE_PATH"),
    "arPath" => array("PATH" => "/upload/"),
    "select" => 'F',
    "operation" => 'O',
    "showUploadTab" => true,
    "showAddToMenuTab" => false,
    "fileFilter" => '',
    "allowAllFiles" => true,
    "SaveConfig" => true,
));?>

<?\CAdminFileDialog::ShowScript(array(
    "event" => "AWZ_ALLXLS_PROFILE_ORM_FILE",
    "arResultDest" => array("ELEMENT_ID" => "AWZ_ALLXLS_PROFILE_ORM_FILE"),
    "arPath" => array("PATH" => "/"),
    "select" => 'F',
    "operation" => 'O',
    "showUploadTab" => false,
    "showAddToMenuTab" => false,
    "fileFilter" => 'php',
    "allowAllFiles" => false,
    "SaveConfig" => true,
));?>
