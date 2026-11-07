<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

/** @global CMain $APPLICATION */
/** @var array $arGadgetParams */

$moduleId = 'awz.allxls';
if (!Loader::includeModule($moduleId)) return;

$APPLICATION->SetTitle(GetMessage('GD_AWZ_ALLXLS_TITLE'));

$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < "R") return;
if (!\Awz\Allxls\Access\AccessController::can(0, \Awz\Allxls\Access\Custom\ActionDictionary::ACTION_PROFILE_SECTION_VIEW)) return;

$count = (int)($arGadgetParams["PROFILES_COUNT"] ?? 10);
if ($count <= 0) $count = 10;
$showExport = ($arGadgetParams["SHOW_EXPORT"] ?? 'N') == 'Y';

$filter = ['=CRON_ACTIVE' => 'Y'];
if (!$showExport) {
    $filter = ['=CRON_ACTIVE' => 'Y', '=TYPE' => 'import'];
}

$now = new DateTime();
$rows = \Awz\Allxls\Tables\ProfilesTable::getList([
    'select' => [
        'ID', 'NAME', 'TYPE', 'ACTIVE',
        'CRON_RUNNING', 'CRON_START_TIME',
        'CRON_LAST_ERR', 'CRON_HIT_TIME',
        'DATE_LAST_RUN', 'DATE_LAST_END',
        'DATE_NEXT_RUN', 'RUN_COUNT', 'CRON_FALLS',
        'SETTINGS',
    ],
    'filter' => $filter,
    'order' => ['DATE_NEXT_RUN' => 'ASC', 'ID' => 'ASC'],
    'limit' => $count,
])->fetchAll();

function gdAwzAllxlsFmt(\DateInterval $diff): string
{
    return $diff->format('%aд %H:%I:%S');
}

/**
 * Секунды → человекочитаемый интервал (1 ч 05 мин / 4 мин 20 сек / 45 сек)
 */
function gdAwzAllxlsSec(int $sec): string
{
    if ($sec >= 3600) {
        return intdiv($sec, 3600).' ч '.str_pad((string)intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT).' мин';
    }
    if ($sec >= 60) {
        return intdiv($sec, 60).' мин '.($sec % 60).' сек';
    }
    return $sec.' сек';
}

if (!$rows) {
    echo Loc::getMessage('GD_AWZ_ALLXLS_EMPTY');
    return;
}

