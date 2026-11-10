<?php

namespace Awz\Allxls\Tables\AdminPages;

use Awz\Allxls\Access\AccessController;
use Awz\Allxls\Access\Custom\ActionDictionary;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Awz\Admin\IList;
use Awz\Admin\IParams;
use Awz\Admin\Helper;
use Awz\Allxls\Tables\ProfilesTable;

Loc::loadMessages(__FILE__);

class ProfilesList extends IList implements IParams {

    public $type_profile = '';

    public static $sections = [];

    public static $lastId;

    public function __construct($params){
        global $POST_RIGHT;
        parent::__construct($params);
        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $filter = [];
        if($request->get('export')=='Y'){
            $this->type_profile = 'export';
            $filter['=TYPE'] = 'export';
            if(!AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)){
                $this->getAdminList()->arGroupErrors[] = ["Нет прав на просмотр профилей экспорта"];
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT) ||
                AccessController::can(0,ActionDictionary::ACTION_EXPORT_DELETE)
            ){
                $POST_RIGHT = 'W';
            }
        }else{
            $this->type_profile = 'import';
            $filter['=TYPE'] = 'import';
            if(!AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)){
                $this->getAdminList()->arGroupErrors[] = ["Нет прав на просмотр профилей импорта"];
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT) ||
                AccessController::can(0,ActionDictionary::ACTION_IMPORT_DELETE)
            ){
                $POST_RIGHT = 'W';
            }
        }
        $this->addFilter($filter);
    }

    public function trigerGetRowListAdmin($row){
        Helper::viewListField($row, 'ID', ['type'=>'entity_link'], $this);
        $row->AddViewField('NAME', $this->getNameCellHtml($row->arRes));
        Helper::editListField($row, 'NAME', ['type'=>'string'], $this);
        Helper::editListField($row, 'DESC', ['type'=>'string'], $this);
        Helper::editListField($row, 'ACTIVE', ['type'=>'checkbox'], $this);
        $row->AddSelectField('SECTION_ID', self::$sections, []);
        $row->AddViewField('CRON_SCHEDULE', $this->getScheduleCellHtml($row->arRes));
        self::$lastId = $row->arRes['ID'];
    }

    /**
     * Доп. поля в выборку (SETTINGS и cron-поля нужны для HTML в колонках NAME/CRON_SCHEDULE)
     */
    public function getUserQuery(){
        $query = parent::getUserQuery();
        $select = $query->getSelect();
        $need = [
            'TYPE', 'ACTIVE', 'SETTINGS', 'RUN_COUNT',
            'DATE_LAST_RUN', 'DATE_LAST_END', 'DATE_NEXT_RUN',
            'CRON_ACTIVE', 'CRON_SCHEDULE', 'CRON_HIT_TIME', 'CRON_PAUSE',
            'CRON_RUNNING', 'CRON_START_TIME', 'CRON_LAST_ERR', 'CRON_FALLS',
        ];
        foreach($need as $code){
            if(!in_array($code, $select)) $select[] = $code;
        }
        $query->setSelect($select);
        return $query;
    }

    /**
     * Название + кнопка запуска возле него
     */
    private function getNameCellHtml(array $res): string
    {
        $primaryCode = $this->getParam('PRIMARY', 'ID');
        $url = $this->getParam("FILE_EDIT");
        if(mb_strpos($url, '?')!==false){
            $url .= '&';
        }else{
            $url .= '?';
        }
        $url .= 'lang='.LANG.'&'.$primaryCode.'='.$res[$primaryCode];

        $html = '<a href="'.$url.'" title="'.htmlspecialcharsbx($res['NAME'] ?? '').'">'
            .htmlspecialcharsbx($res['NAME'] ?? '').'</a>';

        $type = ($res['TYPE'] ?? '') == 'export' ? 'export' : 'import';
        $actionCode = $type == 'export'
            ? ActionDictionary::ACTION_EXPORT_LOAD
            : ActionDictionary::ACTION_IMPORT_LOAD;
        if(AccessController::can(0, $actionCode)){
            $runUrl = $url.'&'.$type.'=Y&step=4';
            $html .= '<br><br> <a class="adm-btn" style="font-size:11px;padding:0 6px;line-height:21px;'
                .'vertical-align:middle;text-decoration:none;margin:3px 6px 3px 0;display:inline-block;" title="Запустить прогон" href="'.$runUrl.'">'
                .'&#9654; К запуску</a>';
        }
        // переход к настройкам (шаг 3): сопоставление колонок (импорт) / настройка выгрузки (экспорт)
        $editCode = $type == 'export'
            ? ActionDictionary::ACTION_EXPORT_EDIT
            : ActionDictionary::ACTION_IMPORT_EDIT;
        if(AccessController::can(0, $editCode)){
            $settingsUrl = $url.'&'.$type.'=Y&step=3';
            $html .= ' <a class="adm-btn" style="font-size:11px;padding:0 6px;line-height:21px;'
                .'vertical-align:middle;text-decoration:none;margin:3px 6px 3px 0;display:inline-block;" title="Настройки колонок (шаг 3)" href="'.$settingsUrl.'">'
                .'&#9881; Настроить</a>';
        }
        return $html;
    }

    /**
     * Детали прогона в колонке расписания — как в виджете «активные прогоны»
     */
    private function getScheduleCellHtml(array $res): string
    {
        $type = ($res['TYPE'] ?? '') == 'export' ? 'export' : 'import';
        $settings = $this->normalizeSettings($res['SETTINGS'] ?? []);
        $processKey = $type == 'export' ? 'PROCESS_EXPORT' : 'PROCESS';
        $process = is_array($settings[$processKey] ?? null) ? $settings[$processKey] : [];

        $green = '#2e7d32';
        $red = '#c62828';
        $grey = '#8a94a6';
        $orange = '#b26a00';

        $now = new DateTime();
        $processed = (int)($process['PROCESSED'] ?? 0);
        $totalRows = 0;
        foreach((array)($process['SHEET_TOTALS'] ?? []) as $sheetTotal){
            $totalRows += (int)$sheetTotal;
        }
        $done = ($process['DONE'] ?? 'N') == 'Y';

        // статус
        $cronLive = !isset($process['DONE']) || $done === false;
        $isRunning = $res['CRON_RUNNING'] == 'Y' || ($cronLive && ($process['SOURCE'] ?? '') == 'cron');
        $stalled = false;
        if($isRunning && !empty($process['CRON_ALIVE'])){
            $stalled = ($now->getTimestamp() - (int)$process['CRON_ALIVE'] > 180);
        }
        $hasErr = !empty($res['CRON_LAST_ERR']);

        if($isRunning && !$stalled){
            $status = '<span style="color:'.$green.';font-weight:bold;">&#9654; Выполняется</span>';
        }elseif($stalled){
            $status = '<span style="color:'.$red.';font-weight:bold;" title="Нет обновлений более 3 мин">Завис</span>';
        }elseif($hasErr){
            $status = '<span style="color:'.$red.';font-weight:bold;">Ошибка</span>';
        }elseif($res['DATE_LAST_END'] instanceof DateTime){
            $status = '<span style="color:'.$green.';">Завершен</span>';
        }elseif($res['CRON_ACTIVE'] == 'Y'){
            $status = '<span style="color:'.$orange.';">Ожидает запуска</span>';
        }else{
            $status = '<span style="color:'.$grey.';">Не запускался</span>';
        }

        // прогресс
        $pct = null;
        if(!$done && $totalRows > 0){
            $pct = min(100, round(100 * $processed / $totalRows));
        }elseif($done){
            $pct = ($totalRows > 0) ? min(100, round(100 * $processed / $totalRows)) : 100;
        }

        // время работы
        $elapsed = '—';
        $runStart = null;
        if($res['CRON_RUNNING'] == 'Y' && $res['CRON_START_TIME'] instanceof DateTime){
            $runStart = $res['CRON_START_TIME'];
        }elseif(isset($process['RUN_STARTED']) && (int)$process['RUN_STARTED'] > 0 && !$done){
            $runStart = DateTime::createFromTimestamp((int)$process['RUN_STARTED']);
        }
        if($runStart){
            $elapsed = $now->getDiff($runStart)->format('%aд %H:%I:%S');
        }elseif($res['DATE_LAST_RUN'] instanceof DateTime && $res['DATE_LAST_END'] instanceof DateTime){
            $elapsed = $res['DATE_LAST_END']->getDiff($res['DATE_LAST_RUN'])->format('%aд %H:%I:%S');
        }

        $nextRun = $res['DATE_NEXT_RUN'] instanceof DateTime ? $res['DATE_NEXT_RUN']->format('d.m.Y H:i') : '—';
        $lastEnd = $res['DATE_LAST_END'] instanceof DateTime ? $res['DATE_LAST_END']->format('d.m.Y H:i') : '—';
        $filePath = (string)($settings['FILE_PATH'] ?? '');

        $lbl = 'color:'.$grey.';min-width:86px;display:inline-block;flex-shrink:0;';
        $html = '';

        if($res['CRON_ACTIVE'] == 'Y' && !empty($res['CRON_SCHEDULE'])){
            $html .= '<span style="font-weight:bold;" title="Расписание cron (5 полей)">'
                .htmlspecialcharsbx($res['CRON_SCHEDULE']).'</span>'
                .' <span style="color:'.$grey.';">(cron)</span>';
        }elseif(!empty($res['CRON_SCHEDULE'])){
            $html .= '<span style="color:'.$grey.';" title="Расписание cron (выключено)">'
                .htmlspecialcharsbx($res['CRON_SCHEDULE']).' (cron выключен)</span>';
        }else{
            $html .= '<span style="color:'.$grey.';">Расписание не задано</span>';
        }

        $rowsHtml = '';
        $rowsHtml .= '<div style="display:flex;gap:6px;margin-top:4px;"><span style="'.$lbl.'">Статус</span><span>'.$status.'</span></div>';
        if($pct !== null || $processed > 0){
            $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Обработано</span><span>'
                .$processed.($totalRows > 0 ? ' / '.$totalRows : '').($pct !== null ? ' — '.(int)$pct.'%' : '').'</span></div>';
        }
        $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Время работы</span><span>'.htmlspecialcharsbx($elapsed).'</span></div>';
        $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Сл. запуск</span><span>'.$nextRun.'</span></div>';
        $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Завершен</span><span>'.$lastEnd.'</span></div>';
        $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Запусков</span><span>'.(int)($res['RUN_COUNT'] ?? 0).'</span></div>';
        if((int)($res['CRON_FALLS'] ?? 0) > 0){
            $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Падений</span><span style="color:'.$red.';">'
                .(int)$res['CRON_FALLS'].'</span></div>';
        }
        if((int)($res['CRON_HIT_TIME'] ?? 0) > 0){
            $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Шаг</span><span>'
                .self::formatSec((int)$res['CRON_HIT_TIME']).'</span></div>';
        }
        if((int)($res['CRON_PAUSE'] ?? 0) > 0){
            $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Пауза</span><span>'
                .self::formatSec((int)$res['CRON_PAUSE']).'</span></div>';
        }
        if($filePath !== ''){
            $rowsHtml .= '<div style="display:flex;gap:6px;"><span style="'.$lbl.'">Файл</span><span style="word-break:break-all;">'
                .htmlspecialcharsbx($filePath).'</span></div>';
        }
        if($hasErr){
            $rowsHtml .= '<div style="margin-top:4px;padding:4px 6px;background:#fdecea;border-radius:4px;color:'.$red.';font-size:11px;word-break:break-word;">'
                .'<b>Ошибка:</b> '.htmlspecialcharsbx($res['CRON_LAST_ERR']).'</div>';
        }

        $html .= '<div style="font-size:12px;line-height:1.45;color:#334155;">'.$rowsHtml.'</div>';
        return $html;
    }

    /**
     * SETTINGS может прийти как массив (ArrayField) или сырой строкой
     */
    private function normalizeSettings($value): array
    {
        if(is_array($value)) return $value;
        if(!is_string($value) || $value === '') return [];
        $decrypted = base64_decode($value, true);
        if($decrypted === false) $decrypted = $value;
        $json = @gzuncompress($decrypted);
        if($json === false) $json = $decrypted;
        try{
            $data = \Bitrix\Main\Web\Json::decode($json);
            return is_array($data) ? $data : [];
        }catch(\Exception $e){
            return [];
        }
    }

    private static function formatSec(int $sec): string
    {
        if($sec >= 3600){
            return intdiv($sec, 3600).' ч '.str_pad((string)intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT).' мин';
        }
        if($sec >= 60){
            return intdiv($sec, 60).' мин '.($sec % 60).' сек';
        }
        return $sec.' сек';
    }

    public function trigerInitFilter(){
        if($this->type_profile == 'export' && !AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)){
            $this->filter['<ID'] = 0;
        }
        if($this->type_profile == 'import' && !AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)){
            $this->filter['<ID'] = 0;
        }
        if($this->type_profile == 'export'){
            $this->filter['=TYPE'] = 'export';
        }
        if($this->type_profile == 'import'){
            $this->filter['!TYPE'] = 'export';
        }
    }

    public function trigerGetRowListActions(array $actions): array
    {
        $editFile = $this->getParam("FILE_EDIT").'?'.$this->getParam("PRIMARY").'='.self::$lastId.'&lang='.LANG;
        if(
            ($this->type_profile == 'export' && AccessController::can(0,ActionDictionary::ACTION_EXPORT_VIEW)
            && !AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT))
            ||
            ($this->type_profile == 'import' && AccessController::can(0,ActionDictionary::ACTION_IMPORT_VIEW)
                && !AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT))
        ){
            $actions[] = array(
                "ICON"=>"view",
                "DEFAULT"=>true,
                "TEXT"=>'Посмотреть',
                "TITLE"=>'Посмотреть',
                "ACTION"=>$this->getAdminList()->ActionRedirect($editFile)
            );
        }
        if(
            ($this->type_profile == 'export' && AccessController::can(0,ActionDictionary::ACTION_EXPORT_LOAD))
            ||
            ($this->type_profile == 'import' && AccessController::can(0,ActionDictionary::ACTION_IMPORT_LOAD))
        ){
            $actions[] = array(
                "ICON"=>"view",
                "DEFAULT"=>true,
                "TEXT"=>'К запуску',
                "TITLE"=>'Запустить',
                "ACTION"=>$this->getAdminList()->ActionRedirect($editFile.'&'.$this->type_profile.'=Y&step=4')
            );
        }
        return $actions;
    }

    public static function getTitle(): string
    {
        return Loc::getMessage('AWZ_ALLXLS_S_PROFILES_LIST_TITLE');
    }

    public static function getParams(): array
    {

        $sections = ['0'=>'Без раздела'];
        if(AccessController::can(0,ActionDictionary::ACTION_PROFILE_SECTION_VIEW)){
            $r = \Awz\Allxls\Tables\SectionsTable::getList(['select'=>['ID','NAME']]);
            while($data = $r->fetch()){
                $sections[$data['ID']] = $data['NAME'];
            }
        }
        self::$sections = $sections;

        $request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
        $btn = [];
        $ADD_GROUP_ACTIONS = [];
        $ADD_LIST_ACTIONS = [];

        if($request->get('export')=='Y'){
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_EDIT)){
                $ADD_GROUP_ACTIONS[] = 'edit';
                $ADD_LIST_ACTIONS[] = 'edit';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_DELETE)){
                $ADD_GROUP_ACTIONS[] = 'delete';
                $ADD_LIST_ACTIONS[] = 'delete';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_EXPORT_ADD)){
                $btn['add'] = [
                    'TEXT' => "Новый профиль экспорта",
                    'ICON' => 'btn_new',
                    'LINK' => 'awz_allxls_profiles_edit.php'.'?lang='.LANG.'&export=Y',
                ];
            }
        }else{
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_EDIT)){
                $ADD_GROUP_ACTIONS[] = 'edit';
                $ADD_LIST_ACTIONS[] = 'edit';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_DELETE)){
                $ADD_GROUP_ACTIONS[] = 'delete';
                $ADD_LIST_ACTIONS[] = 'delete';
            }
            if(AccessController::can(0,ActionDictionary::ACTION_IMPORT_ADD)){
                $btn['add'] = [
                    'TEXT' => "Новый профиль импорта",
                    'ICON' => 'btn_new',
                    'LINK' => 'awz_allxls_profiles_edit.php'.'?lang='.LANG.'&import=Y',
                ];
            }
        }

        $arParams = array(
            "PRIMARY"=>'ID',
            "ENTITY" => "\\Awz\\Allxls\\Tables\\ProfilesTable",
            "FILE_EDIT"=>"awz_allxls_profiles_edit.php",
            "BUTTON_CONTEXTS"=>$btn,
            "ADD_GROUP_ACTIONS"=>$ADD_GROUP_ACTIONS,
            "ADD_LIST_ACTIONS"=>$ADD_LIST_ACTIONS,
            "FIND"=>array(
                'SECTION_ID'=>[
                    'id'=>'SECTION_ID',
                    'realId'=>'SECTION_ID',
                    'name'=>'Раздел',
                    'type'=>'list',
                    "items" => $sections ?? [],
                    "params"=>["multiple"=>"Y"]
                ]
            ),
            "FIND_FROM_ENTITY"=>array(
                'NAME'=>[],
                'ACTIVE'=>[],
            ),
        );
        return $arParams;
    }
}