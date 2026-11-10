<?php
defined('B_PROLOG_INCLUDED') and B_PROLOG_INCLUDED === true or die();
/**
 * JS-инициализация шага 2 (экспорт — файл выгрузки).
 * Доступные переменные:
 *
 * @var int    $profileId ID профиля
 * @var string $listUrl   URL списка профилей
 */

use Bitrix\Main\Localization\Loc;
?>
<script>
    BX.message({
        AWZ_ALLXLS_PROFILE_FILE_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_EMPTY'))?>',
        AWZ_ALLXLS_PROFILE_FILE_SAVED: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_FILE_SAVED'))?>',
        AWZ_ALLXLS_PROFILE_DEST_IBLOCK_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_DEST_IBLOCK_EMPTY_MSG'))?>',
        AWZ_ALLXLS_PROFILE_ORM_FILE_EMPTY: '<?=\CUtil::JSEscape(Loc::getMessage('AWZ_ALLXLS_S_PROFILES_EDIT_ORM_FILE_EMPTY'))?>'
    });
    BX.ready(function () {
        window.awzAllxlsProfilesEditFile = new Awz.Allxls.ProfilesEditFile({
            id: <?=$profileId?>,
            listUrl: '<?=$listUrl?>',
            action: 'awz:allxls.api.profiles.savefile',
            formId: 'awz_allxls_profile_file_form',
            saveBtnId: 'awz_allxls_profile_file_save',
            skipXmlPaths: true
        });
        window.awzAllxlsProfilesEditFile.init();

        // === специфика экспорта: автозаполнение пути из имени файла и расширения ===
        var exportDir = '/upload/awz.allxls/';
        var form = document.getElementById('awz_allxls_profile_file_form');
        var nameInput = document.getElementById('AWZ_ALLXLS_PROFILE_EXPORT_FILE_NAME');
        var pathInput = document.getElementById('AWZ_ALLXLS_PROFILE_FILE_PATH');
        var extBtns = form ? form.querySelectorAll('.awz-export-ext-btn') : [];
        var currentExt = 'csv';

        function syncPath() {
            if (!nameInput || !pathInput) return;
            var name = BX.util.trim(nameInput.value).replace(/[\\\/]+/g, '');
            pathInput.value = name ? (exportDir + name + '.' + currentExt) : '';
            if (window.awzAllxlsProfilesEditFile) {
                window.awzAllxlsProfilesEditFile.toggleCsvDelimiter();
            }
        }

        function showExtBtn(ext) {
            currentExt = ext;
            extBtns.forEach(function (btn) {
                var active = (btn.getAttribute('data-ext') === ext);
                btn.classList.remove('awz-allxls-btn--primary', 'awz-allxls-btn--ghost');
                btn.classList.add(active ? 'awz-allxls-btn--primary' : 'awz-allxls-btn--ghost');
            });
            syncPath();
            // кодировка CSV и разделитель — только для .csv
            var encRow = document.getElementById('awz_allxls_profile_csv_encoding_row');
            var delimRow = document.getElementById('awz_allxls_profile_csv_delimiter_row');
            if (encRow) encRow.style.display = (ext === 'csv') ? '' : 'none';
            if (delimRow) delimRow.style.display = (ext === 'csv') ? '' : 'none';
        }

        if (nameInput) {
            BX.bind(nameInput, 'change', syncPath);
            BX.bind(nameInput, 'keyup', syncPath);
        }
        extBtns.forEach(function (btn) {
            BX.bind(btn, 'click', function (e) {
                BX.PreventDefault(e);
                showExtBtn(this.getAttribute('data-ext'));
            });
        });

        // начальное расширение из сохраненного пути
        var saved = pathInput ? pathInput.value : '';
        if (/\.xlsx$/i.test(saved)) showExtBtn('xlsx');
        else if (/\.json$/i.test(saved)) showExtBtn('json');
        else showExtBtn('csv');

        // === кодировка CSV: показ/скрытие и передача на сервер ===
        var encRow = document.getElementById('awz_allxls_profile_csv_encoding_row');
        var encType = form ? form.querySelector('[name="CSV_ENCODING_TYPE"]') : null;
        var encCustom = form ? form.querySelector('[name="CSV_ENCODING_CUSTOM"]') : null;

        function toggleEncoding() {
            if (!encRow || !pathInput) return;
            var isCsv = /\.csv$/i.test(BX.util.trim(pathInput.value));
            encRow.style.display = isCsv ? '' : 'none';
            if (encCustom && encType) encCustom.style.display = encType.value === 'other' ? '' : 'none';
        }
        if (encType) BX.bind(encType, 'change', toggleEncoding);
        toggleEncoding();

        // добавляем кодировку и JSON-фильтр в данные, отправляемые на сервер
        if (window.awzAllxlsProfilesEditFile) {
            var ob = window.awzAllxlsProfilesEditFile;
            var origCollect = ob.collect;
            ob.collect = function () {
                var data = origCollect.apply(ob, arguments);
                if (encType) {
                    data.csv_encoding = encType.value;
                    data.csv_encoding_custom = (encType.value === 'other' && encCustom) ? BX.util.trim(encCustom.value) : '';
                }
                var filterInput = document.getElementById('AWZ_ALLXLS_PROFILE_EXPORT_FILTER');
                if (filterInput) data.export_filter = filterInput.value;
                return data;
            };
        }
    });
</script>