$green = '#2e7d32';
$red = '#c62828';
$grey = '#8a94a6';
$iconRunning = '<svg width="16" height="16" viewBox="0 0 16 16" style="vertical-align:-3px;"><circle cx="8" cy="8" r="7" fill="'.$green.'"/><path d="M6 4.5v7l5.5-3.5z" fill="#fff"/></svg>';
$iconDone = '<svg width="16" height="16" viewBox="0 0 16 16" style="vertical-align:-3px;"><circle cx="8" cy="8" r="7" fill="'.$grey.'"/><path d="M4.5 8.2l2.4 2.4 4.6-5" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$iconError = '<svg width="16" height="16" viewBox="0 0 16 16" style="vertical-align:-3px;"><circle cx="8" cy="8" r="7" fill="'.$red.'"/><path d="M5.2 5.2l5.6 5.6M10.8 5.2l-5.6 5.6" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>';
$iconWait = '<svg width="16" height="16" viewBox="0 0 16 16" style="vertical-align:-3px;"><circle cx="8" cy="8" r="7" fill="#f0a202"/><path d="M8 4.5V8l2.4 1.6" stroke="#fff" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>';
$iconStalled = '<svg width="16" height="16" viewBox="0 0 16 16" style="vertical-align:-3px;"><circle cx="8" cy="8" r="7" fill="'.$red.'"/><path d="M8 4v4.5" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><circle cx="8" cy="11" r="1" fill="#fff"/></svg>';
$iconImport = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><path d="M8 2v8M4.8 7l3.2 3.2L11.2 7" stroke="#5a6b87" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12.5h10" stroke="#5a6b87" stroke-width="1.6" stroke-linecap="round"/></svg>';
$iconExport = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><path d="M8 11V3M4.8 6.2L8 3l3.2 3.2" stroke="#5a6b87" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12.5h10" stroke="#5a6b87" stroke-width="1.6" stroke-linecap="round"/></svg>';
$iconClock = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><circle cx="8" cy="8" r="6.5" stroke="#5a6b87" stroke-width="1.4" fill="none"/><path d="M8 4.5V8l2.4 1.6" stroke="#5a6b87" stroke-width="1.4" fill="none" stroke-linecap="round"/></svg>';
$iconFlag = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><path d="M4 2.5v11" stroke="#5a6b87" stroke-width="1.5" stroke-linecap="round"/><path d="M4 3h7.5l-2 2.5 2 2.5H4z" fill="#5a6b87"/></svg>';
$iconNext = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><path d="M4 3.5v9l7-4.5z" fill="#5a6b87"/><rect x="11" y="3.5" width="1.8" height="9" fill="#5a6b87"/></svg>';
$iconFile = '<svg width="14" height="14" viewBox="0 0 16 16" style="vertical-align:-2px;"><path d="M4 2h5l3 3v9H4z" fill="none" stroke="#5a6b87" stroke-width="1.3" stroke-linejoin="round"/><path d="M9 2v3h3" fill="none" stroke="#5a6b87" stroke-width="1.3" stroke-linejoin="round"/></svg>';

