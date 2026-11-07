/**
 * AWZ.AllXLS: шаг 3 (экспорт) — расширение настроек колонок.
 * Подключается после profiles_edit_step3_export.js и profiles_edit_step3_mapdialog.js.
 *
 * Добавляет к Awz.Allxls.ProfilesEditExportSettings:
 *  - чекбокс активности колонки (в ячейке номера) и иконку перемещения (drag&drop),
 *  - окно настроек колонки как в импорте (BX.PopupWindow, titleBar, ширина 900,
 *    кнопки ui-btn, HTML с контроллера awz:allxls.api.pexport.colsettings,
 *    правила — тот же конструктор BX.TreeConditions через миксин mapdialog),
 *  - поддержку ACTIVE ('Y'|'N') и правил в формате импорта (TYPE:'skip').
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};
    window.Awz.Allxls = window.Awz.Allxls || {};

    function applyWhenReady(cb) {
        if (window.Awz.Allxls.ProfilesEditExportSettings
            && window.Awz.Allxls.ProfilesEditMapDialogMixin
            && window.BX) {
            cb();
        } else {
            setTimeout(function () { applyWhenReady(cb); }, 50);
        }
    }

    applyWhenReady(function () {
        var ExportSettings = window.Awz.Allxls.ProfilesEditExportSettings;
        var Mixin = window.Awz.Allxls.ProfilesEditMapDialogMixin;
        var proto = ExportSettings.prototype;

        // === миксин окна настроек (инициализация деревьев, сбор значений) ===
        for (var key in Mixin) {
            if (Object.prototype.hasOwnProperty.call(Mixin, key) && !proto[key]) {
                proto[key] = Mixin[key];
            }
        }

        // === normalizeColumn: ACTIVE + правила в формате импорта ===
        var origNormalizeColumn = proto.normalizeColumn;
        proto.normalizeColumn = function (col) {
            col = col || {};
            var result = origNormalizeColumn.apply(this, arguments);
            result.ACTIVE = col.ACTIVE === 'N' ? 'N' : 'Y';
            var rules = [];
            (col.RULES || []).forEach(function (rule) {
                if (!rule || typeof rule !== 'object') return;
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
            result.RULES = rules;
            return result;
        };

        // === render: чекбокс активности + иконка перемещения в ячейке номера ===
        var origRender = proto.render;
        proto.render = function () {
            origRender.apply(this, arguments);
            var container = document.getElementById(this.containerId);
            if (!container) return;
            var self = this;

            Array.prototype.slice.call(container.querySelectorAll('table tr')).forEach(function (tr) {
                var s = parseInt(tr.getAttribute('data-sheet'), 10);
                if (isNaN(s)) return;
                var c = parseInt(tr.getAttribute('data-col'), 10);
                if (isNaN(c)) return;
                var col = (self.sheets[s] && self.sheets[s].COLUMNS[c]) || null;
                if (!col) return;

                var numTd = tr.cells[0];
                if (!numTd) return;
                // основной файл уже мог добавить иконку и чекбокс — не дублируем
                if (numTd.getAttribute('data-awz-ext') === 'Y' || numTd.querySelector('.awz-export-col-drag')) {
                    numTd.setAttribute('data-awz-ext', 'Y');
                    return;
                }
                numTd.setAttribute('data-awz-ext', 'Y');
                numTd.style.whiteSpace = 'nowrap';

                var inactive = col.ACTIVE === 'N';
                if (inactive) tr.style.opacity = '.5';

                var html = '<span class="awz-export-col-drag" data-sheet="' + s + '" data-col="' + c + '"'
                    + ' title="' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_MOVE') || 'Переместить')
                    + '" style="cursor:move;color:#999;padding:0 4px;display:inline-block;">&#8942;&#8942;</span>'
                    + '<input type="checkbox" class="awz-export-col-active" data-sheet="' + s + '" data-col="' + c + '"'
                    + ' title="' + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_COL_ACTIVE') || 'Колонка активна')
                    + '"' + (inactive ? '' : ' checked')
                    + ' style="vertical-align:middle;margin:0 4px 0 0;">';
                numTd.innerHTML = html + numTd.innerHTML;
            });

            this.bindExtEvents(container);
        };

        // === события чекбокса активности и перемещения колонок ===
        var extBound = new WeakSet ? null : null; // noop для старых браузеров
        proto.bindExtEvents = function (container) {
            var self = this;
            if (container.getAttribute('data-awz-ext-bound') === 'Y') return;
            container.setAttribute('data-awz-ext-bound', 'Y');

            // чекбокс активности
            BX.bindDelegate(container, 'change', {className: 'awz-export-col-active'}, function () {
                var s = parseInt(this.getAttribute('data-sheet'), 10) || 0;
                var c = parseInt(this.getAttribute('data-col'), 10) || 0;
                var col = self.sheets[s] && self.sheets[s].COLUMNS[c];
                if (!col) return;
                col.ACTIVE = this.checked ? 'Y' : 'N';
                var tr = this.closest ? this.closest('tr') : null;
                if (tr) tr.style.opacity = this.checked ? '' : '.5';
            });

            // перемещение: drag включается только за иконку
            BX.bindDelegate(container, 'mousedown', {className: 'awz-export-col-drag'}, function () {
                var tr = this.closest ? this.closest('tr') : null;
                if (tr) tr.setAttribute('draggable', 'true');
            });
            BX.bindDelegate(container, 'mouseup', {className: 'awz-export-col-drag'}, function () {
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
        };

        // === окно настроек колонки: как в импорте (ajax + TreeConditions) ===
        proto.openColumnSettings = function (sheetIdx, colIdx) {
            var self = this;
            // защита от повторного открытия: обработчики накапливаются при перерисовке
            if (this._colPopupOpen) return;
            this._colPopupOpen = true;
            var popupId = 'awz-allxls-col-settings-' + this.id + '-' + sheetIdx + '-' + colIdx
                + '-' + Math.floor(Math.random() * 1e5);

            var content = document.createElement('div');
            content.innerHTML = '<div style="padding:30px;text-align:center;color:#8a94a6;">Загрузка...</div>';

            var popup = new BX.PopupWindow(popupId, null, {
                content: content,
                titleBar: BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS') || 'Настройки колонки',
                width: 900,
                overlay: {opacity: 40},
                draggable: true,
                closeByEsc: true,
                events: {
                    onPopupClose: function () {
                        self._colPopupOpen = false;
                        popup.destroy();
                    }
                },
                buttons: [
                    new BX.PopupWindowButton({
                        text: BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS_SAVE') || 'Сохранить',
                        className: 'ui-btn ui-btn-primary',
                        events: {
                            click: function () {
                                var settings = self.collectMapSettingsFromDialog(content);
                                var col = self.sheets[sheetIdx].COLUMNS[colIdx];
                                if (col) {
                                    col.TRIM = settings.TRIM === 'Y' ? 'Y' : 'N';
                                    col.CASE = ['lower', 'upper', 'firstupper'].indexOf(settings.CASE) >= 0
                                        ? settings.CASE : '';
                                    col.DELIMETER = settings.DELIMETER && settings.DELIMETER !== ''
                                        ? settings.DELIMETER : ', ';
                                    // оставляем только действия skip (формат импорта)
                                    col.RULES = (settings.RULES || []).filter(function (rule) {
                                        return rule && rule.TYPE === 'skip';
                                    });
                                }
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

            BX.ajax.runAction('awz:allxls.api.pexport.colsettings', {
                data: {id: this.id, sheet: sheetIdx, col: colIdx}
            }).then(function (response) {
                var data = response.data || {};
                if (popup.titleNode && data.name) {
                    popup.titleNode.textContent = (BX.message('AWZ_ALLXLS_EXPORT_COL_SETTINGS') || 'Настройки колонки')
                        + ' "' + data.name + '"';
                }
                content.innerHTML = data.html || '';
                // инициализация деревьев условий (методы миксина mapdialog)
                if (self.bindMapDialogEvents) {
                    self.bindMapDialogEvents(content);
                }
            }, function (response) {
                content.innerHTML = '<div style="padding:20px;color:#d14;">'
                    + BX.util.htmlspecialchars(self.errorMessage(response, 'Ошибка загрузки настроек'))
                    + '</div>';
            });
        };
    });

})(window);
