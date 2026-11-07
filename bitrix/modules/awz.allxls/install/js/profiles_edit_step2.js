/**
 * AWZ.AllXLS: шаг 2 — выбор файла источника
 * Использование:
 *   var ob = new Awz.Allxls.ProfilesEditFile({id: 1, listUrl: '...'});
 *   ob.init();
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var ProfilesEditFile = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.action = options.action || 'awz:allxls.api.profiles.savefile';
        this.formId = options.formId || 'awz_allxls_profile_file_form';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_file_save';
        this.savedFilePath = options.savedFilePath || '';
        this.savedExt = options.savedExt || '';
        this.savedXmlPaths = options.savedXmlPaths || '';
        this.touched = false;
        this.saving = false;
    };

    ProfilesEditFile.prototype = {
        init: function () {
            var self = this;
            var btn = document.getElementById(this.saveBtnId);
            if (btn) {
                BX.bind(btn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.save();
                });
            }
            var input = document.querySelector('#' + this.formId + ' [name="FILE_PATH"]');
            if (input) {
                BX.bind(input, 'change', function () { self.touched = true; self.toggleCsvDelimiter(); });
                BX.bind(input, 'keyup', function () { self.touched = true; self.toggleCsvDelimiter(); });
                BX.bind(input, 'blur', function () { self.touched = true; });
            }
            var delimType = document.querySelector('#' + this.formId + ' [name="CSV_DELIMITER_TYPE"]');
            if (delimType) {
                BX.bind(delimType, 'change', function () { self.toggleCsvDelimiterCustom(); });
            }
            var encType = document.querySelector('#' + this.formId + ' [name="CSV_ENCODING_TYPE"]');
            if (encType) {
                BX.bind(encType, 'change', function () { self.toggleCsvEncodingCustom(); });
            }
            var destType = document.getElementById('AWZ_ALLXLS_PROFILE_DEST_TYPE');
            if (destType) {
                BX.bind(destType, 'change', function () { self.toggleDestType(); });
            }
            this.toggleCsvDelimiter();
            this.toggleDestType();
        },

        /**
         * Показ/скрытие полей выбора инфоблока или ORM-сущности
         */
        toggleDestType: function () {
            var sel = document.getElementById('AWZ_ALLXLS_PROFILE_DEST_TYPE');
            if (!sel) return;
            var iblockRow = document.getElementById('awz_allxls_profile_dest_iblock_row');
            var ormRow = document.getElementById('awz_allxls_profile_dest_orm_row');
            if (iblockRow) iblockRow.style.display = sel.value === 'iblock' ? '' : 'none';
            if (ormRow) ormRow.style.display = sel.value === 'orm' ? '' : 'none';
        },

        collect: function () {
            var form = document.getElementById(this.formId);
            var data = {id: this.id, file_path: '', proxy: '', csv_delimiter: ',', csv_encoding: 'utf8', csv_encoding_custom: '', dest_type: 'iblock', iblock_id: 0, orm_file: ''};
            if (!form) return data;
            var destType = document.getElementById('AWZ_ALLXLS_PROFILE_DEST_TYPE');
            if (destType) data.dest_type = destType.value;
            var iblockSel = document.getElementById('AWZ_ALLXLS_PROFILE_IBLOCK_ID');
            if (iblockSel) data.iblock_id = parseInt(iblockSel.value, 10) || 0;
            var ormFile = document.getElementById('AWZ_ALLXLS_PROFILE_ORM_FILE');
            if (ormFile) data.orm_file = BX.util.trim(ormFile.value);
            var input = form.querySelector('[name="FILE_PATH"]');
            if (input) data.file_path = BX.util.trim(input.value);
            var fileExt = document.getElementById('AWZ_ALLXLS_PROFILE_FILE_EXT');
            if (fileExt) data.file_ext = fileExt.value;
            var proxy = form.querySelector('[name="PROXY"]');
            if (proxy) data.proxy = BX.util.trim(proxy.value);
            var xmlPaths = document.getElementById('AWZ_ALLXLS_PROFILE_XML_PATHS');
            if (xmlPaths) data.xml_paths = BX.util.trim(xmlPaths.value);
            var ttlSel = document.getElementById('AWZ_ALLXLS_PROFILE_FILE_CACHE_LIFETIME');
            if (ttlSel) data.file_cache_lifetime = parseInt(ttlSel.value, 10) || 0;
            var maxParams = document.getElementById('AWZ_ALLXLS_PROFILE_MAX_PARAMS');
            if (maxParams) data.max_params = parseInt(maxParams.value, 10) || 0;
            var delimType = form.querySelector('[name="CSV_DELIMITER_TYPE"]');
            var delimCustom = form.querySelector('[name="CSV_DELIMITER_CUSTOM"]');
            if (delimType) {
                if (delimType.value === 'other') {
                    data.csv_delimiter = delimCustom ? delimCustom.value : '';
                } else {
                    data.csv_delimiter = delimType.value;
                }
            }
            return data;
        },

        /**
         * Актуальный тип файла: явный выбор (FILE_EXT) либо по расширению пути
         */
        effectiveExt: function (form, input) {
            var sel = document.getElementById('AWZ_ALLXLS_PROFILE_FILE_EXT');
            if (sel && sel.value) return sel.value;
            var value = BX.util.trim(input.value);
            var ext = /\.([a-z0-9]+)(\.gz)?$/i.exec(value);
            if (ext) return ext[1].toLowerCase();
            // значение не менялось: используем формат, сохраненный в настройках
            if (!this.touched && value === this.savedFilePath && this.savedExt) return this.savedExt;
            return '';
        },

        /**
         * Показ/скрытие настройки разделителя CSV в зависимости от расширения файла
         */
        toggleCsvDelimiter: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var input = form.querySelector('[name="FILE_PATH"]');
            var row = document.getElementById('awz_allxls_profile_csv_delimiter_row');
            if (!input || !row) return;
            var isCsv = this.effectiveExt(form, input) === 'csv';
            row.style.display = isCsv ? '' : 'none';
            this.toggleCsvDelimiterCustom();
            var encRow = document.getElementById('awz_allxls_profile_csv_encoding_row');
            if (encRow) encRow.style.display = isCsv ? '' : 'none';
            this.toggleCsvEncodingCustom();
            this.toggleXmlPaths();
            this.toggleFileTtl();
            this.toggleFileExt();
        },

        /**
         * Показ/скрытие поля времени жизни кеша внешнего источника
         */
        toggleFileTtl: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var input = form.querySelector('[name="FILE_PATH"]');
            var row = document.getElementById('awz_allxls_profile_file_ttl_row');
            if (!input || !row) return;
            var isExternal = /^(https?|ftps?):\/\//i.test(BX.util.trim(input.value));
            row.style.display = isExternal ? '' : 'none';
        },

        /**
         * Поле «Тип файла» показывается для внешних источников (ссылки/FTP),
         * у которых в пути может не быть расширения
         */
        toggleFileExt: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var input = form.querySelector('[name="FILE_PATH"]');
            var row = document.getElementById('awz_allxls_profile_file_ext_row');
            if (!input || !row) return;
            var isExternal = /^(https?|ftps?):\/\//i.test(BX.util.trim(input.value));
            row.style.display = isExternal ? '' : 'none';
        },

        /**
         * Показ/скрытие поля путей до элементов XML/YML/JSON
         */
        toggleXmlPaths: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var input = form.querySelector('[name="FILE_PATH"]');
            var row = document.getElementById('awz_allxls_profile_xml_paths_row');
            if (!input || !row) return;
            var ext = this.effectiveExt(form, input);
            var isXml = ext === 'xml' || ext === 'yml' || ext === 'json';
            if (!isXml) {
                // значение не менялось: используем формат, сохраненный в настройках
                isXml = !this.touched
                    && BX.util.trim(input.value) === this.savedFilePath
                    && (this.savedExt === 'xml' || this.savedExt === 'yml' || this.savedExt === 'json');
            }
            row.style.display = isXml ? '' : 'none';
        },

        toggleCsvEncodingCustom: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var encType = form.querySelector('[name="CSV_ENCODING_TYPE"]');
            var encCustom = form.querySelector('[name="CSV_ENCODING_CUSTOM"]');
            if (!encType || !encCustom) return;
            encCustom.style.display = encType.value === 'other' ? '' : 'none';
        },

        toggleCsvDelimiterCustom: function () {
            var form = document.getElementById(this.formId);
            if (!form) return;
            var delimType = form.querySelector('[name="CSV_DELIMITER_TYPE"]');
            var delimCustom = form.querySelector('[name="CSV_DELIMITER_CUSTOM"]');
            if (!delimType || !delimCustom) return;
            delimCustom.style.display = delimType.value === 'other' ? '' : 'none';
        },

        validate: function (data) {
            if (!data.file_path) {
                this.notify(BX.message('AWZ_ALLXLS_PROFILE_FILE_EMPTY') || 'Укажите путь к файлу', 'error');
                var input = document.querySelector('#' + this.formId + ' [name="FILE_PATH"]');
                if (input) input.focus();
                return false;
            }
            if (data.dest_type === 'iblock' && !data.iblock_id) {
                this.notify(BX.message('AWZ_ALLXLS_PROFILE_DEST_IBLOCK_EMPTY') || 'Выберите инфоблок', 'error');
                var iblockSel = document.getElementById('AWZ_ALLXLS_PROFILE_IBLOCK_ID');
                if (iblockSel) iblockSel.focus();
                return false;
            }
            if (data.dest_type === 'orm' && !data.orm_file) {
                this.notify(BX.message('AWZ_ALLXLS_PROFILE_ORM_FILE_EMPTY') || 'Укажите файл ORM-сущности', 'error');
                var ormFile = document.getElementById('AWZ_ALLXLS_PROFILE_ORM_FILE');
                if (ormFile) ormFile.focus();
                return false;
            }
            var forcedXml = (data.file_ext === 'xml' || data.file_ext === 'yml' || data.file_ext === 'json');
            var extXml = /\.(xml|yml|json)(\.gz)?$/i.test(BX.util.trim(data.file_path));
            if ((forcedXml || extXml) && !data.xml_paths) {
                this.notify(BX.message('AWZ_ALLXLS_PROFILE_XML_PATHS_EMPTY') || 'Укажите пути до элементов (например: items->item)', 'error');
                var xmlPaths = document.getElementById('AWZ_ALLXLS_PROFILE_XML_PATHS');
                if (xmlPaths) xmlPaths.focus();
                return false;
            }
            return true;
        },

        save: function () {
            if (this.saving) return;
            var self = this;
            var data = this.collect();
            // кодировка CSV (экспорт): собираем напрямую из формы
            var form = document.getElementById(this.formId);
            if (form) {
                var encType = form.querySelector('[name="CSV_ENCODING_TYPE"]');
                var encCustom = form.querySelector('[name="CSV_ENCODING_CUSTOM"]');
                if (encType) {
                    data.csv_encoding = encType.value;
                    data.csv_encoding_custom = (encType.value === 'other' && encCustom) ? BX.util.trim(encCustom.value) : '';
                }
            }
            if (!this.validate(data)) return;

            this.saving = true;
            this.lock(true);

            BX.ajax.runAction(this.action, {
                data: data
            }).then(function (response) {
                self.saving = false;
                self.lock(false);
                var result = response.data || {};
                self.notify(
                    BX.message('AWZ_ALLXLS_PROFILE_FILE_SAVED') || 'Файл сохранен',
                    'success'
                );
                if (result.nextStepUrl) {
                    window.location.href = result.nextStepUrl;
                } else if (self.listUrl) {
                    window.location.href = self.listUrl;
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

        lock: function (locked) {
            var btn = document.getElementById(this.saveBtnId);
            if (btn) btn.disabled = !!locked;
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
    window.Awz.Allxls.ProfilesEditFile = ProfilesEditFile;

})(window);