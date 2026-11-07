/**
 * AWZ.AllXLS: шаг 3 — создание нового свойства инфоблока из select сопоставления.
 * Миксин: расширяет Awz.Allxls.ProfilesEditSettings.
 *
 * В select сопоставления добавляется группа «Создать новое свойство»
 * с опциями по типам (строка, число, список, файл, привязка к элементам,
 * привязка к разделам, пользовательские HTML). По выбору запрашивается
 * название и открывается стандартный диалог Битрикса
 * /bitrix/admin/iblock_edit_property.php (bxpublic=Y, receiver=obAwzAllxlsProps)
 * с заранее переданным типом (тип внутри окна не меняется).
 *
 * Ресивер obAwzAllxlsProps (Proxy — принимает любой вызов диалога):
 * полученные значения отправляются в awz:allxls.api.profiles.createproperty
 * (создание по API, idempotent по коду). Fallback после закрытия диалога —
 * проверка awz:allxls.api.profiles.propertycheck по коду и перезагрузка полей.
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};
    window.Awz.Allxls = window.Awz.Allxls || {};

    var NEW_PROP_TYPES = {
        'S': 'Строка',
        'N': 'Число',
        'L': 'Список',
        'F': 'Файл',
        'E': 'Привязка к элементам',
        'G': 'Привязка к разделам',
        'HTML': 'Пользовательские HTML'
    };

    // PROP[PROPINFO] — base64_encode(serialize(умолчания формы свойства)):
    // IBLOCK_ID=0, LIST_TYPE=L, ROW_COUNT=1, COL_COUNT=30, LINK_IBLOCK_ID=0,
    // MULTIPLE_CNT=5, SECTION_PROPERTY=Y, SMART_FILTER=N, DISPLAY_EXPANDED=N и т.д.
    var PROPINFO_BASE64 = 'YToyMTp7czo5OiJJQkxPQ0tfSUQiO2k6MDtzOjk6IkZJTEVfVFlQRSI7czowOiIiO3M6OToiTElTVF9UWVBFIjtzOjE6IkwiO3M6OToiUk9XX0NPVU5UIjtzOjE6IjEiO3M6OToiQ09MX0NPVU5UIjtzOjI6IjMwIjtzOjE0OiJMSU5LX0lCTE9DS19JRCI7czoxOiIwIjtzOjEzOiJERUZBVUxUX1ZBTFVFIjtzOjA6IiI7czoxODoiVVNFUl9UWVBFX1NFVFRJTkdTIjthOjA6e31zOjE2OiJXSVRIX0RFU0NSSVBUSU9OIjtzOjA6IiI7czoxMDoiU0VBUkNIQUJMRSI7czowOiIiO3M6OToiRklMVFJBQkxFIjtzOjA6IiI7czoxMjoiTVVMVElQTEVfQ05UIjtzOjE6IjUiO3M6NDoiSElOVCI7czowOiIiO3M6NjoiWE1MX0lEIjtzOjA6IiI7czo2OiJWQUxVRVMiO2E6MDp7fXM6MTY6IlNFQ1RJT05fUFJPUEVSVFkiO3M6MToiWSI7czoxMjoiU01BUlRfRklMVEVSIjtzOjE6Ik4iO3M6MTI6IkRJU1BMQVlfVFlQRSI7czowOiIiO3M6MTY6IkRJU1BMQVlfRVhQQU5ERUQiO3M6MToiTiI7czoxMToiRklMVEVSX0hJTlQiO3M6MDoiIjtzOjg6IkZFQVRVUkVTIjthOjA6e319';

    var NewPropMixin = {

        /**
         * Добавление группы «Создать новое свойство» в select сопоставления.
         * Вызывается из applyFieldsToSelect после заполнения групп полей.
         */
        appendNewPropGroup: function (select) {
            if (!select || this.iblockId <= 0) return;
            // убираем ранее добавленную группу (перерисовка select2)
            var olds = select.querySelectorAll('optgroup[data-awz-new-prop]');
            for (var i = olds.length - 1; i >= 0; i--) olds[i].parentNode.removeChild(olds[i]);

            var group = document.createElement('optgroup');
            group.setAttribute('label', 'Создать новое свойство');
            group.setAttribute('data-awz-new-prop', 'Y');
            Object.keys(NEW_PROP_TYPES).forEach(function (code) {
                var opt = document.createElement('option');
                opt.value = '__new:' + code;
                opt.textContent = NEW_PROP_TYPES[code];
                group.appendChild(opt);
            });
            select.appendChild(group);

            var self = this;
            // select2 4.x триггерит change только через jQuery, а не нативно —
            // вешаем обработчик на оба механизма (повторный вход блокируется)
            BX.bind(select, 'change', function () {
                self.onMapSelectChange(select);
            });
            if (window.jQuery) {
                window.jQuery(select).on('change', function () {
                    self.onMapSelectChange(select);
                });
            }
        },

        /**
         * Название колонки (заголовок файла) для текущего select —
         * подставляется по умолчанию в название нового свойства.
         */
        getColumnHeader: function (select) {
            var wrap = select.closest ? select.closest('[data-col-maps]') : null;
            if (!wrap) return '';
            var parts = (wrap.getAttribute('data-col-maps') || '').split(':');
            var sheet = this.sheets[parseInt(parts[0], 10)];
            if (!sheet || !sheet._rows || !sheet._rows[parts[1]]) return '';
            var col = sheet._rows[parts[1]].col || {};
            return BX.util.trim(col.HEADER || col.ALIAS || '');
        },

        /**
         * Обработка выбора опции «Создать новое свойство»:
         * значение сбрасывается, запрашивается название (по умолчанию —
         * название колонки), открывается диалог.
         */
        onMapSelectChange: function (select) {
            if (select.__awzNewPropLock) return;
            var val = BX.util.trim(select.value || '');
            if (val.indexOf('__new:') !== 0) return;
            // блокировка от повторного входа: обработчик висит и на
            // нативном change, и на jQuery change select2
            select.__awzNewPropLock = true;
            setTimeout(function () { select.__awzNewPropLock = false; }, 300);
            var type = val.substring('__new:'.length);

            // сбрасываем выбор (псевдозначение не сохраняется в сопоставление)
            select.value = '';
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                window.jQuery(select).val('').trigger('change');
            }

            var self = this;
            var defaultName = this.getColumnHeader(select);
            var mapKey = '';
            var wrap = select.closest ? select.closest('[data-col-maps]') : null;
            if (wrap) mapKey = wrap.getAttribute('data-col-maps') || '';
            this.__pendingNewProp = {
                mapKey: mapKey,
                code: this.normalizePropCode(defaultName || '')
            };
            var askName = function (cb) {
                var name = window.prompt('Название нового свойства', defaultName || '');
                cb(BX.util.trim(name || ''));
            };
            askName(function (name) {
                if (name === '') return;
                self.openNewPropDialog(type, name, select);
            });
        },

        /**
         * Нормализация символьного кода свойства (зеркало серверной
         * Profiles::normalizePropertyCode): транслитерация, все буквы
         * заглавные, не начинается с цифры (префикс P), прочерки заменяются
         * на нижнее подчеркивание, допустимы [A-Z0-9_]. Уникальность кода
         * в рамках инфоблока обеспечивает сервер (суффиксы _2, _3...).
         */
        normalizePropCode: function (name) {
            var code = this.translit(name);
            code = code.replace(/-/g, '_').replace(/[^a-z0-9_]/g, '');
            if (code === '') return '';
            code = code.toUpperCase();
            if (/^\d/.test(code)) code = 'P' + code;
            return code;
        },

        /**
         * Проверка уникальности символьного кода в инфоблоке (ci) с
         * автоматическим подбором суффикса _2, _3... до свободного кода.
         * Результат передается в callback(uniqueCode).
         */
        ensureUniquePropCode: function (iblockId, code, callback) {
            var tryCode = function (candidate) {
                BX.ajax.runAction('awz:allxls.api.profiles.propertycheck', {
                    data: {iblockId: iblockId, code: candidate}
                }).then(function (response) {
                    var prop = response.data && response.data.property;
                    if (prop) {
                        var parts = candidate.split('_');
                        var tail = parseInt(parts[parts.length - 1], 10);
                        var next = isNaN(tail)
                            ? candidate + '_2'
                            : candidate.substring(0, candidate.length - String(tail).length - 1) + '_' + (tail + 1);
                        tryCode(next);
                    } else {
                        callback(candidate);
                    }
                }, function () {
                    callback(candidate); // ошибка проверки — открываем как есть
                    if (window.console && console.warn) console.warn('[awz.allxls] propertycheck fail', candidate);
                });
            };
            tryCode(code);
        },

        /**
         * Открытие стандартного диалога создания свойства инфоблока.
         * Тип свойства передается заранее (PROP[PROPERTY_TYPE]) — внутри
         * окна тип не меняется. Ресивер — window.obAwzAllxlsProps.
         * После успешного создания свойство выбирается в исходном select.
         */
        openNewPropDialog: function (type, name, select) {
            var self = this;
            var proposed = this.normalizePropCode(name);
            // проверяем уникальность кода в инфоблоке ДО открытия диалога
            // (суффикс _2, _3... при занятом коде)
            this.ensureUniquePropCode(this.iblockId, proposed, function (code) {
                self.showNewPropDialog(type, name, code, select);
            });
        },

        /**
         * Открытие стандартного диалога создания свойства инфоблока
         * с уже проверенным уникальным кодом.
         */
        showNewPropDialog: function (type, name, code, select) {
            var self = this;
            var coreType = type === 'HTML' ? 'S' : type;

            // цель для автовыбора после создания
            var mapKey = '';
            if (select) {
                var wrap = select.closest ? select.closest('[data-col-maps]') : null;
                if (wrap) mapKey = wrap.getAttribute('data-col-maps') || '';
            }
            this.__pendingNewProp = {
                mapKey: mapKey,
                code: code,
                name: name
            };

            // инициализация ресивера (Proxy — принимает любой вызов диалога)
            this.initNewPropReceiver();

            var parts = [
                'lang=ru',
                'propedit=n0',
                'bxpublic=Y',
                'bxsender=core_window_cadmindialog',
                'receiver=obAwzAllxlsProps'
            ];
            // параметры свойства передаются POST-ом (content_post у BX.CAdminDialog)
            var postParts = [
                'PARAMS[PREFIX]=AWZ_ALLXLS_PROP_',
                'PARAMS[ID]=n0',
                'PARAMS[IBLOCK_ID]=' + this.iblockId,
                'PARAMS[TITLE]=' + encodeURIComponent('Настройка свойства инфоблока'),
                'PARAMS[RECEIVER]=obAwzAllxlsProps',
                'PROP[PROPERTY_TYPE]=' + coreType,
                'PROP[NAME]=' + encodeURIComponent(name),
                'PROP[ACTIVE]=Y',
                'PROP[MULTIPLE]=N',
                'PROP[IS_REQUIRED]=N',
                'PROP[SORT]=500',
                'PROP[PROPINFO]=' + PROPINFO_BASE64,
                'sessid=' + (BX.bitrix_sessid ? BX.bitrix_sessid() : '')
            ];
            if (code !== '') postParts.push('PROP[CODE]=' + encodeURIComponent(code));
            if (type === 'HTML') postParts.push('PROP[USER_TYPE]=HTML');

            window.__awzNewPropCtx = {
                type: type,
                name: name,
                code: code,
                iblockId: this.iblockId
            };

            var dialog = new BX.CAdminDialog({
                content_url: '/bitrix/admin/iblock_edit_property.php?' + parts.join('&'),
                content_post: postParts.join('&'),
                title: 'Новое свойство инфоблока',
                width: 820,
                height: 540
            });
            dialog.Show();
            if (window.console && console.info) console.info('[awz.allxls] new prop dialog opened', type, name, code);

            // страница в bxpublic-режиме может не выставить кнопки диалога —
            // добавляем свои «Сохранить»/«Отмена» (перезатираются кнопками
            // страницы, если она их все же установит)
            var self2 = self;
            var setDialogButtons = function () {
                if (!dialog.PARTS || !dialog.PARTS.BUTTONS_CONTAINER) return false;
                if (dialog.PARTS.BUTTONS_CONTAINER.children.length > 0) return true;
                dialog.SetButtons([
                    new BX.CWindowButton({
                        title: 'Сохранить',
                        name: 'save',
                        className: 'adm-btn-save',
                        parentWindow: dialog,
                        action: function () { dialog.Submit(); }
                    }),
                    new BX.CWindowButton({
                        title: 'Отмена',
                        name: 'cancel',
                        parentWindow: dialog,
                        action: function () { dialog.Close(); }
                    })
                ]);
                return true;
            };
            setDialogButtons();
            setTimeout(setDialogButtons, 500);
            setTimeout(setDialogButtons, 1500);

            // после закрытия диалога (любым способом) — обновляем список
            // полей и пробуем выбрать созданное свойство
            this.__newPropCloseToken = Date.now();
            var closedHandler = function () {
                self.afterNewPropDialogClosed();
            };
            BX.addCustomEvent(dialog, 'onWindowClose', closedHandler);
            // страховка: диалог мог быть закрыт ядром страницы без нашего
            // события — опрашиваем скрытие окна (только ПОСЛЕ показа окна,
            // иначе ложное «закрытие» до фактического открытия)
            var wasShown = false;
            var closePoll = setInterval(function () {
                if (!dialog.DIV) return;
                if (dialog.DIV.style.display !== 'none') {
                    wasShown = true;
                    return;
                }
                if (!wasShown) return;
                clearInterval(closePoll);
                closedHandler();
            }, 500);
        },

        /**
         * Инициализация ресивера значений стандартного диалога.
         * Proxy принимает вызов любого метода — значения аргументов
         * (объект свойства) передаются в обработчик __handle.
         */
        initNewPropReceiver: function () {
            if (window.obAwzAllxlsProps) return;
            var self = this;
            window.obAwzAllxlsProps = new Proxy({}, {
                get: function (target, prop) {
                    return function () {
                        self.handleNewPropReceiver(String(prop), Array.prototype.slice.call(arguments));
                    };
                }
            });
            window.__awzNewPropCtx = null;
        },

        /**
         * Обработка «прилетевших» от диалога значений:
         * свойство создается через API (awz:allxls.api.profiles.createproperty,
         * idempotent по коду+названию — повторные вызовы ресивера и вызовы
         * при инициализации страницы дубликат не дают).
         */
        handleNewPropReceiver: function (method, args) {
            var ctx = window.__awzNewPropCtx || {};
            var prop = null;
            for (var i = 0; i < args.length; i++) {
                var a = args[i];
                if (a && typeof a === 'object' && (a.NAME || a.CODE || a.ID)) {
                    prop = a;
                    break;
                }
            }
            var name = prop ? BX.util.trim(prop.NAME || '') : (ctx.name || '');
            var code = prop ? BX.util.trim(prop.CODE || '') : (ctx.code || '');
            if (name === '' && code === '') return;
            this.createNewPropProperty(ctx.iblockId || this.iblockId, name, code, ctx.type || 'S');
        },

        /**
         * Fallback после закрытия диалога (событие onWindowClose):
         * безусловно перезагружает список полей (свойство создано самим
         * диалогом), затем выбирает новое свойство — по коду
         * (propertycheck, регистронезависимо) либо подбором по названию.
         */
        afterNewPropDialogClosed: function () {
            var ctx = window.__awzNewPropCtx || {};
            var self = this;
            // защита от двойного запуска (событие + опрос скрытия)
            var token = this.__newPropCloseToken;
            if (token && this.__newPropClosedToken === token) return;
            this.__newPropClosedToken = token;
            // список полей обновляется в любом случае
            this.loadFields();
            if ((ctx.iblockId || this.iblockId) <= 0 || (ctx.code === '' && ctx.name === '')) return;

            BX.ajax.runAction('awz:allxls.api.profiles.propertycheck', {
                data: {
                    iblockId: ctx.iblockId || this.iblockId,
                    code: ctx.code || ''
                }
            }).then(function (response) {
                var prop = response.data && response.data.property;
                if (prop) {
                    self.notify('Свойство "' + prop.NAME + '" (' + prop.CODE + ') создано', 'success');
                    self.waitForNewPropSelection(prop.CODE);
                } else if (ctx.name) {
                    // код не совпал — подбор опции по названию
                    self.waitForNewPropSelection('', ctx.name);
                }
            }, function () {
                if (ctx.name) self.waitForNewPropSelection('', ctx.name);
            });
        },

        /**
         * Автовыбор созданного свойства в select колонки после
         * перезагрузки списка полей: опция ожидается (перерисовка
         * асинхронна), затем устанавливается в первый пустой select
         * сопоставления колонки. Если код не известен — подбор по
         * вхождению названия в текст опции.
         */
        waitForNewPropSelection: function (code, name) {
            var pending = this.__pendingNewProp || {};
            code = code || pending.code || '';
            name = name || pending.name || '';
            if (!pending.mapKey || (!code && !name)) return;
            var target = code ? 'PROPERTY_' + String(code).toUpperCase() : '';
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                var wrap = document.querySelector('[data-col-maps="' + pending.mapKey + '"]');
                if (!wrap) {
                    if (tries > 60) clearInterval(timer);
                    return;
                }
                var selects = wrap.querySelectorAll('.awz-allxls-grid__map-select');
                var select = null;
                var selectValue = '';
                for (var i = 0; i < selects.length; i++) {
                    var s = selects[i];
                    for (var j = 0; j < s.options.length; j++) {
                        var val = String(s.options[j].value || '');
                        var txt = String(s.options[j].textContent || '');
                        if (!val) continue;
                        var match = false;
                        if (target && val.toUpperCase() === target.toUpperCase()) {
                            match = true;
                        } else if (!target && name &&
                            val.indexOf('PROPERTY_') === 0 &&
                            txt.toLowerCase().indexOf(String(name).toLowerCase()) >= 0) {
                            match = true;
                        }
                        if (match) {
                            select = s;
                            selectValue = val;
                            break;
                        }
                    }
                    if (select) break;
                }
                if (!select) {
                    if (tries > 60) clearInterval(timer);
                    return;
                }
                clearInterval(timer);
                select.value = selectValue;
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                    window.jQuery(select).val(selectValue).trigger('change');
                }
            }, 250);
        },

        /**
         * Создание свойства по API (idempotent по коду) и перезагрузка полей.
         */
        createNewPropProperty: function (iblockId, name, code, type) {
            if (!iblockId || (name === '' && code === '')) return;
            var self = this;
            BX.ajax.runAction('awz:allxls.api.profiles.createproperty', {
                data: {
                    iblockId: iblockId,
                    name: name,
                    code: code,
                    type: type,
                    multiple: 'N'
                }
            }).then(function (response) {
                var prop = response.data || {};
                if (window.console && console.info) console.info('[awz.allxls] property created', prop);
                self.notify(
                    (prop.EXISTS ? 'Свойство "' : 'Создано свойство "') +
                    (prop.NAME || name) + '" (' + (prop.CODE || code) + ')',
                    'success'
                );
                self.loadFields();
                self.waitForNewPropSelection(prop.CODE || code);
            }, function (response) {
                self.notify(self.errorMessage(response, 'Ошибка создания свойства'), 'error');
            });
        }
    };

    window.Awz.Allxls.ProfilesEditNewPropMixin = NewPropMixin;

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
    // (файл миксина подключается после profiles_edit_step3.js)
    if (window.Awz.Allxls.ProfilesEditSettings) {
        applyMixin(window.Awz.Allxls.ProfilesEditSettings.prototype, NewPropMixin);
    }

})(window);