/**
 * AWZ.AllXLS: шаг 3 — всплывающее окно настроек сопоставления (миксин).
 * Подключается после profiles_edit_step3.js и расширяет прототип
 * Awz.Allxls.ProfilesEditSettings.
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};
    window.Awz.Allxls = window.Awz.Allxls || {};

    var MapDialogMixin = {

        /**
         * Всплывающее окно настроек сопоставления (стандартный BX.PopupWindow).
         * HTML формы подгружается с контроллера по ID профиля и ID поля.
         */
        openMapSettingsDialog: function (item, gear, fieldValue, sheet, col, key) {
            var self = this;
            var popupId = 'awz-allxls-map-settings-' + this.id + '-' + fieldValue + '-' + Math.floor(Math.random() * 1e5);

            var content = document.createElement('div');
            content.innerHTML = '<div style="padding:30px;text-align:center;color:#8a94a6;">Загрузка...</div>';

            var popup = new BX.PopupWindow(popupId, null, {
                content: content,
                titleBar: 'Настройки поля',
                width: 900,
                overlay: {opacity: 40},
                draggable: true,
                closeByEsc: true,
                buttons: [
                    new BX.PopupWindowButton({
                        text: BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SAVE') || 'Сохранить',
                        className: 'ui-btn ui-btn-primary',
                        events: {
                            click: function () {
                                item._mapSettings = self.collectMapSettingsFromDialog(content);
                                if (self.updateMapItemInfo) self.updateMapItemInfo(item);
                                gear.classList.toggle(
                                    'awz-allxls-map-item__gear--active'
                                );
                                self.save();
                                popup.close();
                            }
                        }
                    }),
                    new BX.PopupWindowButtonLink({
                        text: BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_CANCEL') || 'Отменить',
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

            BX.ajax.runAction('awz:allxls.api.map.settings', {
                data: {
                    id: this.id,
                    from: col['KEY'],
                    // код листа (CSV / SHEET1 / SHEET2...), имя — только как fallback для старых данных
                    sheet: sheet['CODE'] || sheet['NAME'],
                    field: fieldValue,
                    key:key,
                    // актуальные колонки листа (настройки этапа 3 могут быть еще не сохранены)
                    columns: (sheet.COLUMNS || []).map(function (c) {
                        return {
                            KEY: c.KEY || '',
                            LETTER: c.LETTER || '',
                            HEADER: c.HEADER || '',
                            ALIAS: c.ALIAS || ''
                        };
                    })
                }
            }).then(function (response) {
                var data = response.data || {};
                var name = data.name || fieldValue;
                // заголовок окна: «Настройки поля "..."»
                if (popup.titleNode) {
                    popup.titleNode.textContent = 'Настройки поля "' + name + '"';
                }
                content.innerHTML = data.html || '';
                self.bindMapDialogEvents(content);
            }, function (response) {
                content.innerHTML = '<div style="padding:20px;color:#d14;">' +
                    BX.util.htmlspecialchars(self.errorMessage(response, 'Ошибка загрузки настроек')) +
                    '</div>';
            });
        },

        /**
         * Обработчики внутри окна настроек.
         * Правила обработки рендерятся стандартным конструктором BX.TreeConditions
         * (ext awz_core_condtree модуля awz.admin, как в awz.autform/options.php).
         */
        bindMapDialogEvents: function (content) {
            var self = this;

            // выполняем inline-скрипты (innerHTML их не исполняет) —
            // они наполняют очередь AwzAllxlsMapCondQueue конфигами деревьев
            Array.prototype.slice.call(content.querySelectorAll('script')).forEach(function (s) {
                if (!s.textContent) return;
                try {
                    (new Function(s.textContent)).call(window);
                } catch (e) {
                    console.error('map dialog script error', e);
                }
            });

            var initTrees = function () {
                var queue = window.AwzAllxlsMapCondQueue || [];
                window.AwzAllxlsMapCondQueue = [];
                queue.forEach(function (cfg) {
                    self.initMapCondTree(content, cfg);
                });
            };

            initTrees();
            self.bindMapSearchModeVisibility(content);
            self.bindMapToggleGroups(content);
            self.bindMapUniqueCodeWarn(content);
            //if (window.BX && BX.TreeConditions) {
            //    initTrees();
            //} else {
                // подгружаем конструктор условий модуля awz.admin
            //    BX.load([
            //        //'/bitrix/modules/awz.admin/lang/ru/conditions.php',
            //        '/bitrix/js/awz.admin/core_tree.js',
            //        '/bitrix/panel/awz.admin/catalog_cond.css'
            //    ], initTrees);
            //}
        },

        /**
         * Режим поиска (MAP_SEARCH_MODE) показывается только при
         * активном чекбоксе «Использовать для поиска элемента».
         */
        bindMapSearchModeVisibility: function (content) {
            var cb = content.querySelector('input[name="MAP_SEARCH_USE"]');
            var sel = content.querySelector('select[name="MAP_SEARCH_MODE"]');
            if (!cb || !sel) return;
            var apply = function () {
                sel.style.display = cb.checked ? '' : 'none';
            };
            BX.bind(cb, 'change', apply);
            apply();
        },

        /**
         * Информационный варнинг для действия «сгенерировать уникальный
         * символьный код» (unique_code): показывается, когда действие
         * выбрано хотя бы в одном правиле преобразования.
         * Обновляется при кликах и изменениях в диалоге (селекты дерева
         * условий перерисовываются BX.TreeConditions, поэтому ловим
         * события делегированием + наблюдаем за DOM).
         */
        bindMapUniqueCodeWarn: function (content) {
            var warn = content.querySelector('[data-unique-code-warn]');
            if (!warn) return;

            var update = function () {
                var hasUnique = false;
                // селекты действия правил в дереве условий (имена вида maprule[...][action])
                Array.prototype.slice.call(content.querySelectorAll('select[name$="[action]"]')).forEach(function (sel) {
                    if ((sel.value || '') === 'unique_code') hasUnique = true;
                });
                warn.style.display = hasUnique ? '' : 'none';
            };

            BX.bind(content, 'click', update);
            BX.bind(content, 'change', update);
            if (typeof MutationObserver === 'function') {
                var mo = new MutationObserver(update);
                mo.observe(content, {childList: true, subtree: true});
            }
            update();
        },

        /**
         * Группы кнопок-переключателей (режимы [data-toggle-group]):
         *  - single — активна ровно одна кнопка, в hidden пишется её data-value;
         *  - multi  — независимые кнопки, значение hidden собирается из набора
         *    (для IS_NEW: обе/ни одной -> '', только Новые -> 'Y', только
         *    Обновляемые -> 'U'; выключить последнюю активную нельзя).
         */
        bindMapToggleGroups: function (content) {
            var activeClass = 'awz-allxls-mapdlg__toggle--active';

            Array.prototype.slice.call(content.querySelectorAll('[data-toggle-group]')).forEach(function (group) {
                var hidden = content.querySelector('[name="' + group.getAttribute('data-toggle-target') + '"]');
                if (!hidden) return;

                var btns = Array.prototype.slice.call(group.querySelectorAll('.awz-allxls-mapdlg__toggle'));
                var mode = group.getAttribute('data-toggle-group');

                var syncHidden = function () {
                    if (mode === 'single') {
                        var active = group.querySelector('.' + activeClass);
                        hidden.value = active ? (active.getAttribute('data-value') || '') : '';
                        return;
                    }
                    var y = false, u = false;
                    btns.forEach(function (b) {
                        if (!b.classList.contains(activeClass)) return;
                        if (b.getAttribute('data-value') === 'Y') y = true;
                        if (b.getAttribute('data-value') === 'U') u = true;
                    });
                    hidden.value = (y && !u) ? 'Y' : ((u && !y) ? 'U' : '');
                };

                btns.forEach(function (btn) {
                    BX.bind(btn, 'click', function () {
                        if (mode === 'single') {
                            btns.forEach(function (b) { b.classList.remove(activeClass); });
                            btn.classList.add(activeClass);
                        } else {
                            if (btn.classList.contains(activeClass)) {
                                // не даём выключить последнюю активную кнопку
                                var othersActive = btns.some(function (b) {
                                    return b !== btn && b.classList.contains(activeClass);
                                });
                                if (!othersActive) return;
                                btn.classList.remove(activeClass);
                            } else {
                                btn.classList.add(activeClass);
                            }
                        }
                        syncHidden();
                    });
                });
            });
        },

        /**
         * Инициализация одного дерева условий в окне
         */
        initMapCondTree: function (content, cfg) {
            var self = this;
            var container = content.querySelector('#' + cfg.container) || document.getElementById(cfg.container);
            var form = content.querySelector('#' + cfg.form) || document.getElementById(cfg.form);
            if (!container || !form || !BX.TreeConditions) return;

            cfg._content = content;
            cfg._form = form;

            let k1, k2;
            for(k1 in cfg.rules){
                // 1. Добавляем события для управления видимостью контролов
                cfg.rules[k1]['events'] = {
                    // onInit срабатывает при первой загрузке (скрываем, если значение уже было сохранено в БД)
                    onChange: function() {
                       console.log('test');
                    },
                };
            }

            //btnvalues
            // в этом диалоге добавляются правила (действия), а не условия
            if (BX.message) {
                BX.message({'JC_CORE_TREE_SELECT_CONTROL': 'Выберите действие'});
            }
            cfg._tree = new BX.TreeConditions({
                'parentContainer': cfg.container,
                'form': cfg.form,
                'formName': cfg.form,
                'sepID': '__',
                'prefix': cfg.prefix
            }, cfg.values, cfg.rules);

            // кнопки макросов и поиск в селектах должны появляться и у
            // добавленных позже правил/условий: следим за появлением
            // новых input/select в дереве
            var treeContainer = container;
            if (typeof MutationObserver === 'function') {
                var mo = new MutationObserver(function () {
                    var need = Array.prototype.some.call(
                        treeContainer.querySelectorAll('.condition-wrapper input[type="text"]'),
                        function (i) { return i.getAttribute('data-macro-btn') !== 'Y'; }
                    );
                    if (need) self.appendMapCondMacros(content, cfg);
                });
                mo.observe(treeContainer, {childList: true, subtree: true});
            }

            // ссылки сортировки правил вверх/вниз (как в awz.autform/options.php, но без перезагрузки)
            setTimeout(function () {
                self.appendMapCondSorters(content, cfg);
                self.appendMapCondMacros(content, cfg);
                self.appendMapCondClipboard(content, cfg);
                self.bindMapCondOpVisibility(content, cfg);
            }, 0);
        },

        /**
         * Иконки «Копировать правила» / «Вставить правила» рядом с деревом правил.
         * Правила передаются JSON-структурой (RULES): копирование — в буфер
         * обмена + модальное окно с текстом (для ручного переноса между
         * браузерами/профилями), вставка — из внутреннего буфера или из окна.
         */
        appendMapCondClipboard: function (content, cfg) {
            var self = this;
            var container = content.querySelector('#' + cfg.container) || document.getElementById(cfg.container);
            if (!container || container.parentNode.querySelector('[data-map-clipboard]')) return;

            var bar = document.createElement('div');
            bar.setAttribute('data-map-clipboard', 'Y');
            bar.style.cssText = 'display:flex;gap:8px;align-items:center;margin:6px 0;';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'awz-allxls-btn awz-allxls-btn--ghost';
            copyBtn.style.cssText = 'padding:2px 8px;font-size:11px;';
            copyBtn.textContent = '⧉ Копировать правила';
            copyBtn.title = 'Скопировать правила обработки (JSON) — можно вставить в настройки другого поля/колонки/профиля';
            BX.bind(copyBtn, 'click', function () {
                var rules = self.collectMapRulesFromDialog(content);
                if (!rules.length) {
                    alert('В этом сопоставлении нет правил для копирования');
                    return;
                }
                var json = JSON.stringify(rules);
                // внутренний буфер модуля (между полями/колонками этого браузера)
                try { window.localStorage.setItem('awz_allxls_rules_clipboard', json); } catch (e) {}
                self.showMapRulesClipboardPopup('Копирование правил', json, false);
            });
            bar.appendChild(copyBtn);

            var pasteBtn = document.createElement('button');
            pasteBtn.type = 'button';
            pasteBtn.className = 'awz-allxls-btn awz-allxls-btn--ghost';
            pasteBtn.style.cssText = 'padding:2px 8px;font-size:11px;';
            pasteBtn.textContent = '📋 Вставить правила';
            pasteBtn.title = 'Вставить правила обработки из буфера (JSON)';
            BX.bind(pasteBtn, 'click', function () {
                var stored = '';
                try { stored = window.localStorage.getItem('awz_allxls_rules_clipboard') || ''; } catch (e) {}
                self.showMapRulesClipboardPopup('Вставка правил', stored, true, function (json) {
                    var rules;
                    try {
                        rules = JSON.parse(json);
                    } catch (e) {
                        alert('Некорректный JSON правил: ' + e.message);
                        return;
                    }
                    if (!Array.isArray(rules)) {
                        alert('Ожидался массив правил (JSON-массив)');
                        return;
                    }
                    if (!rules.length) return;
                    // пересобираем дерево с новыми правилами
                    cfg.values = self.buildMapCondValues(rules);
                    var treeContainer = content.querySelector('#' + cfg.container) || document.getElementById(cfg.container);
                    if (treeContainer) treeContainer.innerHTML = '';
                    self.initMapCondTree(content, cfg);
                    setTimeout(function () {
                        self.appendMapCondSorters(content, cfg);
                        self.appendMapCondMacros(content, cfg);
                        self.appendMapCondClipboard(content, cfg);
                        self.bindMapCondOpVisibility(content, cfg);
                    }, 0);
                });
            });
            bar.appendChild(pasteBtn);

            // размещаем панель перед деревом правил
            container.parentNode.insertBefore(bar, container);
        },

        /**
         * Модальное окно с JSON правил: просмотр/копирование (readonly) или
         * вставка (редактируемое поле + кнопка «Вставить»).
         */
        showMapRulesClipboardPopup: function (title, json, editable, onPaste) {
            var popupId = 'awz-allxls-rules-clipboard-' + Math.floor(Math.random() * 1e5);
            var wrap = document.createElement('div');
            wrap.style.cssText = 'padding:12px;min-width:480px;';

            var hint = document.createElement('div');
            hint.style.cssText = 'margin-bottom:8px;color:#667; font-size:12px;';
            hint.textContent = editable
                ? 'Вставьте JSON-массив правил (можно взять из окна «Копировать правила» другого поля/профиля):'
                : 'Скопируйте текст ниже (Ctrl+C) и сохраните — это правила обработки в формате JSON:';
            wrap.appendChild(hint);

            var ta = document.createElement('textarea');
            ta.style.cssText = 'width:100%;height:220px;font-family:monospace;font-size:12px;box-sizing:border-box;';
            ta.value = json || '';
            ta.readOnly = !editable;
            wrap.appendChild(ta);

            var buttons = [
                new BX.PopupWindowButton({
                    text: editable ? 'Вставить' : 'Скопировать в буфер',
                    className: 'ui-btn ui-btn-primary',
                    events: {
                        click: function () {
                            var text = ta.value;
                            if (editable) {
                                if (onPaste) onPaste(text);
                            } else {
                                // современный Clipboard API с fallback на выделение
                                if (navigator.clipboard && navigator.clipboard.writeText) {
                                    navigator.clipboard.writeText(text);
                                } else {
                                    ta.select();
                                    try { document.execCommand('copy'); } catch (e) {}
                                }
                            }
                            popup.close();
                        }
                    }
                }),
                new BX.PopupWindowButtonLink({
                    text: 'Отменить',
                    className: 'ui-btn ui-btn-link',
                    events: {click: function () { popup.close(); }}
                })
            ];

            var popup = new BX.PopupWindow(popupId, null, {
                content: wrap,
                titleBar: title,
                width: 560,
                overlay: {opacity: 40},
                draggable: true,
                closeByEsc: true,
                buttons: buttons
            });
            popup.show();
            if (editable) ta.focus();
        },

        /**
         * Показ/скрытие полей значения условия в зависимости от выбранного оператора (op):
         *  - empty / not_empty / any — поле value скрывается;
         *  - between — показывается дополнительное поле value2, иначе оно скрывается.
         * (visual-конфиги в rules не работают: core_tree.js читает visual
         *  только у контролов с group=true, а правила здесь group=false.)
         */
        bindMapCondOpVisibility: function (content, cfg) {

            // контрол ищется только среди прямых потомков строки,
            // чтобы не задевать контролы вложенных условий
            function directChild(node, selector) {
                var all = node.querySelectorAll(selector);
                for (var i = 0; i < all.length; i++) {
                    if (all[i].parentNode === node) return all[i];
                }
                return null;
            }

            var rows = content.querySelectorAll(
                '#' + cfg.container + ' .condition-wrapper .condition-simple-control, ' +
                '#' + cfg.container + ' .condition-wrapper .condition-container'
            );
            Array.prototype.slice.call(rows).forEach(function (row) {

                var applyToAnchor = function (input, op, attr) {
                    var link = input && input.previousElementSibling;
                    if (link && link.tagName === 'A') {
                        link.setAttribute(attr, op);
                    }
                };

                //return;
                // видимость value/value2 в зависимости от оператора
                var opSelect = directChild(row, 'select[name$="[op]"]');
                if (opSelect && opSelect.getAttribute('data-op-visibility') !== 'Y') {
                    opSelect.setAttribute('data-op-visibility', 'Y');
                    var valueInput = directChild(row, 'input[name$="[value]"]');
                    var value2Input = directChild(row, 'input[name$="[value2]"]');
                    var applyOp = function () {
                        var op = opSelect.value;
                        if (valueInput) {
                            valueInput.setAttribute('data-op', op);
                            applyToAnchor(valueInput, op, 'data-op');
                            applyToAnchor(valueInput, 'value', 'data-inp');
                        }
                        if (value2Input) {
                            value2Input.setAttribute('data-op', op);
                            applyToAnchor(value2Input, op, 'data-op');
                            applyToAnchor(value2Input, 'value2', 'data-inp');
                        }
                    };
                    BX.bind(opSelect, 'change', applyOp);
                    applyOp();
                }

                // видимость aval/aval2 в зависимости от действия
                var actionSelect = directChild(row, 'select[name$="[action]"]');
                if (actionSelect && actionSelect.getAttribute('data-action-visibility') !== 'Y') {
                    actionSelect.setAttribute('data-action-visibility', 'Y');
                    var avalInput = directChild(row, 'input[name$="[aval]"]');
                    var aval2Input = directChild(row, 'input[name$="[aval2]"]');
                    var applyAction = function () {
                        var a = actionSelect.value;
                        if (avalInput) {
                            avalInput.setAttribute('data-a', a);
                            applyToAnchor(avalInput, a, 'data-a');
                            applyToAnchor(avalInput, 'aval', 'data-inp');
                        }
                        if (aval2Input) {
                            aval2Input.setAttribute('data-a', a);
                            applyToAnchor(aval2Input, a, 'data-a');
                            applyToAnchor(aval2Input, 'aval2', 'data-inp');
                        }
                    };
                    BX.bind(actionSelect, 'change', applyAction);
                    applyAction();
                }
            });
        },

        /**
         * Добавляет кнопку «...» рядом с текстовыми полями правил.
         * Кнопка открывает меню предустановленных макросов,
         * выбранный макрос вставляется в поле (в позицию курсора).
         */
        appendMapCondMacros: function (content, cfg) {

            var self = this;
            var macros = cfg.macros || {};
            var codes = Object.keys(macros);
            if (!codes.length) return;

            var inputs = content.querySelectorAll(
                '#' + cfg.container + ' .condition-wrapper input[type="text"]'
            );
            Array.prototype.slice.call(inputs).forEach(function (input) {
                if (input.getAttribute('data-macro-btn') === 'Y') return;
                input.setAttribute('data-macro-btn', 'Y');

                var btn = document.createElement('a');
                btn.href = 'javascript:void(0)';
                btn.className = 'awz-allxls-mapdlg__macro-btn';
                btn.textContent = '...';
                btn.title = 'Вставить макрос';

                BX.bind(btn, 'click', function () {
                    var menuId = 'awz-map-macro-' + Math.floor(Math.random() * 1e6);
                    var items = [];
                    // строка поиска: фильтрует пункты меню по подстроке
                    items.push({
                        html: '<div class="awz-allxls-mapdlg__macro-search" style="padding:6px 12px;">'
                            + '<input type="text" placeholder="Поиск..." style="width:100%;box-sizing:border-box;padding:4px;">'
                            + '</div>',
                        id: 'search_field',
                        noHover: true
                    });
                    codes.forEach(function (code) {
                        items.push({
                            text: code + ' — ' + macros[code],
                            className: 'awz-allxls-mapdlg__macro-item',
                            onclick: function () {
                                self.insertMapMacro(input, code);
                                var menu = BX.PopupMenu.getCurrent();
                                if (menu) menu.close();
                            }
                        });
                    });
                    BX.PopupMenu.show(menuId, btn, items, {
                        autoHide: true,
                        offsetLeft: 0,
                        offsetTop: 5,
                        events: {
                            onPopupShow: function (popupWindow) {
                                var container = popupWindow.getPopupContainer();
                                var searchInput = container.querySelector('.awz-allxls-mapdlg__macro-search input');
                                if (!searchInput) return;
                                // фокус на поле ввода при открытии
                                setTimeout(function () { searchInput.focus(); }, 50);
                                // фильтрация пунктов по вводу текста
                                BX.bind(searchInput, 'input', function () {
                                    var filter = searchInput.value.toLowerCase();
                                    var menuItems = container.querySelectorAll('.awz-allxls-mapdlg__macro-item');
                                    Array.prototype.slice.call(menuItems).forEach(function (item) {
                                        var text = item.textContent || item.innerText || '';
                                        item.style.display = (text.toLowerCase().indexOf(filter) > -1) ? '' : 'none';
                                    });
                                });
                            },
                            onPopupClose: function () {
                                input.focus();
                            }
                        }
                    });
                });

                input.parentNode.insertBefore(btn, input.nextSibling);
            });
        },

        /**
         * Селекты дерева условий — стандартные (без select2).
         * Функция оставлена для совместимости: уничтожает select2,
         * если он был инициализирован (старые данные/кеш JS).
         */
        initCondSelectsSearch: function (content, cfg) {
            if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) return;
            var container = content.querySelector('#' + cfg.container) || document.getElementById(cfg.container);
            if (!container) return;
            Array.prototype.slice.call(container.querySelectorAll('.condition-wrapper select')).forEach(function (select) {
                var $sel = window.jQuery(select);
                if ($sel.data('select2')) {
                    try { $sel.select2('destroy'); } catch (e) {}
                }
            });
        },

        /**
         * Вставка макроса в input в позиции курсора (заменяя выделение)
         */
        insertMapMacro: function (input, code) {
            var start = (typeof input.selectionStart === 'number') ? input.selectionStart : input.value.length;
            var end = (typeof input.selectionEnd === 'number') ? input.selectionEnd : start;
            input.value = input.value.substring(0, start) + code + input.value.substring(end);
            // позиция курсора после вставленного макроса
            if (typeof input.setSelectionRange === 'function') {
                input.setSelectionRange(start + code.length, start + code.length);
            }
            // уведомляем дерево условий об изменении значения
            BX.fireEvent(input, 'change');
        },

        hideControlsIfNeeded: function (conditionContext, currentValues) {
            console.log(conditionContext, currentValues);
            // conditionContext — это контекст строки (this из событий Битрикса)
            if (!conditionContext.DOM || !conditionContext.DOM.row) return;

            // 1. Получаем текущие значения элементов в строке
            // Если функция вызвана из onInit, берём сохраненные значения через встроенный метод GetValue()
            var values = currentValues || (typeof conditionContext.GetValue === 'function' ? conditionContext.GetValue() : {});

            // 2. Проверяем наше условие. Например, проверяем, заполнено ли поле 'btnvalues'
            // (Имя ключа в объекте values соответствует id вашего контрола)
            var hasValue = values && values['btnvalues'] && values['btnvalues'] !== '';

            // 3. Находим контрол, который нужно скрыть (например, контрол с id "other_control")
            // Используем поиск по частичному совпадению *= из-за суффиксов Битрикса
            var controlToHide = conditionContext.DOM.row.querySelector('[data-id*="other_control"]');

            if (controlToHide) {
                if (hasValue) {
                    // Если значение ЕСТЬ — скрываем контрол
                    controlToHide.style.display = 'none';
                } else {
                    // Если значения НЕТ (пусто) — показываем обратно
                    controlToHide.style.display = 'inline-block';
                }
            }
        },

        /**
         * Добавляет ссылки «выше»/«ниже» к правилам верхнего уровня.
         * Сортировка выполняется на клиенте: значения пересобираются,
         * дерево переинициализируется в новом порядке.
         * Сортируются только правила (controlId начинается с "rule"),
         * вложенные условия и группы условий не сортируются.
         */
        appendMapCondSorters: function (content, cfg) {
            var self = this;
            var containers = content.querySelectorAll(
                '#' + cfg.container + ' .condition-wrapper .condition-container'
            );
            var ruleRows = [];
            Array.prototype.slice.call(containers).forEach(function (node) {
                var cidInput = node.querySelector('input[name$="[controlId]"]');
                if (cidInput && (cidInput.value || '').indexOf('rule') === 0) {
                    ruleRows.push(node);
                }
            });

            ruleRows.forEach(function (node, index) {
                var up = document.createElement('a');
                up.href = 'javascript:void(0)';
                up.className = 'awz-allxls-mapdlg__sort awz-allxls-mapdlg__sort-up';
                up.textContent = '↑';
                BX.bind(up, 'click', function () {
                    self.moveMapCondRule(content, cfg, index, index - 1);
                });

                var down = document.createElement('a');
                down.href = 'javascript:void(0)';
                down.className = 'awz-allxls-mapdlg__sort awz-allxls-mapdlg__sort-down';
                down.textContent = '↓';
                BX.bind(down, 'click', function () {
                    self.moveMapCondRule(content, cfg, index, index + 1);
                });

                node.appendChild(up);
                node.appendChild(down);
            });
        },

        /**
         * Перемещение правила и переинициализация дерева
         */
        moveMapCondRule: function (content, cfg, from, to) {
            var rules = this.collectMapRulesFromDialog(content);
            if (from < 0 || from >= rules.length) return;
            if (to < 0 || to >= rules.length) return;

            var moved = rules.splice(from, 1)[0];
            rules.splice(to, 0, moved);

            // пересобираем значения и переинициализируем дерево
            cfg.values = this.buildMapCondValues(rules);
            var container = content.querySelector('#' + cfg.container) || document.getElementById(cfg.container);
            if (container) container.innerHTML = '';
            this.initMapCondTree(content, cfg);
        },

        /**
         * Сбор правил обработки из дерева условий в структуру settings.RULES.
         * Дерево обходится по id узлов (разделитель __), вложенность без ограничений.
         *
         * Формат правила:
         * {TYPE, AGG, CONDITIONS: [{SOURCE,OP,VALUE,VALUE2} | {AGG, CHILDREN:[...]}],
         *  ACTION, ACTION_VALUE, ACTION_VALUE2}
         */
        collectMapRulesFromDialog: function (content) {
            var form = content.querySelector('form[data-map-cond-form]');
            if (!form) return [];

            var prefix = 'maprule';
            var re = new RegExp('^' + prefix + '\\[(.+?)\\]\\[(.+?)\\]$');
            var nodes = {};

            Array.prototype.slice.call(form.querySelectorAll('input, select, textarea')).forEach(function (el) {
                var m = (el.name || '').match(re);
                if (!m) return;
                var key = m[1], field = m[2], val;
                if (el.type === 'checkbox') {
                    val = el.checked ? 'Y' : 'N';
                } else if (el.type === 'radio') {
                    if (!el.checked) return;
                    val = el.value;
                } else {
                    val = el.value;
                }
                if (!nodes[key]) nodes[key] = {};
                nodes[key][field] = val;
            });

            var controlIdMap = {
                ruleTransform: 'transform',
                ruleSkip: 'skip',
                ruleDelete: 'delete',
                ruleUnset: 'unset',
                ruleEntity: 'entity'
            };

            // прямые потомки узла (id вида parent__N, без дополнительной вложенности)
            function directChildren(parentId) {
                var res = [], p = parentId + '__';
                Object.keys(nodes).forEach(function (id) {
                    if (id.indexOf(p) === 0 && id.substring(p.length).indexOf('__') < 0) {
                        res.push(id);
                    }
                });
                res.sort(function (a, b) {
                    return (parseInt(a.substring(p.length), 10) || 0) - (parseInt(b.substring(p.length), 10) || 0);
                });
                return res;
            }

            function normAgg(v) {
                // fLevel (name=aggregator): AND/OR; notLevel (name=not): N/Y
                var f = (v.aggregator === 'OR' || v.fLevel === 'OR') ? 'OR' : 'AND';
                var not = (v.not === 'Y' || v.notLevel === 'Y') ? '_NOT' : '';
                return f + not;
            }

            function convert(id) {
                var v = nodes[id] || {};
                var cid = v.controlId || '';
                if (cid.indexOf('rule') === 0) {
                    var type = controlIdMap[cid] || 'transform';
                    var rule = {
                        TYPE: type,
                        AGG: normAgg(v),
                        CONDITIONS: directChildren(id).map(convert)
                    };
                    if (type !== 'skip' && type !== 'delete' && type !== 'unset') {
                        rule.ACTION = v.action || 'replace';
                        rule.ACTION_VALUE = v.aval || '';
                        rule.ACTION_VALUE2 = v.aval2 || '';
                    }
                    return rule;
                }
                if (cid === 'condGroup') {
                    return {
                        AGG: normAgg(v),
                        CHILDREN: directChildren(id).map(convert)
                    };
                }
                // лист-условие
                return {
                    SOURCE: v.source || '',
                    OP: v.op || 'eq',
                    VALUE: v.value || '',
                    VALUE2: v.value2 || ''
                };
            }

            return directChildren('0').map(convert);
        },

        /**
         * Построение значений дерева условий из массива правил
         * (зеркало Awz\Allxls\Api\Map::buildCondValues)
         */
        buildMapCondValues: function (rules) {
            var controlIdMap = {
                transform: 'ruleTransform',
                skip: 'ruleSkip',
                delete: 'ruleDelete',
                unset: 'ruleUnset',
                entity: 'ruleEntity'
            };

            function aggValues(agg) {
                var a = agg || 'AND';
                return {
                    fLevel: (a === 'OR' || a === 'OR_NOT') ? 'OR' : 'AND',
                    notLevel: (a === 'AND_NOT' || a === 'OR_NOT') ? 'Y' : 'N'
                };
            }

            function condNodes(conditions) {
                return (conditions || []).map(function (c, j) {
                    var id = String(j);
                    if (c && c.CHILDREN) {
                        return {
                            id: id,
                            controlId: 'condGroup',
                            values: aggValues(c.AGG),
                            children: condNodes(c.CHILDREN)
                        };
                    }
                    return {
                        id: id,
                        controlId: 'cond',
                        values: {
                            source: (c && c.SOURCE) || '',
                            op: (c && c.OP) || 'eq',
                            value: (c && c.VALUE) || '',
                            value2: (c && c.VALUE2) || ''
                        },
                        children: []
                    };
                });
            }

            var children = (rules || []).map(function (r, i) {
                var controlId = controlIdMap[r.TYPE] || 'ruleTransform';
                var values = aggValues(r.AGG);
                if (r.TYPE !== 'skip' && r.TYPE !== 'delete' && r.TYPE !== 'unset') {
                    values.action = r.ACTION || 'replace';
                    values.aval = r.ACTION_VALUE || '';
                    values.aval2 = r.ACTION_VALUE2 || '';
                }
                return {
                    id: String(i),
                    controlId: controlId,
                    values: values,
                    children: condNodes(r.CONDITIONS)
                };
            });

            return {id: '0', controlId: 'CondGroup', values: [], children: children};
        },

        /**
         * Сбор настроек из формы всплывающего окна.
         * Значения собираются динамически по всем полям внутри content
         * (без фиксированного списка имён).
         */
        collectMapSettingsFromDialog: function (content) {
            var settings = {};
            var rules = [];

            Array.prototype.slice.call(content.querySelectorAll('input, select, textarea')).forEach(function (field) {
                var name = field.name;
                if (!name) return;

                // правила замены собираются отдельно, в структуру REPLACE
                if (name.indexOf('MAP_RULE_') === 0) return;

                // поля дерева условий собираются отдельно в RULES
                if (name.indexOf('maprule[') === 0) return;

                // нормализация имени: префикс MAP_ убираем,
                // чтобы ключи совпадали с сохраняемыми настройками (TRIM, SEARCH_USE и т.д.)
                if (name.indexOf('MAP_') === 0) name = name.substring(4);

                var value;
                if (field.type === 'checkbox') {
                    value = field.checked ? 'Y' : 'N';
                } else if (field.type === 'radio') {
                    if (!field.checked) return;
                    value = field.value;
                } else if (field.type === 'select-multiple') {
                    value = Array.prototype.slice.call(field.selectedOptions).map(function (opt) {
                        return opt.value;
                    });
                } else {
                    value = BX.util.trim(field.value);
                }

                // несколько полей с одинаковым именем — собираем в массив
                if (Object.prototype.hasOwnProperty.call(settings, name)) {
                    if (!Array.isArray(settings[name])) {
                        settings[name] = [settings[name]];
                    }
                    if (Array.isArray(value)) {
                        settings[name] = settings[name].concat(value);
                    } else {
                        settings[name].push(value);
                    }
                } else {
                    settings[name] = value;
                }
            });

            // правила обработки собираются отдельно
            settings.RULES = this.collectMapRulesFromDialog(content);

            return settings;
        }
    };

    window.Awz.Allxls.ProfilesEditMapDialogMixin = MapDialogMixin;

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

    // если основной класс уже загружен — сразу расширяем прототип
    if (window.Awz.Allxls.ProfilesEditSettings) {
        applyMixin(window.Awz.Allxls.ProfilesEditSettings.prototype, MapDialogMixin);
    }

})(window);
