/**
 * AWZ.AllXLS: шаг 3 (экспорт) — настройка колонок выгрузки по листам.
 *
 * Использование:
 *   var ob = new Awz.Allxls.ProfilesEditExportSettings({id: 1, ...});
 *   ob.init();
 *
 * Структура листа экспорта:
 *   {NAME: 'имя листа', COLUMNS: [{
 *       NAME: 'заголовок колонки', FIELD: 'NAME'|'PROPERTY_CODE',
 *       ACTIVE: 'Y'|'N',
 *       TRIM: 'Y'|'N', CASE: ''|'lower'|'upper'|'firstupper',
 *       DELIMETER: ', ',
 *       RULES: [{TYPE:'skip', AGG:'AND', CONDITIONS:[...]}]  // формат правил как в импорте
 *   }]}
 * По умолчанию создается 5 пустых строк колонок; при выборе поля в селекте
 * заголовок колонки слева заполняется названием поля.
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var DEFAULT_ROWS = 5;

    var ICON_GEAR = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + '<circle cx="12" cy="12" r="3"/>'
        + '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06'
        + 'a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4'
        + 'a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82'
        + ' 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82'
        + 'l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3'
        + 'a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0'
        + ' 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2'
        + 'h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
    var ICON_TRASH = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        + '<polyline points="3 6 5 6 21 6"/>'
        + '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
        + '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>';

    var ExportSettings = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.loadAction = options.loadAction || 'awz:allxls.api.pexport.settings';
        this.saveAction = options.saveAction || 'awz:allxls.api.pexport.savesettings';
        this.colSettingsAction = options.colSettingsAction || 'awz:allxls.api.pexport.colsettings';
        this.containerId = options.containerId || 'awz_allxls_profile_export_container';
        this.loaderId = options.loaderId || 'awz_allxls_profile_export_loader';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_export_save';
        this.saveBtnNextId = options.saveBtnNextId || 'awz_allxls_profile_export_save_next';
        // CSV: лист может быть только один
        this.singleSheet = !!options.singleSheet;

        this.groups = [];   // группы полей для селектов
        this.sheets = [];   // текущее состояние листов
    };

    ExportSettings.prototype = {
        init: function () {
            var self = this;
            var saveBtn = document.getElementById(this.saveBtnId);
            if (saveBtn) {
                BX.bind(saveBtn, 'click', function (e) { BX.PreventDefault(e); self.save(false); });
            }
            var nextBtn = document.getElementById(this.saveBtnNextId);
            if (nextBtn) {
                BX.bind(nextBtn, 'click', function (e) { BX.PreventDefault(e); self.save(true); });
            }
            this.load();
        },

        load: function () {
            var self = this;
            BX.ajax.runAction(this.loadAction, {data: {id: this.id}}).then(function (response) {
                var data = response.data || {};
                self.groups = data.groups || [];
                self.sheets = self.normalizeSheets(data.sheets || []);
                var loader = document.getElementById(self.loaderId);
                if (loader) loader.style.display = 'none';
                self.render();
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_EXPORT_SETTINGS_LOAD_ERROR')), true);
            });
        },

        normalizeSheets: function (sheets) {
            var self = this;
            var result = [];
            var list = Array.isArray(sheets) ? sheets : Object.values(sheets || {});
            list.forEach(function (sheet) {
                if (!sheet || typeof sheet !== 'object') return;
                var columns = [];
                (sheet.COLUMNS || []).forEach(function (col) {
                    if (!col || typeof col !== 'object') return;
                    if (!col.FIELD) return;
                    columns.push(self.normalizeColumn(col));
                });
                result.push({NAME: sheet.NAME || '', COLUMNS: columns});
            });
            if (!result.length) {
                result.push({NAME: 'SHEET1', COLUMNS: this.emptyColumns()});
            }
            // CSV: оставляем только первый лист
            if (this.singleSheet && result.length > 1) {
                result = [result[0]];
            }
            return result;
        },

        emptyColumns: function () {
            var rows = [];
            for (var i = 0; i < DEFAULT_ROWS; i++) {
                rows.push(this.normalizeColumn({}));
            }
            return rows;
        },

        /**
         * Нормализация настроек колонки: NAME, FIELD + опции обработки
         * (ACTIVE, TRIM, CASE, DELIMETER, RULES).
         */
        normalizeColumn: function (col) {
            col = col || {};
            var rules = [];
            (col.RULES || []).forEach(function (rule) {
                if (!rule || typeof rule !== 'object') return;
                // старый формат {ACTION: 'skip_row'}
                if (!rule.TYPE && rule.ACTION === 'skip_row') {
                    rules.push({TYPE: 'skip', AGG: 'AND', CONDITIONS: [{OP: 'empty', VALUE: '', VALUE2: ''}]});
                    return;
                }
                if (!rule.TYPE) return;
                rules.push({
                    TYPE: rule.TYPE,
                    AGG: rule.AGG || 'AND',
                    CONDITIONS: Array.isArray(rule.CONDITIONS) ? rule.CONDITIONS : [],
                    ACTION: rule.ACTION || '',
                    ACTION_VALUE: rule.ACTION_VALUE || '',
                    ACTION_VALUE2: rule.ACTION_VALUE2 || ''
                });
            });
            return {
                NAME: col.NAME || '',
                FIELD: col.FIELD || '',
                ACTIVE: col.ACTIVE === 'N' ? 'N' : 'Y',
                TRIM: col.TRIM === 'Y' ? 'Y' : 'N',
                CASE: ['lower', 'upper', 'firstupper'].indexOf(col.CASE) >= 0 ? col.CASE : '',
                DELIMETER: typeof col.DELIMETER === 'string' && col.DELIMETER !== '' ? col.DELIMETER : ', ',
                RULES: rules
            };
        },

        /**
         * Есть ли у колонки нестандартные настройки (для подсветки шестеренки).
         */
        columnHasOptions: function (col) {
            return col.TRIM === 'Y' || !!col.CASE || (col.DELIMETER !== ', ') || !!(col.RULES && col.RULES.length);
        },

        /**
         * Плоский список полей: [{ID, NAME}] из групп.
         */
        flatFields: function () {
            var fields = [];
            this.groups.forEach(function (group) {
                (group.ITEMS || []).forEach(function (item) {
                    fields.push({ID: item.ID, NAME: item.NAME, GROUP: group.NAME});
                });
            });
            return fields;
        },

        fieldTitle: function (fieldId) {
            var found = '';
            this.flatFields().forEach(function (f) {
                if (f.ID === fieldId) found = f.NAME;
            });
            return found;
        },

        render: function () {
            var container = document.getElementById(this.containerId);
            if (!container) return;
            var self = this;
            var html = '';

            this.sheets.forEach(function (sheet, sheetIdx) {
                html += '<div class="awz-allxls-card" style="margin-bottom:16px;">';
                html += '<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #e0e5ea;">';
                html += '<b>' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_SHEET')) + ' ' + (sheetIdx + 1) + ':</b>';
                html += '<input type="text" class="awz-export-sheet-name" data-sheet="' + sheetIdx + '" value="'
                    + BX.util.htmlspecialchars(sheet.NAME) + '" style="width:220px;">';
                html += '<button type="button" class="awz-allxls-btn awz-allxls-btn--ghost awz-export-add-col" data-sheet="' + sheetIdx + '">'
                    + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_ADD_COL')) + '</button>';
                if (self.sheets.length > 1 && !self.singleSheet) {
                    html += '<button type="button" class="awz-allxls-btn awz-allxls-btn--ghost awz-export-del-sheet" data-sheet="' + sheetIdx + '">'
                        + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_DEL_SHEET')) + '</button>';
                }
                html += '</div>';

                html += '<table style="width:100%;border-collapse:collapse;">';
                html += '<tr style="background:#e8edf3;">'
                    + '<th style="padding:6px 10px;border:1px solid #e0e5ea;text-align:left;width:70px;">#</th>'
                    + '<th style="padding:6px 10px;border:1px solid #e0e5ea;text-align:left;">'
                    + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_NAME')) + '</th>'
                    + '<th style="padding:6px 10px;border:1px solid #e0e5ea;text-align:left;">'
                    + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_FIELD')) + '</th>'
                    + '<th style="padding:6px 10px;border:1px solid #e0e5ea;width:40px;"></th>'
                    + '<th style="padding:6px 10px;border:1px solid #e0e5ea;width:40px;"></th>'
                    + '</tr>';

                (sheet.COLUMNS || []).forEach(function (col, colIdx) {
                    var hasOpts = self.columnHasOptions(col);
                    var inactive = col.ACTIVE === 'N';
                    html += '<tr data-sheet="' + sheetIdx + '" data-col="' + colIdx + '"'
                        + (inactive ? ' style="opacity:.5;"' : '') + '>'
                        + '<td style="padding:4px 10px;border:1px solid #e0e5ea;white-space:nowrap;">'
                        + '<span class="awz-export-col-drag" data-sheet="' + sheetIdx + '" data-col="' + colIdx
                        + '" title="' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_MOVE'))
                        + '">&#8942;&#8942;</span>'
                        + '<input type="checkbox" class="awz-export-col-active" data-sheet="' + sheetIdx + '" data-col="' + colIdx
                        + '" title="' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_ACTIVE'))
                        + '"' + (inactive ? '' : ' checked') + ' style="vertical-align:middle;"> '
                        + (colIdx + 1) + '</td>'
                        + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">'
                        + '<input type="text" class="awz-export-col-name" data-sheet="' + sheetIdx + '" data-col="' + colIdx
                        + '" value="' + BX.util.htmlspecialchars(col.NAME) + '" style="width:95%;"></td>'
                        + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">'
                        + self.renderFieldSelect(sheetIdx, colIdx, col.FIELD) + '</td>'
                        + '<td style="padding:4px 10px;border:1px solid #e0e5ea;text-align:center;">'
                        + '<button type="button" class="awz-export-icon awz-export-col-gear'
                        + (hasOpts ? ' awz-export-icon--active' : '')
                        + '" data-sheet="' + sheetIdx + '" data-col="' + colIdx + '" title="'
                        + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS'))
                        + '">' + ICON_GEAR + '</button></td>'
                        + '<td style="padding:4px 10px;border:1px solid #e0e5ea;text-align:center;">'
                        + '<button type="button" class="awz-export-icon awz-export-icon--del awz-export-del-col"'
                        + ' data-sheet="' + sheetIdx + '" data-col="' + colIdx + '" title="'
                        + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_DEL_COL'))
                        + '">' + ICON_TRASH + '</button></td>'
                        + '</tr>';
                });

                html += '</table></div>';
            });

            if (!this.singleSheet) {
                html += '<button type="button" class="awz-allxls-btn awz-allxls-btn--ghost awz-export-add-sheet"'
                    + ' id="awz_export_add_sheet">'
                    + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_ADD_SHEET')) + '</button>';
            }

            container.innerHTML = html;
            // обработчики (BX.bindDelegate на контейнере) регистрируются один раз —
            // повторный bind накапливал обработчики и вызывал «глюки» добавления/удаления
            this.bindEvents();
        },

        renderFieldSelect: function (sheetIdx, colIdx, selected) {
            var html = '<select class="awz-export-col-field" data-sheet="' + sheetIdx + '" data-col="' + colIdx + '" style="width:95%;">';
            html += '<option value="">' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_FIELD_EMPTY')) + '</option>';
            this.groups.forEach(function (group) {
                html += '<optgroup label="' + BX.util.htmlspecialchars(group.NAME || '') + '">';
                (group.ITEMS || []).forEach(function (item) {
                    html += '<option value="' + BX.util.htmlspecialchars(item.ID) + '"'
                        + (item.ID === selected ? ' selected' : '') + '>'
                        + BX.util.htmlspecialchars(item.NAME) + '</option>';
                });
                html += '</optgroup>';
            });
            html += '</select>';
            return html;
        },

        bindEvents: function () {
            // одноразовая привязка: все обработчики — BX.bindDelegate на
            // контейнере, они выживают после innerHTML в render();
            // повторный вызов (каждый render) накапливал обработчики
            if (this.eventsBound) return;
            this.eventsBound = true;
            var self = this;
            var container = document.getElementById(this.containerId);
            if (!container) return;

            BX.bindDelegate(container, 'click', {className: 'awz-export-add-col'}, function (e) {
                var idx = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                self.sheets[idx].COLUMNS.push(self.normalizeColumn({}));
                self.render();
            });
            BX.bindDelegate(container, 'click', {className: 'awz-export-del-col'}, function (e) {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self.sheets[s].COLUMNS.splice(c, 1);
                self.render();
            });
            BX.bindDelegate(container, 'click', {className: 'awz-export-del-sheet'}, function (e) {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                self.sheets.splice(s, 1);
                self.render();
            });
            // шестеренка настроек колонки
            BX.bindDelegate(container, 'click', {className: 'awz-export-col-gear'}, function (e) {
                BX.PreventDefault(e);
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self.openColumnSettings(s, c);
            });
            // чекбокс активности колонки
            BX.bindDelegate(container, 'change', {className: 'awz-export-col-active'}, function () {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self.sheets[s].COLUMNS[c].ACTIVE = this.checked ? 'Y' : 'N';
                var tr = this.closest ? this.closest('tr') : null;
                if (tr) tr.style.opacity = this.checked ? '' : '.5';
            });
            // добавить лист (делегированно — кнопка пересоздается при render)
            if (!this.singleSheet) {
                BX.bindDelegate(container, 'click', {className: 'awz-export-add-sheet'}, function (e) {
                    BX.PreventDefault(e);
                    self.sheets.push({NAME: 'SHEET' + (self.sheets.length + 1), COLUMNS: self.emptyColumns()});
                    self.render();
                });
            }
            // имя листа
            BX.bindDelegate(container, 'change', {className: 'awz-export-sheet-name'}, function () {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                self.sheets[s].NAME = this.value;
            });
            // заголовок колонки
            BX.bindDelegate(container, 'change', {className: 'awz-export-col-name'}, function () {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self.sheets[s].COLUMNS[c].NAME = this.value;
            });
            // выбор поля: авто-заполнение заголовка колонки названием поля
            BX.bindDelegate(container, 'change', {className: 'awz-export-col-field'}, function () {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self.sheets[s].COLUMNS[c].FIELD = this.value;
                if (this.value) {
                    var title = self.fieldTitle(this.value);
                    if (title && !self.sheets[s].COLUMNS[c].NAME) {
                        self.sheets[s].COLUMNS[c].NAME = title;
                        var nameInput = container.querySelector(
                            '.awz-export-col-name[data-sheet="' + s + '"][data-col="' + c + '"]'
                        );
                        if (nameInput) nameInput.value = title;
                    }
                }
            });

            // перемещение колонок за иконку (HTML5 drag&drop)
            BX.bindDelegate(container, 'mousedown', {className: 'awz-export-col-drag'}, function (e) {
                var tr = this.closest ? this.closest('tr') : null;
                if (tr) tr.setAttribute('draggable', 'true');
            });
            BX.bindDelegate(container, 'mouseup', {className: 'awz-export-col-drag'}, function (e) {
                var tr = this.closest ? this.closest('tr') : null;
                if (tr) tr.removeAttribute('draggable');
            });
            BX.bindDelegate(container, 'dragstart', {tagName: 'tr'}, function (e) {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                self._dragCol = {sheet: s, col: c};
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', s + ':' + c); } catch (err) {}
                }
            });
            BX.bindDelegate(container, 'dragover', {tagName: 'tr'}, function (e) {
                if (self._dragCol) {
                    e.preventDefault();
                    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                }
            });
            BX.bindDelegate(container, 'drop', {tagName: 'tr'}, function (e) {
                if (!self._dragCol) return;
                BX.PreventDefault(e);
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                var from = self._dragCol;
                self._dragCol = null;
                if (from.sheet !== s || from.col === c) return;
                var cols = self.sheets[s].COLUMNS;
                if (from.col < 0 || from.col >= cols.length || c < 0 || c >= cols.length) return;
                var moved = cols.splice(from.col, 1)[0];
                cols.splice(c, 0, moved);
                self.render();
            });
            BX.bindDelegate(container, 'dragend', {tagName: 'tr'}, function () {
                self._dragCol = null;
                this.removeAttribute('draggable');
            });
        },

        /**
         * Окно настроек колонки (шестеренка) — как в импорте:
         * BX.PopupWindow с заголовком, ширина 900, кнопки ui-btn.
         * HTML загружается с контроллера awz:allxls.api.pexport.colsettings,
         * правила рендерятся конструктором BX.TreeConditions (миксин mapdialog).
         */
        openColumnSettings: function (sheetIdx, colIdx) {
            var self = this;
            var col = this.sheets[sheetIdx].COLUMNS[colIdx];
            var popupId = 'awz-allxls-col-settings-' + this.id + '-' + sheetIdx + '-' + colIdx + '-' + Math.floor(Math.random() * 1e5);

            var content = document.createElement('div');
            content.innerHTML = '<div style="padding:30px;text-align:center;color:#8a94a6;">Загрузка...</div>';

            var popup = new BX.PopupWindow(popupId, null, {
                content: content,
                titleBar: BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS') || 'Настройки колонки',
                width: 900,
                overlay: {opacity: 40},
                draggable: true,
                closeByEsc: true,
                buttons: [
                    new BX.PopupWindowButton({
                        text: BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS_SAVE') || 'Сохранить',
                        className: 'ui-btn ui-btn-primary',
                        events: {
                            click: function () {
                                var settings = self.collectMapSettingsFromDialog(content);
                                col.TRIM = settings.TRIM === 'Y' ? 'Y' : 'N';
                                col.CASE = ['lower', 'upper', 'firstupper'].indexOf(settings.CASE) >= 0 ? settings.CASE : '';
                                col.DELIMETER = settings.DELIMETER && settings.DELIMETER !== '' ? settings.DELIMETER : ', ';
                                col.RULES = settings.RULES || [];
                                popup.close();
                                self.render();
                            }
                        }
                    }),
                    new BX.PopupWindowButtonLink({
                        text: BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS_CANCEL') || 'Отменить',
                        className: 'ui-btn ui-btn-link',
                        events: {
                            click: function () {
                                popup.close();
                            }
                        }
                    })
                ]
            });
            popup.show();

            BX.ajax.runAction(this.colSettingsAction, {
                data: {
                    id: this.id,
                    sheet: sheetIdx,
                    col: colIdx,
                    // текущее состояние колонки (новая колонка еще не сохранена
                    // в EXPORT_SHEETS — сервер возьмет ее отсюда)
                    columnCfg: {
                        NAME: col.NAME || '',
                        FIELD: col.FIELD || '',
                        TRIM: col.TRIM || 'N',
                        CASE: col.CASE || '',
                        DELIMETER: col.DELIMETER || ', ',
                        RULES: col.RULES || []
                    }
                }
            }).then(function (response) {
                var data = response.data || {};
                if (popup.titleNode && data.name) {
                    popup.titleNode.textContent = (BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS') || 'Настройки колонки')
                        + ' "' + data.name + '"';
                }
                content.innerHTML = data.html || '';
                // инициализация деревьев условий (методы из миксина mapdialog)
                if (self.bindMapDialogEvents) {
                    self.bindMapDialogEvents(content);
                }
            }, function (response) {
                content.innerHTML = '<div style="padding:20px;color:#d14;">'
                    + BX.util.htmlspecialchars(self.errorMessage(response, 'Ошибка загрузки настроек'))
                    + '</div>';
            });
        },

        /**
         * Сбор и сохранение настроек. При next=true — переход на шаг 4.
         */
        save: function (next) {
            var self = this;
            // перед сохранением убираем пустые строки (без поля)
            var sheets = [];
            this.sheets.forEach(function (sheet) {
                var columns = [];
                (sheet.COLUMNS || []).forEach(function (col) {
                    if (col.FIELD) columns.push(self.normalizeColumn(col));
                });
                if (columns.length) {
                    sheets.push({NAME: sheet.NAME || '', COLUMNS: columns});
                }
            });
            if (!sheets.length) {
                this.setStatus(BX.message('AWZ_ALLXLS_EXPORT_SHEETS_EMPTY'), true);
                return;
            }

            BX.ajax.runAction(this.saveAction, {
                data: {
                    id: this.id,
                    sheets: sheets,
                    json_template: (function () {
                        var node = document.getElementById('AWZ_ALLXLS_PROFILE_EXPORT_JSON_TEMPLATE');
                        return node ? node.value : '';
                    })()
                }
            }).then(function (response) {
                self.setStatus(BX.message('AWZ_ALLXLS_EXPORT_SETTINGS_SAVED'));
                if (next && response.data && response.data.nextStepUrl) {
                    window.location.href = response.data.nextStepUrl;
                }
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_EXPORT_SETTINGS_ERROR')), true);
            });
        },

        setStatus: function (text, isError) {
            var loader = document.getElementById(this.loaderId);
            if (loader) {
                loader.style.display = '';
                loader.textContent = text || '';
                loader.style.color = isError ? '#d9534f' : '';
            }
        },

        errorMessage: function (response, fallback) {
            var messages = [];
            if (response && response.errors) {
                response.errors.forEach(function (err) {
                    messages.push(err.message || err.code || '');
                });
            }
            messages = messages.filter(function (v) { return !!v; });
            return messages.length ? messages.join('; ') : (fallback || 'Error');
        }
    };

    window.Awz.Allxls = window.Awz.Allxls || {};
    window.Awz.Allxls.ProfilesEditExportSettings = ExportSettings;

    /**
     * Подключение миксина окна настроек (как в импорте):
     * методы bindMapDialogEvents / initMapCondTree / collectMapSettingsFromDialog и др.
     */
    (function applyMixin(proto, mixin) {
        if (!proto || !mixin) return;
        for (var key in mixin) {
            if (Object.prototype.hasOwnProperty.call(mixin, key)) {
                proto[key] = mixin[key];
            }
        }
    })(window.Awz.Allxls.ProfilesEditExportSettings && window.Awz.Allxls.ProfilesEditExportSettings.prototype,
        window.Awz.Allxls.ProfilesEditMapDialogMixin);

})(window);
