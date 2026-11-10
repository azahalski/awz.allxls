<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * Шаг 2 (экспорт) — файл выгрузки.
 * Путь автозаполняется: /upload/awz.allxls/<имя файла>.<csv|xls>
 *
 * @var array  $profile    данные профиля
 * @var array  $settings  декодированные настройки профиля
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;

$filePath = (string)($settings['FILE_PATH'] ?? '');
$csvDelimiter = (string)($settings['CSV_DELIMITER'] ?? ',');
if ($csvDelimiter === '') $csvDelimiter = ',';
$isCustomDelimiter = !in_array($csvDelimiter, [',', ';', "\t"], true);
$csvEncoding = (string)($settings['CSV_ENCODING'] ?? 'utf8');
if (!in_array($csvEncoding, ['utf8', 'cp1251', 'other'], true)) $csvEncoding = 'utf8';
$csvEncodingCustom = (string)($settings['CSV_ENCODING_CUSTOM'] ?? '');
$destType = (string)($settings['DEST_TYPE'] ?? 'iblock');
if (!in_array($destType, ['iblock', 'orm'], true)) $destType = 'iblock';
$iblockId = (int)($settings['IBLOCK_ID'] ?? 0);
$ormFile = (string)($settings['ORM_FILE'] ?? '');

// разбираем сохраненный путь на имя файла и расширение
$exportDir = '/upload/awz.allxls/';
$fileName = '';
$fileExt = 'csv';
if ($filePath !== '') {
    $info = pathinfo($filePath);
    $fileName = (string)($info['filename'] ?? '');
    $ext = strtolower((string)($info['extension'] ?? ''));
    $fileExt = ($ext === 'xlsx') ? 'xlsx' : 'csv';
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

    <div class="awz-allxls-card">
        <div class="awz-allxls-card__head">
            <div class="awz-allxls-card__title"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP2_EXPORT_TITLE')?></div>
            <div class="awz-allxls-card__sub"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_STEP2_EXPORT_SUB')?></div>
        </div>

        <form id="awz_allxls_profile_file_form" onsubmit="return false;">
            <input type="hidden" name="FILE_PATH" id="AWZ_ALLXLS_PROFILE_FILE_PATH"
                   value="<?=htmlspecialcharsbx($filePath)?>">
            <div class="awz-allxls-row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SRC_TYPE')?></div>
                <div class="awz-allxls-row__ctrl">
                    <select class="awz-allxls-input" name="DEST_TYPE" id="AWZ_ALLXLS_PROFILE_DEST_TYPE">
                        <?php foreach (\Awz\Allxls\Helper\ProfilesHelper::getDestTypes('export') as $dtCode => $dtTitle): ?>
                        <option value="<?=htmlspecialcharsbx($dtCode)?>" <?=$destType===$dtCode?'selected':''?>><?=htmlspecialcharsbx($dtTitle)?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SRC_TYPE_DESC')?></div>
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
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_SRC_IBLOCK_DESC')?></div>
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
                <div class="awz-allxls-row__label">JSON-фильтр элементов</div>
                <div class="awz-allxls-row__ctrl">
                    <textarea class="awz-allxls-textarea" name="EXPORT_FILTER" id="AWZ_ALLXLS_PROFILE_EXPORT_FILTER"
                              placeholder='{"ACTIVE":"Y",">&nbsp;ID":100}' style="min-height:70px;font-family:Consolas,monospace;"
                    ><?=htmlspecialcharsbx((string)($settings['EXPORT_FILTER'] ?? ''))?></textarea>
                    <div class="awz-allxls-row__desc">Необязательный фильтр выборки элементов (JSON, формат фильтра GetList). Передается в сервис экспорта.</div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_export_file_row">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_EXPORT_FILE_NAME')?></div>
                <div class="awz-allxls-row__ctrl">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span class="awz-allxls-row__desc" style="white-space:nowrap;"><?=$exportDir?></span>
                        <input class="awz-allxls-input" type="text" name="EXPORT_FILE_NAME"
                               id="AWZ_ALLXLS_PROFILE_EXPORT_FILE_NAME"
                               value="<?=htmlspecialcharsbx($fileName)?>"
                               placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_EXPORT_FILE_NAME_PH'))?>">
                        <button type="button" class="awz-allxls-btn awz-export-ext-btn<?=$fileExt==='csv'?' awz-allxls-btn--primary':' awz-allxls-btn--ghost'?>" data-ext="csv">.csv</button>
                        <button type="button" class="awz-allxls-btn awz-export-ext-btn<?=$fileExt==='xlsx'?' awz-allxls-btn--primary':' awz-allxls-btn--ghost'?>" data-ext="xlsx">.xlsx</button>
                        <button type="button" class="awz-allxls-btn awz-export-ext-btn<?=$fileExt==='json'?' awz-allxls-btn--primary':' awz-allxls-btn--ghost'?>" data-ext="json">.json</button>
                    </div>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_EXPORT_FILE_NAME_DESC')?></div>
                </div>
            </div>
            <div class="awz-allxls-row" id="awz_allxls_profile_csv_delimiter_row" style="<?=$fileExt==='csv'?'':'display:none;'?>">
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
            <div class="awz-allxls-row" id="awz_allxls_profile_csv_encoding_row" style="<?=$fileExt==='csv'?'':'display:none;'?>">
                <div class="awz-allxls-row__label"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING')?></div>
                <div class="awz-allxls-row__ctrl">
                    <div style="display:flex;gap:8px;align-items:center;">
                        <select class="awz-allxls-input" name="CSV_ENCODING_TYPE" id="AWZ_ALLXLS_PROFILE_CSV_ENCODING_TYPE">
                            <option value="utf8" <?=$csvEncoding==='utf8'?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING_UTF8')?></option>
                            <option value="cp1251" <?=$csvEncoding==='cp1251'?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING_CP1251')?></option>
                            <option value="other" <?=$csvEncoding==='other'?'selected':''?>><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING_OTHER')?></option>
                        </select>
                        <input class="awz-allxls-input" type="text" name="CSV_ENCODING_CUSTOM"
                               id="AWZ_ALLXLS_PROFILE_CSV_ENCODING_CUSTOM" maxlength="32"
                               value="<?=htmlspecialcharsbx($csvEncoding==='other' ? $csvEncodingCustom : '')?>"
                               placeholder="<?=htmlspecialcharsbx(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING_OTHER_PH'))?>"
                               style="width:120px;<?=$csvEncoding==='other'?'':'display:none;'?>">
                    </div>
                    <div class="awz-allxls-row__desc"><?=Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_CSV_ENCODING_DESC')?></div>
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
