/**
 * AWZ.AllXLS: шаг 3 — настройки листов файла
 * Использование:
 *   var ob = new Awz.Allxls.ProfilesEditSettings({id: 1, listUrl: '...'});
 *   ob.init();
 *
 * Методы колонок-примеров и диалога настроек сопоставления вынесены в
 * profiles_edit_step3_sample.js и profiles_edit_step3_mapdialog.js (миксины).
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var ProfilesEditSettings = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.filePath = options.filePath || ''; // источник данных (файл) профиля
        this.loadAction = options.loadAction || 'awz:allxls.api.profiles.settings';
        this.saveAction = options.saveAction || 'awz:allxls.api.profiles.savesettings';
        this.containerId = options.containerId || 'awz_allxls_profile_settings_container';
        this.loaderId = options.loaderId || 'awz_allxls_profile_settings_loader';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_settings_save';
        this.saveBtnIdNext = options.saveBtnIdNext || 'awz_allxls_profile_settings_save_next';
            this.fieldsAction = options.fieldsAction || 'awz:allxls.api.profiles.fields';
            this.cacheStatusAction = options.cacheStatusAction || 'awz:allxls.api.profiles.filecachestatus';
        this.fieldsGroups = []; // группированный список полей для сопоставления
        this.sheets = [];
        this.saving = false;
    };

    ProfilesEditSettings.prototype = {
        init: function () {
            var self = this;
            var btn = document.getElementById(this.saveBtnId);
            var btn_next = document.getElementById(this.saveBtnIdNext);
            if (btn) {
                BX.bind(btn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.save();
                });
            }
            if (btn_next) {
                BX.bind(btn_next, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.save(true);
                });
            }
            var reloadBtn = document.getElementById(this.reloadBtnId || 'awz_allxls_profile_settings_reload');
            if (reloadBtn) {
                BX.bind(reloadBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.load();
                });
            }
            // ссылка на инстанс для диагностики в консоли
            window.__awzStep3 = this;
            this.loadFields();
            this.load();
        },

        /**
         * Загрузка списка полей для сопоставления (один раз при открытии этапа)
         */
        loadFields: function () {
            var self = this;
            BX.ajax.runAction(this.fieldsAction, {
                data: {id: this.id}
            }).then(function (response) {
                self.fieldsGroups = (response.data && response.data.groups) || [];
                // ID инфоблока профиля (для создания новых свойств)
                self.iblockId = parseInt(response.data && response.data.iblock_id, 10) || 0;
                if (window.console && console.info) {
                    console.info('[awz.allxls] fields loaded: groups=' + self.fieldsGroups.length);
                }
                // обновление вызывается напрямую (без цепочки .then)
                self.applyFieldsToAllSelects();
            }, function (response) {
                // при ошибке НЕ затираем предыдущие группы
                if (window.console && console.warn) {
                    console.warn('[awz.allxls] fields load FAIL', response && response.errors);
                }
                self.applyFieldsToAllSelects();
            });
        },

        load: function () {
            var self = this;
            BX.ajax.runAction(this.loadAction, {
                data: {id: this.id}
            }).then(function (response) {
                var loader = document.getElementById(self.loaderId);
                if (loader) loader.style.display = 'none';
                // внешний источник, файл по ссылке еще не скачан в кеш —
                // показываем баннер ожидания и опрашиваем статус
                if (response.data && response.data.file_cache === 'pending') {
                    self.showCachePending();
                    return;
                }
                self.sheets = (response.data && response.data.sheets) || [];
                self.render();
                // дерево категорий из файла (если есть categories->category)
                self.renderCatTree(response.data && response.data.cat_tree);
            }, function (response) {
                var loader = document.getElementById(self.loaderId);
                if (loader) loader.innerHTML = BX.util.htmlspecialchars(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_LOAD_ERROR')));
            });
        },

        /**
         * Файл внешнего источника еще не скачан в кеш:
         * баннер ожидания (скачивание выполняется на crontab/агенте)
         * + опрос статуса каждые 10 сек, при готовности — перезагрузка страницы
         */
        showCachePending: function () {
            var self = this;
            var container = document.getElementById(this.containerId);
            var loader = document.getElementById(this.loaderId);
            if (!container) return;
            var banner = document.createElement('div');
            banner.className = 'adm-info-message';
            banner.style.cssText = 'margin:10px 0;';
            banner.textContent = BX.message('AWZ_ALLXLS_PROFILE_FILE_CACHE_PENDING')
                || 'Файл по внешней ссылке еще не скачан. Скачивание выполняется на crontab/агенте — страница обновится автоматически после готовности файла.';
            container.innerHTML = '';
            container.appendChild(banner);
            if (loader) loader.style.display = 'none';
            this.cachePoll = window.setInterval(function () {
                BX.ajax.runAction(self.cacheStatusAction || 'awz:allxls.api.profiles.filecachestatus', {
                    data: {id: self.id}
                }).then(function (response) {
                    var ready = response.data && response.data.ready;
                    if (ready) {
                        window.clearInterval(self.cachePoll);
                        window.location.reload();
                    }
                }, function () {});
            }, 10000);
        },

        render: function () {
            var container = document.getElementById(this.containerId);
            if (!container) return;
            var self = this;
            container.innerHTML = '';

            this.sheets.forEach(function (sheet, index) {
                container.appendChild(self.renderSheet(sheet, index));
                // select2 для select «- добавить поле -» — только после вставки в DOM
                if (sheet._inactiveSelect) {
                    self.initMapSelect(sheet._inactiveSelect, '- добавить поле -');
                }
            });

            // повторное применение состояний после вставки в DOM,
            // иначе кнопка «Показать неактивные» может остаться скрытой
            this.sheets.forEach(function (sheet, index) {
                (sheet.COLUMNS || []).forEach(function (col) {
                    self.applyColumnState(sheet, index, col);
                });
            });
        },

        /**
         * Дерево категорий из файла (categories->category).
         * По умолчанию свернуто — раскрывается по нажатию на «+»
         * (заголовок и каждый узел с дочерними категориями).
         */
        renderCatTree: function (data) {
            var box = document.getElementById('awz_allxls_profile_cattree');
            if (!box) return;
            box.innerHTML = '';
            var map = (data && data.MAP) || {};
            var keys = Object.keys(map);
            if (!keys.length) {
                box.style.display = 'none';
                return;
            }
            var MAX_NODES = 5000;
            if (keys.length > MAX_NODES) {
                box.style.display = '';
                var tooBig = document.createElement('div');
                tooBig.className = 'awz-allxls-row__desc';
                tooBig.textContent = 'Дерево категорий: ' + keys.length + ' категорий (слишком много для отображения)';
                box.appendChild(tooBig);
                return;
            }
            var children = {};
            var roots = [];
            keys.forEach(function (id) {
                var parent = String(map[id].parent || '');
                if (!children[parent]) children[parent] = [];
                children[parent].push(id);
            });
            keys.forEach(function (id) {
                // корень: родителя нет или он не в дереве
                var parent = String(map[id].parent || '');
                if (parent === '' || !map[parent]) roots.push(id);
            });
            var sortIds = function (ids) {
                return ids.sort(function (a, b) {
                    return String(map[a].name || '').localeCompare(String(map[b].name || ''), 'ru');
                });
            };

            // переключатель узла: +/- (раскрыть/свернуть детей)
            var toggles = [];
            var makeToggle = function (targetUl) {
                var toggle = document.createElement('span');
                toggle.className = 'awz-allxls-cattree__toggle';
                toggle.style.cssText = 'display:inline-block;width:18px;height:18px;line-height:16px;text-align:center;cursor:pointer;user-select:none;border:1px solid #ccc;border-radius:3px;margin-right:6px;font-weight:bold;';
                toggle.textContent = '+';
                toggle.setAttribute('data-state', 'closed');
                toggles.push(toggle);
                BX.bind(toggle, 'click', function () {
                    var open = toggle.getAttribute('data-state') === 'open';
                    setToggleState(toggle, targetUl, !open);
                });
                return toggle;
            };
            // единая установка состояния переключателя
            var setToggleState = function (toggle, targetUl, open) {
                toggle.setAttribute('data-state', open ? 'open' : 'closed');
                toggle.textContent = open ? '−' : '+';
                targetUl.style.display = open ? '' : 'none';
            };

            // строит <ul> уровня и возвращает его; дети вложены в li,
            // toggle управляет видимостью именно их <ul>
            var buildUl = function (ids) {
                var ul = document.createElement('ul');
                ul.style.cssText = 'margin:2px 0 2px 18px;padding:0;list-style:none;';
                sortIds(ids).forEach(function (id) {
                    var node = map[id];
                    var li = document.createElement('li');
                    li.style.cssText = 'padding:1px 0;';
                    var label = document.createElement('span');
                    label.textContent = (node.name || '(без названия)') + ' (' + id + ')';
                    li.appendChild(label);
                    var kids = children[id] || [];
                    if (kids.length) {
                        var kidsUl = buildUl(kids);
                        kidsUl.style.display = 'none';
                        li.insertBefore(makeToggle(kidsUl), label);
                        li.appendChild(kidsUl);
                    }
                    ul.appendChild(li);
                });
                return ul;
            };

            // заголовок с «+» — раскрытие всего дерева (по умолчанию скрыто)
            var head = document.createElement('div');
            head.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:10px;';
            var rootUl = buildUl(roots);
            rootUl.style.display = 'none';
            var rootToggle = makeToggle(rootUl);
            head.appendChild(rootToggle);
            var title = document.createElement('div');
            title.className = 'awz-allxls-card__title';
            title.style.flex = '1';
            title.textContent = 'Дерево категорий (' + keys.length + ')';
            head.appendChild(title);

            // кнопка «Развернуть все/Свернуть все» — справа от заголовка дерева
            var expandAllBtn = document.createElement('button');
            expandAllBtn.type = 'button';
            expandAllBtn.className = 'awz-allxls-btn awz-allxls-btn--ghost';
            expandAllBtn.style.cssText = 'padding:5px 12px;font-size:12px;align-self:center;';
            expandAllBtn.textContent = 'Развернуть все';
            head.appendChild(expandAllBtn);
            BX.bind(expandAllBtn, 'click', function () {
                var expand = expandAllBtn.textContent === 'Развернуть все';
                toggles.forEach(function (toggle) {
                    // ul лежит в li после label — ищем ul напрямую в родителе toggle
                    var li = toggle.parentNode;
                    var targetUl = li.querySelector(':scope > ul');
                    if (targetUl) setToggleState(toggle, targetUl, expand);
                });
                // корневой список управляется кнопкой явно
                setToggleState(rootToggle, rootUl, expand);
                expandAllBtn.textContent = expand ? 'Свернуть все' : 'Развернуть все';
            });
            box.appendChild(head);
            box.appendChild(rootUl);
            box.style.display = '';
        },

        renderSheet: function (sheet, index) {
            var self = this;
            var card = document.createElement('div');
            card.className = 'awz-allxls-card';
            card.style.marginTop = '12px';

            var head = document.createElement('div');
            head.className = 'awz-allxls-card__head';

            var title = document.createElement('div');
            title.className = 'awz-allxls-card__title';
            title.textContent = 'Лист "' + sheet.NAME + '"';

            var activeLabel = document.createElement('label');
            activeLabel.style.cssText = 'display:flex;align-items:center;gap:6px;cursor:pointer;padding:0;';
            var activeCheck = document.createElement('input');
            activeCheck.type = 'checkbox';
            activeCheck.checked = sheet.ACTIVE !== 'N';
            activeCheck.setAttribute('data-sheet-active', index);
            activeLabel.appendChild(activeCheck);
            activeLabel.appendChild(document.createTextNode(BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SHEET_ACTIVE') || 'Загружать лист'));
            head.appendChild(title);
            head.appendChild(activeLabel);
            card.appendChild(head);

            var headerRowWrap = document.createElement('div');
            headerRowWrap.style.cssText = 'margin:8px 0;display:flex;align-items:center;gap:8px;padding:0 10px;';
            var headerRowLabel = document.createElement('span');
            headerRowLabel.textContent = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_HEADER_ROW') || 'Строка заголовков:';
            var headerRowInput = document.createElement('input');
            headerRowInput.type = 'number';
            headerRowInput.min = '1';
            headerRowInput.value = sheet.HEADER_ROW || 1;
            headerRowInput.setAttribute('data-sheet-header-row', index);
            headerRowInput.className = 'awz-allxls-input';
            headerRowInput.style.width = '80px';
            headerRowWrap.appendChild(headerRowLabel);
            headerRowWrap.appendChild(headerRowInput);
            card.appendChild(headerRowWrap);

            var table = document.createElement('table');
            table.className = 'awz-allxls-grid';
            table.style.width = '100%';

            var thead = document.createElement('thead');
            var headRow = document.createElement('tr');
            [BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_INCLUDE') || '',
             BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_LETTER') || 'Колонка',
             BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_MAP') || 'Сопоставление',
             BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_SAMPLE') || 'Пример значения'].forEach(function (text) {
                var th = document.createElement('th');
                th.textContent = text;
                headRow.appendChild(th);
            });
            thead.appendChild(headRow);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            tbody.setAttribute('data-cols-tbody', index);
            table.appendChild(tbody);
            card.appendChild(table);

            // состояние дополнительных колонок-примеров (до 10), восстанавливается из настроек профиля
            sheet._sampleCols = Math.max(1, Math.min(10, parseInt(sheet.SAMPLE_COLS, 10) || 1));
            sheet._sampleHidden = {};
            (sheet.SAMPLE_HIDDEN || []).forEach(function (idx) {
                idx = parseInt(idx, 10);
                if (idx >= 1 && idx < sheet._sampleCols) sheet._sampleHidden[idx] = true;
            });
            sheet._sampleCells = {};
            sheet._headRow = headRow;

            // кнопка-переключатель «Показать/Скрыть неактивные»
            var inactiveToggle = document.createElement('button');
            inactiveToggle.type = 'button';
            inactiveToggle.className = 'awz-allxls-btn awz-allxls-btn--ghost';
            inactiveToggle.style.cssText = 'margin:10px;padding:5px 12px;font-size:12px;';
            inactiveToggle.textContent = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SHOW_INACTIVE') || 'Показать неактивные';
            inactiveToggle.setAttribute('data-inactive-toggle', index);
            inactiveToggle.style.display = 'none';
            card.appendChild(inactiveToggle);
            sheet._inactiveToggle = inactiveToggle;

            // select «Управление полями» — выбор конкретного неактивного поля
            var inactiveSelect = document.createElement('select');
            inactiveSelect.className = 'awz-allxls-input';
            inactiveSelect.style.cssText = 'margin:10px;padding:4px 8px;font-size:12px;width:auto;';
            inactiveSelect.setAttribute('data-inactive-select', index);
            var manageOpt = document.createElement('option');
            manageOpt.value = '';
            manageOpt.textContent = '- добавить поле -';
            inactiveSelect.appendChild(manageOpt);
            // пункты с неактивными полями заполняются в refreshInactiveSelect()
            inactiveSelect.style.display = 'none';
            card.appendChild(inactiveSelect);
            sheet._inactiveSelect = inactiveSelect;

            // кнопка «Скрыть все» — снимает INCLUDE у всех колонок листа
            // (повторное нажатие включает все колонки обратно)
            var hideAllBtn = document.createElement('button');
            hideAllBtn.type = 'button';
            hideAllBtn.className = 'awz-allxls-btn awz-allxls-btn--ghost';
            hideAllBtn.style.cssText = 'margin:10px;padding:5px 12px;font-size:12px;';
            hideAllBtn.textContent = 'Скрыть все';
            hideAllBtn.setAttribute('data-hide-all', index);
            card.appendChild(hideAllBtn);

            sheet._rows = {};
            (sheet.COLUMNS || []).forEach(function (col) {
                var tr = self.renderColumnRow(index, col, sheet);
                sheet._rows[col.KEY] = {row: tr, col: col};
                tbody.appendChild(tr);
                self.applyColumnState(sheet, index, col);
            });

            // drag-and-drop перемещение строк колонок
            this.initRowDrag(sheet, index, tbody);

            // шапка дополнительных колонок-примеров (+/-)
            this.renderSampleHeader(sheet, index, headRow);

            // заполнение select пунктами неактивных полей листа.
            // applyColumnState вызывает refreshInactiveSelect на каждую колонку —
            // без дебаунса на больших листах получается O(N²) пересборок
            // опций + re-init select2, что подвешивает UI на секунды
            var refreshTimer = null;
            function refreshInactiveSelect() {
                if (refreshTimer) window.clearTimeout(refreshTimer);
                refreshTimer = window.setTimeout(function () {
                    refreshTimer = null;
                    if (!inactiveSelect || !inactiveSelect.options || !sheet || !sheet.COLUMNS) return;
                    // сохраняем первый пункт «Управление полями»
                    while (inactiveSelect.options.length > 1) {
                        inactiveSelect.remove(1);
                    }
                    sheet.COLUMNS.forEach(function (col) {
                        if (col.INCLUDE === 'N') {
                            var opt = document.createElement('option');
                            opt.value = col.KEY;
                            // буква колонки | транслит | название (TITLE — реальное имя из name для param_*)
                            opt.textContent = [
                                col.LETTER || col.INDEX,
                                col.ALIAS || self.translit(col.HEADER || ''),
                                col.TITLE || col.HEADER
                            ].filter(function (v) { return !!v; }).join(' | ');
                            inactiveSelect.appendChild(opt);
                        }
                    });
                    // сброс выбора, если выбранное ранее поле стало активным
                    if (inactiveSelect.value &&
                        (!sheet._rows[inactiveSelect.value] ||
                         sheet._rows[inactiveSelect.value].col.INCLUDE !== 'N')) {
                        inactiveSelect.value = '';
                        tbody.setAttribute('data-inactive-selected', '');
                    }
                    // select2 должен перечитать обновленные опции
                    self.initMapSelect(inactiveSelect, '- добавить поле -');
                }, 30);
            }
            sheet._refreshInactiveSelect = refreshInactiveSelect;

            // кнопка-переключатель: показать/скрыть все неактивные
            BX.bind(inactiveToggle, 'click', function () {
                var hidden = tbody.getAttribute('data-inactive-hidden') !== 'N';
                tbody.setAttribute('data-inactive-hidden', hidden ? 'N' : 'Y');
                table.setAttribute('data-inactive-hidden', hidden ? 'N' : 'Y');
                inactiveToggle.textContent = hidden
                    ? (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_HIDE_INACTIVE') || 'Скрыть неактивные')
                    : (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SHOW_INACTIVE') || 'Показать неактивные');
                Object.keys(sheet._rows).forEach(function (key) {
                    self.applyColumnState(sheet, index, sheet._rows[key].col);
                });
            });

            // select «Управление полями»: показать только выбранную неактивную колонку
            function onInactiveSelectChange() {
                // дедуп: select2 может вызвать и native change, и jQuery change —
                // второй вызов с уже сброшенным value отменил бы применение
                var now = (window.Date && Date.now) ? Date.now() : +new Date();
                if (now - onInactiveSelectChange._last < 50) return;
                onInactiveSelectChange._last = now;
                var val = inactiveSelect.value;
                if (!val) return;
                tbody.setAttribute('data-inactive-hidden', val ? 'Y' : 'N');
                tbody.setAttribute('data-inactive-selected', val);
                Object.keys(sheet._rows).forEach(function (key) {
                    self.applyColumnState(sheet, index, sheet._rows[key].col);
                });
                // сбрасываем select на пустое значение после применения
                inactiveSelect.value = '';
                // синхронизируем отображение select2 (иначе в закрытом
                // контроле остается текст выбранного поля)
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                    window.jQuery(inactiveSelect).trigger('change.select2');
                }
            }
            // select2 4.x триггерит change только через jQuery —
            // вешаем обработчик и на нативное событие, и на jQuery
            // (двойное срабатывание не страшно: второй вызов с пустым value)
            BX.bind(inactiveSelect, 'change', onInactiveSelectChange);
            if (window.jQuery) {
                window.jQuery(inactiveSelect).on('change', onInactiveSelectChange);
            }

            refreshInactiveSelect();

            // «Скрыть все» — снять INCLUDE со всех колонок листа,
            // повторное нажатие (когда все скрыты) включает все колонки
            BX.bind(hideAllBtn, 'click', function () {
                var cols = sheet.COLUMNS || [];
                if (!cols.length) return;
                var anyActive = cols.some(function (col) { return col.INCLUDE !== 'N'; });
                cols.forEach(function (col) {
                    col.INCLUDE = anyActive ? 'N' : 'Y';
                    var r = sheet._rows[col.KEY];
                    if (r && r.row) {
                        var chk = r.row.querySelector('input[data-col-include]');
                        if (chk) chk.checked = col.INCLUDE !== 'N';
                    }
                    self.applyColumnState(sheet, index, col);
                });
                hideAllBtn.textContent = anyActive ? 'Показать все' : 'Скрыть все';
                if (sheet._refreshInactiveSelect) sheet._refreshInactiveSelect();
            });

            return card;
        },

        renderColumnRow: function (sheetIndex, col, sheet) {
            var self = this;
            var tr = document.createElement('tr');
            tr.setAttribute('data-col-row', sheetIndex + ':' + col.KEY);

            var tdCheck = document.createElement('td');
            // иконка перемещения строки (drag) — над чекбоксом
            var dragHandle = document.createElement('span');
            dragHandle.className = 'awz-allxls-grid__drag';
            dragHandle.textContent = '⠿';
            dragHandle.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_DRAG') || 'Переместить строку';
            dragHandle.setAttribute('data-drag-handle', sheetIndex + ':' + col.KEY);
            dragHandle.draggable = true;
            tdCheck.appendChild(dragHandle);
            var check = document.createElement('input');
            check.type = 'checkbox';
            check.checked = col.INCLUDE !== 'N';
            check.setAttribute('data-col-include', sheetIndex + ':' + col.KEY);
            BX.bind(check, 'change', function () {
                col.INCLUDE = check.checked ? 'Y' : 'N';
                self.applyColumnState(sheet, sheetIndex, col);
            });
            tdCheck.appendChild(check);
            tr.appendChild(tdCheck);

            var tdLetter = document.createElement('td');
            var letter = document.createElement('span');
            letter.className = 'awz-allxls-grid__letter';
            letter.textContent = col.LETTER || String(col.INDEX);
            tdLetter.appendChild(letter);
            if (col.HEADER) {
                var headerInfo = document.createElement('div');
                headerInfo.className = 'awz-allxls-grid__header-info';
                headerInfo.textContent = col.TITLE || col.HEADER;
                headerInfo.title = (col.TITLE && col.HEADER) ? col.HEADER : '';
                tdLetter.appendChild(headerInfo);
            }
            var aliasSpan = document.createElement('div');
            aliasSpan.className = 'awz-allxls-grid__alias';
            // алиас генерится автоматически из заголовка и служит ключом настроек (не редактируется)
            aliasSpan.textContent = col.ALIAS || self.translit(col.HEADER || '');
            aliasSpan.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_ALIAS_HINT') || '';
            tdLetter.appendChild(aliasSpan);
            tr.appendChild(tdLetter);

            var tdMap = document.createElement('td');
            var mapWrap = document.createElement('div');
            mapWrap.className = 'awz-allxls-grid__maps';
            mapWrap.setAttribute('data-col-maps', sheetIndex + ':' + col.KEY);
            tdMap.appendChild(mapWrap);
            // первый селект сопоставления создается всегда (автоподгрузка),
            // остальные — из сохраненных значений
            var savedMaps = (col.MAPS || []).filter(function (v) { return v; });
            var savedSettings = (col.MAP_SETTINGS || []);
            if (!savedMaps.length) {
                mapWrap.appendChild(self.createMapItem(col, null, {}, sheet));
            } else {
                savedMaps.forEach(function (value, mapIdx) {
                    mapWrap.appendChild(self.createMapItem(col, value, savedSettings[mapIdx] || {}, sheet));
                });
            }
            // первый элемент — «+», остальные — «×» (иконки внутри элементов)
            self.refreshMapControls(mapWrap);
            tr.appendChild(tdMap);

            var tdSample = document.createElement('td');
            var sampleText = col.SAMPLE || '';
            var sample = document.createElement('div');
            sample.className = 'awz-allxls-grid__sample';
            sample.textContent = sampleText;
            tdSample.appendChild(sample);
            if (sampleText.split('\n').length > 3 || sampleText.length > 120) {
                var expandBtn = document.createElement('button');
                expandBtn.type = 'button';
                expandBtn.className = 'awz-allxls-btn awz-allxls-btn--ghost';
                expandBtn.style.cssText = 'margin-top:4px;padding:2px 8px;font-size:11px;';
                expandBtn.textContent = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_EXPAND') || 'Раскрыть';
                BX.bind(expandBtn, 'click', function () {
                    var expanded = sample.classList.toggle('awz-allxls-grid__sample--expanded');
                    expandBtn.textContent = expanded
                        ? (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COLLAPSE') || 'Свернуть')
                        : (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_EXPAND') || 'Раскрыть');
                });
                tdSample.appendChild(expandBtn);
            }
            tr.appendChild(tdSample);

            // дополнительные колонки-примеры (значения из последующих строк файла)
            sheet._sampleCells = sheet._sampleCells || {};
            sheet._sampleCells[col.KEY] = sheet._sampleCells[col.KEY] || {};
            for (var sIdx = 1; sIdx < (sheet._sampleCols || 1); sIdx++) {
                var cell = self.renderSampleCell(sheet, sheetIndex, col, sIdx);
                // скрытые колонки не вставляем в DOM (возвращаются при «+»)
                if (!(sheet._sampleHidden && sheet._sampleHidden[sIdx])) {
                    tr.appendChild(cell);
                }
            }

            return tr;
        },

        /**
         * Блок сопоставления: селект поля + иконка настроек справа.
         * Настройки индивидуальны для каждого добавленного сопоставления
         * и редактируются во всплывающем окне (HTML подгружается с контроллера).
         */
        /**
         * Drag-and-drop rows reordering (handle icon in the first column).
         * After drop, sheet.COLUMNS order is synced with DOM rows order.
         */
        initRowDrag: function (sheet, sheetIndex, tbody) {
            var self = this;
            var dragRow = null;

            function getRow(el) {
                while (el && el !== tbody) {
                    if (el.tagName === 'TR') return el;
                    el = el.parentNode;
                }
                return null;
            }

            BX.bind(tbody, 'dragstart', function (e) {
                var t = e.target || e.srcElement;
                if (!t.getAttribute || !t.getAttribute('data-drag-handle')) {
                    e.preventDefault();
                    return;
                }
                dragRow = getRow(t);
                if (dragRow) {
                    dragRow.classList.add('awz-allxls-grid__row--dragging');
                    if (e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'move';
                        try { e.dataTransfer.setData('text/plain', ''); } catch (err) {}
                    }
                }
            });

            BX.bind(tbody, 'dragover', function (e) {
                if (!dragRow) return;
                if (e.preventDefault) e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                var target = getRow(e.target || e.srcElement);
                if (!target || target === dragRow) return;
                var rect = target.getBoundingClientRect();
                var before = (e.clientY - rect.top) < rect.height / 2;
                if (before) {
                    tbody.insertBefore(dragRow, target);
                } else {
                    tbody.insertBefore(dragRow, target.nextSibling);
                }
            });

            function finishDrag() {
                if (!dragRow) return;
                dragRow.classList.remove('awz-allxls-grid__row--dragging');
                dragRow = null;
                // sync COLUMNS order with DOM rows order
                var rows = tbody.querySelectorAll('tr[data-col-row]');
                var ordered = [];
                for (var i = 0; i < rows.length; i++) {
                    var key = rows[i].getAttribute('data-col-row').split(':')[1];
                    var entry = sheet._rows[key];
                    if (entry) ordered.push(entry.col);
                }
                if (ordered.length === (sheet.COLUMNS || []).length) {
                    sheet.COLUMNS = ordered;
                }
                if (sheet._refreshInactiveSelect) sheet._refreshInactiveSelect();
            }

            BX.bind(tbody, 'dragend', finishDrag);
            BX.bind(tbody, 'drop', function (e) {
                if (e.preventDefault) e.preventDefault();
                finishDrag();
            });
        },
        createMapItem: function (col, value, settings, sheet) {
            //console.log(col, value, settings, sheet);
            var self = this;
            settings = settings || {};

            var item = document.createElement('div');
            item.className = 'awz-allxls-map-item';
            item._mapSettings = settings;

            var select = this.createMapSelect(col);
            // желаемое значение — в атрибут: applyFieldsToSelect восстановит
            // его после заполнения опций (поля могут загрузиться позже)
            if (value) select.setAttribute('data-map-value', value);
            item.appendChild(select);
            var effSelect = this.applyFieldsToSelect(select);
            if (effSelect && effSelect !== select) select = effSelect;
            item.__mapSelect = select;

            // компактная сводка настроек под селектом
            var info = document.createElement('div');
            info.className = 'awz-allxls-map-item__info';
            item.appendChild(info);

            // иконка настроек справа от селекта
            var gear = document.createElement('span');
            gear.className = 'awz-allxls-map-item__gear';
            gear.textContent = '⚙';
            gear.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_MAP_SETTINGS') || 'Настройки сопоставления';
            item.appendChild(gear);

            BX.bind(gear, 'click', function () {
                var fieldValue = select.value;
                var wrap = item.parentNode;
                var indexkey = 0;
                if (wrap) {
                    var items = wrap.querySelectorAll('.awz-allxls-map-item');
                    for (var ik = 0; ik < items.length; ik++) {
                        if (items[ik] === item) { indexkey = ik; break; }
                    }
                }
                self.openMapSettingsDialog(item, gear, fieldValue, sheet, col, indexkey);
            });

            // иконка добавления: видна только у последнего сопоставления
            var add = document.createElement('span');
            add.className = 'awz-allxls-map-add';
            add.textContent = '+';
            add.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_ADD_MAP') || 'Добавить сопоставление';
            add.style.display = 'none';
            item.appendChild(add);

            BX.bind(add, 'click', function () {
                var wrap = item.parentNode;
                if (!wrap) return;
                var newItem = self.createMapItem(col, null, {}, sheet);
                // новое сопоставление добавляется в конец списка
                wrap.appendChild(newItem);
                self.refreshMapControls(wrap);
            });

            // иконка удаления: видна, когда сопоставлений больше одного
            var del = document.createElement('span');
            del.className = 'awz-allxls-map-item__del';
            del.textContent = '×';
            del.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_MAP_DELETE') || 'Удалить сопоставление';
            del.style.display = 'none';
            item.appendChild(del);

            BX.bind(del, 'click', function () {
                var wrap = item.parentNode;
                if (!wrap) return;
                var siblings = wrap.querySelectorAll(':scope > .awz-allxls-map-item');
                if (siblings.length > 1) {
                    wrap.removeChild(item);
                    self.refreshMapControls(wrap);
                } else {
                    // последнее сопоставление — очищаем
                    select.value = '';
                    item._mapSettings = {};
                    self.updateMapItemInfo(item);
                    gear.classList.remove('awz-allxls-map-item__gear--active');
                    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                        window.jQuery(select).trigger('change.select2');
                    }
                }
            });

            self.updateMapItemInfo(item);

            return item;
        },

        /**
         * Компактная сводка настроек сопоставления под селектом (только иконки):
         * 🔍 — поиск элемента (в подсказке — тип поиска),
         * 🆕 / ✏️ — применение поля (новые / обновляемые),
         * ⚙ — настройки с количеством правил по типам (преобраз./пропуск/элемент).
         * Смысл каждой иконки раскрывается в подсказке (title).
         */
        updateMapItemInfo: function (item) {
            if (!item) return;
            var info = item.querySelector('.awz-allxls-map-item__info');
            if (!info) return;
            var s = item._mapSettings || {};
            var self = this;
            var makeIcon = function (icon, title) {
                var span = document.createElement('span');
                span.className = 'awz-allxls-map-item__icon';
                span.textContent = icon;
                span.title = title;
                span.style.cssText = 'cursor:default;margin-right:4px;';
                return span;
            };

            info.innerHTML = '';

            // поиск элемента: иконка + тип поиска в подсказке
            if ((s.SEARCH_USE || 'N') === 'Y') {
                var modes = {
                    eq: 'полное совпадение',
                    begin: 'начало строки',
                    end: 'конец строки',
                    contains: 'подстрока'
                };
                var modeName = modes[s.SEARCH_MODE] || modes.eq;
                info.appendChild(makeIcon('🔍', 'Поиск элемента: ' + modeName));
            }

            // применение поля: для новых / обновляемых
            var isNew = (s.IS_NEW || '') + '';
            if (isNew === 'Y') {
                info.appendChild(makeIcon('🆕', 'Только для новых элементов'));
            } else if (isNew === 'U') {
                info.appendChild(makeIcon('✏️', 'Только для обновляемых элементов'));
            } else {
                info.appendChild(makeIcon('🆕✏️', 'Для новых и обновляемых элементов'));
            }

            // настройки: иконка + количество правил по типам (преобраз./пропуск/элемент)
            var rules = (s.RULES && s.RULES.length) ? s.RULES : [];
            var counts = { transform: 0, skip: 0, delete: 0, unset: 0, entity: 0 };
            rules.forEach(function (r) {
                var t = (r && r.TYPE) || 'transform';
                if (counts[t] === undefined) counts[t] = 0;
                counts[t]++;
            });
            var ruleTitle = 'Правила: преобраз. ' + counts.transform +
                ' / пропуск ' + counts.skip +
                ' / элемент ' + counts.delete +
                ' / unset ' + counts.unset +
                ' / существующее ' + counts.entity;
            info.appendChild(makeIcon('⚙' + counts.transform + '/' + counts.skip + '/' + counts.delete + '/' + counts.unset + '/' + counts.entity, ruleTitle));

            info.style.display = '';
        },

        /**
         * Расстановка контролов сопоставлений:
         * «+» виден только у последнего элемента (добавление после него),
         * «×» видна у всех, когда сопоставлений больше одного.
         */
        refreshMapControls: function (mapWrap) {
            if (!mapWrap) return;
            var items = [];
            for (var n = 0; n < mapWrap.children.length; n++) {
                if (mapWrap.children[n].classList &&
                    mapWrap.children[n].classList.contains('awz-allxls-map-item')) {
                    items.push(mapWrap.children[n]);
                }
            }
            var single = items.length <= 1;
            for (var i = 0; i < items.length; i++) {
                var del = items[i].querySelector('.awz-allxls-map-item__del');
                // «×» не нужна у первого (базового) сопоставления
                if (del) del.style.display = (single || i === 0) ? 'none' : '';
                var add = items[i].querySelector('.awz-allxls-map-add');
                if (add) add.style.display = (i === 0) ? '' : 'none';
            }
        },

        /**
         * Пустой селект соответствия поля (заполняется на следующих этапах)
         */
        createMapSelect: function (col) {
            var select = document.createElement('select');
            select.className = 'awz-allxls-input awz-allxls-grid__map-select';
            select.style.cssText = 'width:260px;max-width:100%;margin-bottom:4px;';
            var option = document.createElement('option');
            option.value = '';
            option.textContent = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_MAP_EMPTY') || '- выбрать поле -';
            select.appendChild(option);
            return select;
        },

        /**
         * Заполнение селекта группами полей + инициализация select2 (поиск, группировка)
         */
        /**
         * Заполнение селекта группами полей (пересборка опций на месте,
         * select2 переинициализируется через destroy — узел select
         * не заменяется, ссылки и обработчики сохраняются).
         */
        applyFieldsToSelect: function (select) {
            // несохраненный выбор пользователя теряется при пересборке
            // опций (loadFields после создания нового свойства) — запоминаем
            // текущее значение ДО пересборки и восстанавливаем после
            var current = select.value || '';
            var emptyOption = select.querySelector('option[value=""]');
            select.innerHTML = '';
            if (emptyOption) select.appendChild(emptyOption);
            else {
                emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_MAP_EMPTY') || '- выбрать поле -';
                select.appendChild(emptyOption);
            }
            var self = this;
            (this.fieldsGroups || []).forEach(function (group) {
                if (!group.ITEMS || !group.ITEMS.length) return;
                var optgroup = document.createElement('optgroup');
                optgroup.label = group.NAME || '';
                group.ITEMS.forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = item.ID;
                    option.textContent = item.NAME;
                    optgroup.appendChild(option);
                });
                select.appendChild(optgroup);
            });
            // группа «Создать новое свойство» (миксин NewProp)
            if (this.appendNewPropGroup) this.appendNewPropGroup(select);
            // восстановление значения из атрибута (поля могли загрузиться позже);
            // если атрибута нет — возвращаем несохраненный выбор пользователя,
            // чтобы создание одного нового свойства не обнуляло другие
            // только что выбранные (но еще не сохраненные) сопоставления
            var want = select.getAttribute('data-map-value') || current;
            if (want) {
                for (var wi = 0; wi < select.options.length; wi++) {
                    if (select.options[wi].value === want) {
                        select.value = want;
                        break;
                    }
                }
            }
            this.initMapSelect(select);
            return select;
        },

        /**
         * Инициализация select2 для селекта сопоставления
         */
        initMapSelect: function (select, placeholder) {
            // инициализация select2 ТОЛЬКО для узла в DOM: на отсоединенном
            // select разметка виджета «теряется» при вставке в документ
            if (!select.parentNode) return;
            if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) return;
            var $select = window.jQuery(select);
            // повторная инициализация: сбрасываем существующий select2,
            // чтобы он перечитал обновленные опции (иначе выпадающий список
            // остается со старым набором полей)
            if ($select.data('select2')) {
                try { $select.select2('destroy'); } catch (e) {}
            }
            try {
                $select.select2({
                    // фиксированная ширина контрола, выпадающий список не шире контейнера
                    width: '260px',
                    dropdownAutoWidth: false,
                    // свой класс для дропдауна (он добавляется в body —
                    // стили списка скоупятся по нему)
                    dropdownCssClass: 'awz-allxls-select2-dropdown',
                    // быстрый поиск по наименованиям полей — всегда доступен
                    minimumResultsForSearch: 0,
                    placeholder: placeholder || (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_MAP_EMPTY') || '- выбрать поле -'),
                    allowClear: true,
                    language: 'ru'
                });
            } catch (e) {
                if (window.console && console.warn) console.warn('select2 init fail', e);
            }
        },

        /**
         * Заполнение всех уже созданных селектов сопоставления после загрузки полей
         */
        applyFieldsToAllSelects: function () {
            var self = this;
            var container = document.getElementById(this.containerId);
            if (window.console && console.info) {
                console.info('[awz.allxls] fields applied: container=' + !!container +
                    ' (id=' + this.containerId + ') groups=' + (this.fieldsGroups || []).length);
            }
            // контейнер не найден — обновляем все селекты в документе
            var root = container || document;
            var selects = root.querySelectorAll('.awz-allxls-grid__map-select');
            for (var i = 0; i < selects.length; i++) {
                // ошибка на одном select не должна останавливать обновление остальных
                try {
                    self.applyFieldsToSelect(selects[i]);
                } catch (e) {
                    if (window.console && console.warn) console.warn('applyFieldsToSelect fail', e);
                }
            }
        },

        /**
         * Простая транслитерация для алиаса колонки
         */
        translit: function (text) {
            var map = {
                'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y',
                'к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f',
                'х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'
            };
            return String(text).toLowerCase().split('').map(function (ch) {
                if (map[ch] !== undefined) return map[ch];
                if (/[a-z0-9]/.test(ch)) return ch;
                if (ch === ' ' || ch === '-' || ch === '_') return '_';
                return '';
            }).join('').replace(/_+/g, '_').replace(/^_|_$/g, '');
        },

        collect: function () {
            var container = document.getElementById(this.containerId);
            var sheets = [];
            var self = this;
            if (!container) return {id: this.id, sheets: sheets};

            this.sheets.forEach(function (sheet, index) {
                var activeCheck = container.querySelector('[data-sheet-active="' + index + '"]');
                var headerRowInput = container.querySelector('[data-sheet-header-row="' + index + '"]');
                var columns = {};
                (sheet.COLUMNS || []).forEach(function (col) {
                    var check = container.querySelector('[data-col-include="' + index + ':' + col.KEY + '"]');
                    var maps = [];
                    var mapSettings = [];
                    var mapWrap = container.querySelector('[data-col-maps="' + index + ':' + col.KEY + '"]');
                    if (mapWrap) {
                        var items = mapWrap.querySelectorAll('.awz-allxls-map-item');
                        for (var s = 0; s < items.length; s++) {
                            var sel = items[s].querySelector('select.awz-allxls-grid__map-select');
                            var val = sel ? BX.util.trim(sel.value) : '';
                            if (val === '') continue;
                            maps.push(val);
                            // индивидуальные настройки каждого сопоставления (из всплывающего окна)
                            var st = items[s]._mapSettings || {};
                            mapSettings.push({
                                TRIM: st.TRIM === 'N' ? 'N' : 'Y',
                                SKIP_EMPTY: st.SKIP_EMPTY === 'N' ? 'N' : 'Y',
                                CASE: st.CASE || '',
                                // применение поля: '' — новые+обновляемые, 'Y' — только новые, 'U' — только обновляемые
                                IS_NEW: (st.IS_NEW === 'Y' || st.IS_NEW === 'U') ? st.IS_NEW : '',
                                DELIMETER: st.DELIMETER || '',
                                // тип привязки значения свойства: '' — авто, id|xml_id|name|code
                                BIND: st.BIND || '',
                                // автосоздание отсутствующих значений списка (свойство L)
                                CREATE_ENUM: st.CREATE_ENUM === 'N' ? 'N' : 'Y',
                                // режим множественного свойства: '' — перезаписать, 'append' — дополнить, 'delete' — удалить значения
                                MULTI: (st.MULTI === 'append' || st.MULTI === 'delete') ? st.MULTI : '',
                                // поле раздела: создание отсутствующих и разделитель уровней
                                SECTION_CREATE: st.SECTION_CREATE === 'N' ? 'N' : 'Y',
                                SECTION_LEVEL_DELIM: st.SECTION_LEVEL_DELIM || '/',
                                SEARCH_USE: st.SEARCH_USE === 'Y' ? 'Y' : 'N',
                                SEARCH_MODE: st.SEARCH_MODE || 'eq',
                                REPLACE: (st.REPLACE || []).map(function (rule) {
                                    return {
                                        MODE: rule.MODE === 'neq' ? 'neq' : 'eq',
                                        FROM: rule.FROM || '',
                                        TO: rule.TO || ''
                                    };
                                }),
                                RULES: (st.RULES || []).map(function (rule) {
                                    var out = {
                                        TYPE: ['transform', 'skip', 'delete', 'unset', 'entity'].indexOf(rule.TYPE) >= 0 ? rule.TYPE : 'transform',
                                        AGG: ['AND', 'OR', 'AND_NOT', 'OR_NOT'].indexOf(rule.AGG) >= 0 ? rule.AGG : 'AND',
                                        CONDITIONS: Array.isArray(rule.CONDITIONS) ? rule.CONDITIONS : []
                                    };
                                    if (out.TYPE !== 'skip' && out.TYPE !== 'delete' && out.TYPE !== 'unset') {
                                        out.ACTION = rule.ACTION || 'replace';
                                        out.ACTION_VALUE = rule.ACTION_VALUE || '';
                                        out.ACTION_VALUE2 = rule.ACTION_VALUE2 || '';
                                    }
                                    return out;
                                })
                            });
                        }
                    }
                    columns[col.KEY] = {
                        INCLUDE: (check && !check.checked) ? 'N' : 'Y',
                        ALIAS: col.ALIAS || self.translit(col.HEADER || ''),
                        HEADER: col.HEADER || "",
                        LETTER: col.LETTER || "",
                        MAPS: maps,
                        MAP_SETTINGS: mapSettings
                    };
                });
                sheets.push({
                    NAME: sheet.NAME,
                    ACTIVE: (activeCheck && !activeCheck.checked) ? 'N' : 'Y',
                    HEADER_ROW: headerRowInput ? (parseInt(headerRowInput.value, 10) || 1) : 1,
                    COLUMNS: columns,
                    SAMPLE_COLS: Math.max(1, Math.min(10, parseInt(sheet._sampleCols, 10) || 1)),
                    SAMPLE_HIDDEN: Object.keys(sheet._sampleHidden || {})
                        .filter(function (idx) { return sheet._sampleHidden[idx]; })
                        .map(function (idx) { return parseInt(idx, 10); })
                });
            });

            return {id: this.id, sheets: sheets};
        },

        save: function (redirect) {
            if (this.saving) return;
            var self = this;
            var data = this.collect();

            this.saving = true;
            this.lock(true);

            BX.ajax.runAction(this.saveAction, {
                data: data
            }).then(function (response) {
                self.saving = false;
                self.lock(false);
                self.notify(BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SAVED') || 'Настройки сохранены', 'success');
                // переход к следующему шагу (этап 4 — обработка)
                var nextUrl = (response && response.data && response.data.nextStepUrl)
                    || '/bitrix/admin/awz_allxls_profiles_edit.php?id=' + self.id
                        + '&step=4&lang=' + (BX.message && BX.message('LANGUAGE_ID') ? BX.message('LANGUAGE_ID') : 'ru');
                if(redirect){
                    window.setTimeout(function () {
                        window.location.href = nextUrl;
                    }, 600);
                    // переход к следующему шагу (этап 4 — обработка)
                    window.setTimeout(function () {
                        window.location.href = '/bitrix/admin/awz_allxls_profiles_edit.php?id=' + self.id
                            + '&step=4&lang=' + (BX.message && BX.message('LANGUAGE_ID') ? BX.message('LANGUAGE_ID') : 'ru');
                    }, 600);
                }
            }, function (response) {
                self.saving = false;
                self.lock(false);
                self.notify(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_ERROR') || 'Ошибка сохранения'), 'error');
            });
        },

        lock: function (locked) {
            var btn = document.getElementById(this.saveBtnId);
            if (btn) btn.disabled = !!locked;
        },

        errorMessage: function (response, fallback) {
            var messages = [];
            if (response && response.errors) {
                for (var i = 0; i < response.errors.length; i++) {
                    var err = response.errors[i] || {};
                    // приоритет: текст ошибки, затем description, затем код —
                    // иначе при пустом message показывается только общий fallback
                    messages.push(err.message || err.error_description || err.error || err.code || '');
                }
            }
            return messages.join("\n") || fallback;
        },

        notify: function (message, type) {
            if (window.BX && BX.UI && BX.UI.Notification && BX.UI.Notification.Center) {
                BX.UI.Notification.Center.notify({
                    content: BX.util.htmlspecialchars(message),
                    autoHideDelay: 4000
                });
            } else {
                alert(message);
            }
        }
    };

    window.Awz.Allxls = window.Awz.Allxls || {};
    window.Awz.Allxls.ProfilesEditSettings = ProfilesEditSettings;

    /**
     * Копирует методы миксина в прототип основного класса
     */
    function applyMixin(proto, mixin) {
        for (var key in mixin) {
            if (Object.prototype.hasOwnProperty.call(mixin, key)) {
                proto[key] = mixin[key];
            }
        }
    }

    // расширяем прототип методами из отдельных файлов (колонки-примеры, диалог настроек сопоставления)
    if (window.Awz.Allxls.ProfilesEditSampleColumnsMixin) {
        applyMixin(ProfilesEditSettings.prototype, window.Awz.Allxls.ProfilesEditSampleColumnsMixin);
    }
    if (window.Awz.Allxls.ProfilesEditMapDialogMixin) {
        applyMixin(ProfilesEditSettings.prototype, window.Awz.Allxls.ProfilesEditMapDialogMixin);
    }
    if (window.Awz.Allxls.ProfilesEditNewPropMixin) {
        applyMixin(ProfilesEditSettings.prototype, window.Awz.Allxls.ProfilesEditNewPropMixin);
    }

})(window);