echo '<style>
.awz-gd-cards{display:flex;flex-wrap:wrap;gap:10px;}
.awz-gd-card{flex:1 1 300px;min-width:280px;max-width:100%;box-sizing:border-box;background:#fff;border:1px solid #e3e6ea;border-radius:8px;box-shadow:0 1px 3px rgba(15,25,40,.08), 0 1px 2px rgba(15,25,40,.05);padding:12px 14px;font-size:13px;line-height:1.45;}
.awz-gd-card--running{border-left:4px solid '.$green.';}
.awz-gd-card--stalled{border-left:4px solid '.$red.';}
.awz-gd-card--error{border-left:4px solid '.$red.';}
.awz-gd-card--ok{border-left:4px solid '.$green.';}
.awz-gd-card--idle{border-left:4px solid #f0a202;}
.awz-gd-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.awz-gd-name{font-weight:bold;font-size:13px;color:#1d2939;text-decoration:none;}
.awz-gd-name:hover{text-decoration:underline;}
.awz-gd-badge{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#5a6b87;background:#eef1f5;border-radius:3px;padding:1px 6px;white-space:nowrap;}
.awz-gd-state{margin-left:auto;font-weight:bold;font-size:12px;white-space:nowrap;display:flex;align-items:center;gap:5px;}
.awz-gd-bar{background:#e9edf2;border-radius:4px;height:10px;overflow:hidden;margin:6px 0 3px;}
.awz-gd-bar-in{height:100%;background:'.$green.';transition:width .4s;}
.awz-gd-bar-in--err{background:'.$red.';}
.awz-gd-row{display:flex;gap:6px;align-items:baseline;margin-top:3px;color:#334155;}
.awz-gd-row .awz-gd-lbl{color:'.$grey.';min-width:86px;flex-shrink:0;}
.awz-gd-err{margin-top:8px;padding:6px 8px;background:#fdecea;border-radius:5px;color:'.$red.';font-size:12px;word-break:break-word;}
.awz-gd-foot{margin-top:8px;padding-top:7px;border-top:1px dashed #e3e6ea;color:'.$grey.';font-size:11px;display:flex;gap:10px;flex-wrap:wrap;}
.awz-gd-cards.awz-gd-cards--hidden > .awz-gd-card--ok, .awz-gd-cards.awz-gd-cards--hidden > .awz-gd-card--idle{display:none;}
.awz-gd-toggle{cursor:pointer;user-select:none;font-size:11px;color:#2067b0;background:#eef1f5;border:1px solid #d7dce3;border-radius:4px;padding:2px 8px;display:inline-flex;align-items:center;gap:4px;}
.awz-gd-toggle:hover{background:#e2e8f0;}
</style>';

echo '<div style="margin-bottom:6px;"><span class="awz-gd-toggle" id="awz-gd-allxls-toggle" title="'.Loc::getMessage('GD_AWZ_ALLXLS_HIDE_DONE_TITLE').'" data-lbl-hide="'.Loc::getMessage('GD_AWZ_ALLXLS_HIDE_DONE').'" data-lbl-show="'.Loc::getMessage('GD_AWZ_ALLXLS_SHOW_DONE').'"></span></div>';
echo '<div class="awz-gd-cards" id="awz-gd-allxls-cards">';

foreach ($rows as $row) {
    $typeParam = $row['TYPE'] == 'export' ? 'export' : 'import';
    $step4Url = '/bitrix/admin/awz_allxls_profiles_edit.php?lang='.LANGUAGE_ID.'&step=4&'.$typeParam.'=Y&ID='.(int)$row['ID'];

    $processKey = $row['TYPE'] == 'export' ? 'PROCESS_EXPORT' : 'PROCESS';
    $process = is_array($row['SETTINGS'][$processKey] ?? null) ? $row['SETTINGS'][$processKey] : [];
    $processed = (int)($process['PROCESSED'] ?? 0);
    $totalRows = 0;
    foreach ((array)($process['SHEET_TOTALS'] ?? []) as $sheetTotal) {
        $totalRows += (int)$sheetTotal;
    }
    $done = ($process['DONE'] ?? 'N') == 'Y';

    // оценка оставшегося времени: по скорости последнего хита (строк/сек),
    // при отсутствии — по средней скорости за весь импорт (STATS_VIEW
    // из dumpLiveStats Pimport/Pexport)
    $statsView = is_array($process['STATS_VIEW'] ?? null) ? $process['STATS_VIEW'] : [];
    $eta = '';
    $remainingRows = max(0, $totalRows - $processed);
    $rps = (float)str_replace(',', '.', (string)($statsView['RPS: последний хит'] ?? 0));
    if ($rps <= 0) $rps = (float)str_replace(',', '.', (string)($statsView['RPS: среднее'] ?? 0));
    if (!$done && $remainingRows > 0 && $rps > 0) {
        $eta = '~'.gdAwzAllxlsSec((int)ceil($remainingRows / $rps))
            .' ('.$remainingRows.' / '.round($rps, 1).' стр/сек)';
    }

    // статус и класс карточки
    $cronLive = !isset($process['DONE']) || $done === false;
    $isRunning = $row['CRON_RUNNING'] == 'Y' || ($cronLive && ($process['SOURCE'] ?? '') == 'cron');
    $stalled = false;
    if ($isRunning && !empty($process['CRON_ALIVE'])) {
        $stalled = ($now->getTimestamp() - (int)$process['CRON_ALIVE'] > 180);
    }
    $hasErr = !empty($row['CRON_LAST_ERR']);

    if ($isRunning && !$stalled) {
        $cardClass = 'running';
        $stateHtml = '<span style="color:'.$green.';">'.$iconRunning.' '.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_RUNNING').'</span>';
    } elseif ($stalled) {
        $cardClass = 'stalled';
        $stateHtml = '<span style="color:'.$red.';">'.$iconStalled.' '.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_STALLED').'</span>';
    } elseif ($hasErr) {
        $cardClass = 'error';
        $stateHtml = '<span style="color:'.$red.';">'.$iconError.' '.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_ERROR').'</span>';
    } elseif ($row['DATE_LAST_END'] instanceof DateTime) {
        $cardClass = 'ok';
        $stateHtml = '<span style="color:'.$green.';">'.$iconDone.' '.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_DONE').'</span>';
    } else {
        $cardClass = 'idle';
        $stateHtml = '<span style="color:#b26a00;">'.$iconWait.' '.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_WAIT').'</span>';
    }

    // прогресс: бар + проценты
    $pct = null;
    $progressHtml = '';
    if (!$done && $totalRows > 0) {
        $pct = min(100, round(100 * $processed / $totalRows));
        $progressHtml = '<div class="awz-gd-bar"><div class="awz-gd-bar-in'.($cardClass === 'stalled' || $cardClass === 'error' ? ' awz-gd-bar-in--err' : '').'" style="width:'.(int)$pct.'%;"></div></div>'
            .'<div style="font-size:11px;color:'.$grey.';">'.$processed.' / '.$totalRows.' — '.(int)$pct.'%</div>';
    } elseif ($done) {
        $donePct = ($totalRows > 0) ? min(100, round(100 * $processed / $totalRows)) : 100;
        $progressHtml = '<div style="font-size:11px;color:'.$green.';">'.Loc::getMessage('GD_AWZ_ALLXLS_PROCESSED').': '.$processed
            .($totalRows > 0 ? ' / '.$totalRows : '').' — '.(int)$donePct.'%</div>';
    } elseif ($processed > 0) {
        $progressHtml = '<div style="font-size:11px;color:'.$grey.';">'.Loc::getMessage('GD_AWZ_ALLXLS_PROCESSED').': '.$processed.'</div>';
    }

    // время работы: текущий прогон (идет) или последний завершенный
    $elapsed = '—';
    $isLiveRun = false;
    $runStart = null;
    if ($row['CRON_RUNNING'] == 'Y' && $row['CRON_START_TIME'] instanceof DateTime) {
        $runStart = $row['CRON_START_TIME'];
    } elseif (isset($process['RUN_STARTED']) && (int)$process['RUN_STARTED'] > 0 && !$done) {
        $runStart = DateTime::createFromTimestamp((int)$process['RUN_STARTED']);
    }
    if ($runStart) {
        $isLiveRun = true;
        $elapsed = gdAwzAllxlsFmt($now->getDiff($runStart));
    } elseif ($row['DATE_LAST_RUN'] instanceof DateTime && $row['DATE_LAST_END'] instanceof DateTime) {
        $elapsed = gdAwzAllxlsFmt($row['DATE_LAST_END']->getDiff($row['DATE_LAST_RUN']));
    }

    $lastEnd = $row['DATE_LAST_END'] instanceof DateTime ? $row['DATE_LAST_END']->format('d.m.Y H:i') : '—';
    $nextRun = $row['DATE_NEXT_RUN'] instanceof DateTime ? $row['DATE_NEXT_RUN']->format('d.m.Y H:i') : '—';
    $filePath = (string)($row['SETTINGS']['FILE_PATH'] ?? '');

    echo '<div class="awz-gd-card awz-gd-card--'.$cardClass.'">';
    echo '<div class="awz-gd-head">';
    echo '<a class="awz-gd-name" href="'.$step4Url.'" target="_blank">'.htmlspecialcharsbx($row['NAME']).'</a>';
    echo '<span class="awz-gd-badge">['.($row['TYPE'] == 'export' ? Loc::getMessage('GD_AWZ_ALLXLS_TYPE_EXPORT') : Loc::getMessage('GD_AWZ_ALLXLS_TYPE_IMPORT')).']</span>';
    if ($row['ACTIVE'] != 'Y') echo '<span class="awz-gd-badge" style="color:'.$red.';background:#fdecea;">'.Loc::getMessage('GD_AWZ_ALLXLS_INACTIVE').'</span>';
    echo '<span class="awz-gd-state">'.$stateHtml.'</span>';
    echo '</div>';

    echo $progressHtml;

    echo '<div class="awz-gd-row"><span class="awz-gd-lbl">'.$iconClock.' '.Loc::getMessage('GD_AWZ_ALLXLS_COL_ELAPSED').'</span><span>'.htmlspecialcharsbx($elapsed)
        .($isLiveRun ? ' <span style="color:'.$grey.';">('.Loc::getMessage('GD_AWZ_ALLXLS_ELAPSED_RUNNING').')</span>' : '').'</span></div>';
    if ($eta !== '') {
        echo '<div class="awz-gd-row"><span class="awz-gd-lbl">'.$iconNext.' '.Loc::getMessage('GD_AWZ_ALLXLS_ETA').'</span><span>'.htmlspecialcharsbx($eta).'</span></div>';
    }
    echo '<div class="awz-gd-row"><span class="awz-gd-lbl">'.$iconNext.' '.Loc::getMessage('GD_AWZ_ALLXLS_COL_NEXT_RUN').'</span><span>'.htmlspecialcharsbx($nextRun).'</span></div>';
    echo '<div class="awz-gd-row"><span class="awz-gd-lbl">'.$iconFlag.' '.Loc::getMessage('GD_AWZ_ALLXLS_COL_LAST_END').'</span><span>'.htmlspecialcharsbx($lastEnd).'</span></div>';
    if ($filePath !== '') {
        echo '<div class="awz-gd-row"><span class="awz-gd-lbl">'.$iconFile.' '.Loc::getMessage('GD_AWZ_ALLXLS_FILE').'</span><span style="word-break:break-all;">'.htmlspecialcharsbx($filePath).'</span></div>';
    }

    if ($hasErr) {
        echo '<div class="awz-gd-err"><b>'.Loc::getMessage('GD_AWZ_ALLXLS_STATUS_ERROR').':</b> '.htmlspecialcharsbx($row['CRON_LAST_ERR']).'</div>';
    }

    echo '<div class="awz-gd-foot">';
    echo '<span>'.Loc::getMessage('GD_AWZ_ALLXLS_COL_RUN_COUNT').': <b style="color:#334155;">'.(int)$row['RUN_COUNT'].'</b></span>';
    if ((int)$row['CRON_FALLS'] > 0) {
        echo '<span style="color:'.$red.';" title="'.Loc::getMessage('GD_AWZ_ALLXLS_CRON_FALLS').'">'.Loc::getMessage('GD_AWZ_ALLXLS_CRON_FALLS').': <b>'.(int)$row['CRON_FALLS'].'</b></span>';
    }
    if ((int)$row['CRON_HIT_TIME'] > 0) {
        echo '<span>'.Loc::getMessage('GD_AWZ_ALLXLS_STEP_TIME').': '.gdAwzAllxlsSec((int)$row['CRON_HIT_TIME']).'</span>';
    }
    echo '</div>';

    echo '</div>';
}

echo '</div>';
echo '<div style="margin-top:8px;"><a href="/bitrix/admin/awz_allxls_profiles_list.php?lang='.LANGUAGE_ID.'">'.Loc::getMessage('GD_AWZ_ALLXLS_ALL_LINK').'</a></div>';
?>
<script>
(function(){
    var KEY = 'awz_gd_allxls_hide_done';
    var wrap = document.getElementById('awz-gd-allxls-toggle');
    var box = document.getElementById('awz-gd-allxls-cards');
    if (!wrap || !box) return;
    var hidden = false;
    try { hidden = localStorage.getItem(KEY) === 'Y'; } catch (e) {}
    function render() {
        box.classList.toggle('awz-gd-cards--hidden', hidden);
        wrap.textContent = hidden ? wrap.getAttribute('data-lbl-show') : wrap.getAttribute('data-lbl-hide');
        try { localStorage.setItem(KEY, hidden ? 'Y' : 'N'); } catch (e) {}
    }
    wrap.addEventListener('click', function(){ hidden = !hidden; render(); });
    render();
})();
</script>
<?php
