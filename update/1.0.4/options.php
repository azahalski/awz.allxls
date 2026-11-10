<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\UI\Extension;
use Awz\Allxls\Access\AccessController;

Loc::loadMessages(__FILE__);
Loc::loadMessages(__DIR__.'/helper/genhook.php');
global $APPLICATION;
$module_id = "awz.allxls";
if(!Loader::includeModule($module_id)) return;
Extension::load('ui.sidepanel-content');
$request = Application::getInstance()->getContext()->getRequest();
$APPLICATION->SetTitle(Loc::getMessage('AWZ_ALLXLS_OPT_TITLE'));

if($request->get('IFRAME_TYPE')==='SIDE_SLIDER'){
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
    require_once('lib/access/include/moduleright.php');
    CMain::finalActions();
    die();
}

if(!AccessController::isViewSettings())
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));

/**
 * Синхронизация js/css из install/ модуля в /bitrix/js|css/awz.allxls
 * (на случай, когда файлы не были скопированы при установке или обновлении)
 */
if(defined('AWZ_ALLXLS_OPT_SYNC_ASSETS') && AWZ_ALLXLS_OPT_SYNC_ASSETS === true){
    CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$module_id."/install/js",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/js/".$module_id,
            true, true
    );
    CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$module_id."/install/css",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/css/".$module_id,
            true, true
    );
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

if ($request->getRequestMethod()==='POST' && AccessController::isEditSettings() && $request->get('Update'))
{
    Option::set($module_id, "ENABLE_JOURNAL_LOG", $request->get("ENABLE_JOURNAL_LOG")=='Y' ? 'Y' : 'N', "");
}

$aTabs = array();

$aTabs[] = array(
    "DIV" => "edit1",
    "TAB" => Loc::getMessage('AWZ_ALLXLS_OPT_SECT1'),
    "ICON" => "vote_settings",
    "TITLE" => Loc::getMessage('AWZ_ALLXLS_OPT_SECT1')
);

