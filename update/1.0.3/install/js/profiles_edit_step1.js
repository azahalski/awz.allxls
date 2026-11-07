/**
 * AWZ.AllXLS: шаг 1 — создание профиля (этап 1)
 * Использование:
 *   var obProfilesEdit = new Awz.Allxls.ProfilesEdit({id: 0, listUrl: '/bitrix/admin/awz_allxls_profiles_list.php'});
 *   obProfilesEdit.init();
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var ProfilesEdit = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.action = options.action || 'awz:allxls.api.profiles.save';
        this.formId = options.formId || 'awz_allxls_profile_form';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_save';
        this.saving = false;
    };

    ProfilesEdit.prototype = {
        /**
         * Инициализация: навешивание обработчика на кнопку «Сохранить»
         */
        init: function () {
            var self = this;
            var btn = document.getElementById(this.saveBtnId);
            if (btn) {
                BX.bind(btn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.save();
                });
            }
        },

        /**
         * Сбор данных формы
         * @returns {{id: number, fields: object}}
         */
        collect: function () {
            var form = document.getElementById(this.formId);
            var data = {
                id: this.id,
                fields: {}
            };
            if (!form) return data;

            var name = form.querySelector('[name="NAME"]');
            var active = form.querySelector('[name="ACTIVE"]');
            var sectionId = form.querySelector('[name="SECTION_ID"]');
            var desc = form.querySelector('[name="DESC"]');
            var type = form.querySelector('[name="TYPE"]');

            if (name) data.fields.NAME = BX.util.trim(name.value);
            if (active) data.fields.ACTIVE = active.checked ? 'Y' : 'N';
            if (sectionId) data.fields.SECTION_ID = parseInt(sectionId.value, 10) || 0;
            if (desc) data.fields.DESC = BX.util.trim(desc.value);
            if (type) data.fields.TYPE = (type.value === 'export') ? 'export' : 'import';

            return data;
        },

        /**
         * Валидация перед сохранением
         * @param fields
         * @returns {boolean}
         */
        validate: function (fields) {
            if (!fields.NAME) {
                this.notify(BX.message('AWZ_ALLXLS_PROFILE_NAME_EMPTY') || 'Укажите название профиля', 'error');
                var name = document.querySelector('#' + this.formId + ' [name="NAME"]');
                if (name) name.focus();
                return false;
            }
            return true;
        },

        /**
         * Сохранение профиля через REST-контроллер
         */
        save: function () {
            if (this.saving) return;
            var self = this;
            var data = this.collect();
            if (!this.validate(data.fields)) return;

            this.saving = true;
            this.lock(true);

            BX.ajax.runAction(this.action, {
                data: data
            }).then(function (response) {
                self.saving = false;
                self.lock(false);
                var result = response.data || {};
                var newId = parseInt(result.id, 10) || 0;
                self.notify(
                    BX.message('AWZ_ALLXLS_PROFILE_SAVED') || 'Профиль сохранен',
                    'success'
                );
                if (newId > 0) {
                    self.id = newId;
                    // после создания профиля — переход к этапу 2 (настройки профиля)
                    if (result.nextStepUrl) {
                        window.location.href = result.nextStepUrl;
                    } else if (self.listUrl) {
                        window.location.href = self.listUrl;
                    }
                }
            }, function (response) {
                self.saving = false;
                self.lock(false);
                var messages = [];
                if (response.errors) {
                    for (var i = 0; i < response.errors.length; i++) {
                        messages.push(response.errors[i].message);
                    }
                }
                self.notify(messages.join("\n") || 'Ошибка сохранения', 'error');
            });
        },

        /**
         * Блокировка/разблокировка кнопки сохранения
         */
        lock: function (locked) {
            var btn = document.getElementById(this.saveBtnId);
            if (btn) btn.disabled = !!locked;
        },

        /**
         * Уведомление через BX.UI.Notification
         */
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
    window.Awz.Allxls.ProfilesEdit = ProfilesEdit;

})(window);