$saveUrl = $APPLICATION->GetCurPage(false).'?mid='.htmlspecialcharsbx($module_id).'&lang='.LANGUAGE_ID.'&mid_menu=1';
$tabControl = new CAdminTabControl("tabControl", $aTabs);
$tabControl->Begin();
?>
    <style>.adm-workarea option:checked {background-color: rgb(206, 206, 206);}</style>
    <form method="POST" action="<?=$saveUrl?>" id="FORMACTION">
        <?
        $tabControl->BeginNextTab();
        Extension::load("ui.alerts");
        ?>

        <tr>
            <td style="width:200px;"></td>
            <td>
                <div class="ui-alert ui-alert-primary">
                    <span class="ui-alert-message">
                        <?=Loc::getMessage('AWZ_ALLXLS_OPT_SHOW_DESC')?>
                    </span>
                </div>
            </td>
        </tr>

        <tr>
            <td style="width:200px;"><?=Loc::getMessage('AWZ_ALLXLS_OPT_ENABLE_JOURNAL_LOG_TITLE')?></td>
            <td>
                <?$val = Option::get($module_id, "ENABLE_JOURNAL_LOG", "N", "");?>
                <input type="checkbox" value="Y" name="ENABLE_JOURNAL_LOG" <?if($val=="Y") echo "checked";?> <?if(!AccessController::isEditSettings()) echo "disabled";?>>
                <div class="adm-info-text"><?=Loc::getMessage('AWZ_ALLXLS_OPT_ENABLE_JOURNAL_LOG_DESC')?></div>
            </td>
        </tr>

        <?$genHook = \Awz\Allxls\Helper\GenHook::isBxOrmInstalled();?>
        <tr>
            <td style="width:200px;"><?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_SECT_TITLE')?></td>
            <td>
                <?if($genHook){?>
                    <?
                    $genHookExists = \Awz\Allxls\Helper\GenHook::hasHook();
                    $genHookAdminUrl = '/bitrix/admin/awz_bxorm_hooks_list.php?lang='.LANGUAGE_ID;
                    $genHookMethodsUrl = '/bitrix/admin/awz_bxorm_methods_list.php?lang='.LANGUAGE_ID;
                    $genHookDocUrl = '/bitrix/modules/awz.allxls/README_API_PROFILES.md';
                    ?>
                    <div id="awz_allxls_genhook_result"></div>
                    <div class="ui-alert ui-alert-primary" style="margin-bottom:10px;">
                        <span class="ui-alert-message">
                            <?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_SELL_TEXT')?>
                        </span>
                    </div>
                    <?if($genHookExists){?>
                        <div class="ui-alert ui-alert-success">
                            <span class="ui-alert-message">
                                <?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_EXIST')?>
                                <a href="<?=$genHookAdminUrl?>" target="_blank"><?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_ADMIN_URL')?></a>
                            </span>
                        </div>
                    <?}?>
                    <button id="awz_allxls_genhook_create" class="ui-btn ui-btn-primary <?=($genHookExists?'ui-btn-disabled':'')?>">
                        <?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_BTN_CREATE')?>
                    </button>
                    <button id="awz_allxls_genhook_delete" class="ui-btn ui-btn-light-border <?=(!$genHookExists?'ui-btn-disabled':'')?>">
                        <?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_BTN_DELETE')?>
                    </button>
                    <div style="margin-top:10px;">
                        <a href="<?=$genHookMethodsUrl?>" target="_blank"><?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_LINK_METHODS')?></a>
                        &nbsp;|&nbsp;
                        <a href="<?=$genHookDocUrl?>" target="_blank"><?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_LINK_DOC')?></a>
                    </div>
                    <script>
                        BX.ready(function(){
                            var createBtn = BX('awz_allxls_genhook_create');
                            var deleteBtn = BX('awz_allxls_genhook_delete');
                            var resultNode = BX('awz_allxls_genhook_result');
                            BX.bind(createBtn, 'click', function(){
                                if(BX.hasClass(createBtn, 'ui-btn-disabled')) return;
                                if(!confirm('<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_GENHOOK_JS_CONFIRM_CREATE'))?>')) return;
                                BX.ajax.runAction('awz:allxls.api.genhook.create', {}).then(function(res){
                                    var data = res.data || {};
                                    var msg = '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_GENHOOK_CREATED'))?>';
                                    if(data.TOKEN){ msg += ' <b>'+data.TOKEN+'</b>'; }
                                    BX.adjust(resultNode, {html: '<div class="ui-alert ui-alert-success"><span class="ui-alert-message">'+msg+'</span></div>'});
                                    BX.addClass(createBtn, 'ui-btn-disabled');
                                    BX.removeClass(deleteBtn, 'ui-btn-disabled');
                                }, function(res){
                                    var errs = [];
                                    if(res.errors){ for(var i in res.errors){ errs.push(res.errors[i].message); } }
                                    BX.adjust(resultNode, {html: '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">'+errs.join('<br>')+'</span></div>'});
                                });
                            });
                            BX.bind(deleteBtn, 'click', function(){
                                if(BX.hasClass(deleteBtn, 'ui-btn-disabled')) return;
                                if(!confirm('<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_GENHOOK_JS_CONFIRM_DELETE'))?>')) return;
                                BX.ajax.runAction('awz:allxls.api.genhook.delete', {}).then(function(res){
                                    BX.adjust(resultNode, {html: ''});
                                    BX.removeClass(createBtn, 'ui-btn-disabled');
                                    BX.addClass(deleteBtn, 'ui-btn-disabled');
                                    var alerts = BX.findChildrenByClassName(document, 'ui-alert-success');
                                    if(alerts && alerts.length){ for(var i in alerts){ if(alerts[i].parentNode){ alerts[i].parentNode.removeChild(alerts[i]); } } }
                                }, function(res){
                                    var errs = [];
                                    if(res.errors){ for(var i in res.errors){ errs.push(res.errors[i].message); } }
                                    BX.adjust(resultNode, {html: '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">'+errs.join('<br>')+'</span></div>'});
                                });
                            });
                        });
                    </script>
                <?}else{?>
                    <div class="ui-alert ui-alert-warning">
                        <span class="ui-alert-message">
                            <?=Loc::getMessage('AWZ_ALLXLS_GENHOOK_DESC')?>
                        </span>
                    </div>
                <?}?>
            </td>
        </tr>

        <?if(false){?>
            <tr>
                <td style="width:200px;"><?=Loc::getMessage('AWZ_CURRENCY_OPT_LOAD_NBRB_TITLE')?></td>
                <td>
                    <?$val = Option::get($module_id, "LOAD_NBRB", "N","");?>
                    <input type="checkbox" value="Y" name="LOAD_NBRB" <?if ($val=="Y") echo "checked";?>></td>
                </td>
            </tr>
        <?}?>
        <?
        $tabControl->Buttons();
        ?>
        <input <?if (!AccessController::isEditSettings()) echo "disabled" ?> type="submit" class="adm-btn-green" name="Update" value="<?=Loc::getMessage('AWZ_ALLXLS_OPT_L_BTN_SAVE')?>" />
        <input type="hidden" name="Update" value="Y" />
        <?if(AccessController::isViewRight()){?>
            <button class="adm-header-btn adm-security-btn" onclick="BX.SidePanel.Instance.open('<?=$saveUrl?>');return false;">
                <?=Loc::getMessage('AWZ_ALLXLS_OPT_SECT2')?>
            </button>
        <?}?>
        <?$tabControl->End();?>
    </form>
<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");