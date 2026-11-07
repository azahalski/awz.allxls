/**
 * AWZ.AllXLS: шаг 3 — методы дополнительных колонок-примеров (миксин).
 * Подключается после profiles_edit_step3.js и расширяет прототип
 * Awz.Allxls.ProfilesEditSettings.
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};
    window.Awz.Allxls = window.Awz.Allxls || {};

    var SampleColumnsMixin = {

        /**
         * Ячейка дополнительной колонки-примера (строка данных N из файла)
         */
        renderSampleCell: function (sheet, sheetIndex, col, idx) {
            var td = document.createElement('td');
            td.setAttribute('data-sample-cell', sheetIndex + ':' + col.KEY + ':' + idx);
            var sample = document.createElement('div');
            sample.className = 'awz-allxls-grid__sample';
            sample.textContent = (col.SAMPLES && col.SAMPLES[idx]) || '';
            td.appendChild(sample);
            sheet._sampleCells[col.KEY] = sheet._sampleCells[col.KEY] || {};
            sheet._sampleCells[col.KEY][idx] = td;
            return td;
        },

        /**
         * Шапка дополнительных колонок-примеров: кнопки «−» (скрыть) и «+» (добавить, до 10)
         */
        renderSampleHeader: function (sheet, sheetIndex, headRow) {
            var self = this;
            Array.prototype.slice.call(headRow.querySelectorAll('th[data-sample-th],th[data-sample-add-th]')).forEach(function (th) {
                th.parentNode.removeChild(th);
            });

            var sampleTitle = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_COL_SAMPLE') || 'Пример значения';
            for (var idx = 1; idx < (sheet._sampleCols || 1); idx++) {

                if (sheet._sampleHidden && sheet._sampleHidden[idx]) continue; // скрытые не рендерим
                (function (idx) {
                    var th = document.createElement('th');
                    th.setAttribute('data-sample-th', idx);
                    th.appendChild(document.createTextNode(sampleTitle + ' ' + (idx + 1)));

                    var hideBtn = document.createElement('span');
                    hideBtn.className = 'awz-allxls-grid__sample-toggle';
                    hideBtn.textContent = '−';
                    hideBtn.title = BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_HIDE_SAMPLE') || 'Скрыть колонку с примерами';
                    BX.bind(hideBtn, 'click', function () {
                        self.toggleSampleColumn(sheet, sheetIndex, idx, true);
                    });
                    th.appendChild(hideBtn);

                    headRow.appendChild(th);
                })(idx);
            }

            // колонка с кнопкой добавления новой колонки-примера (всегда видна, макс 10)
            var addTh = document.createElement('th');
            addTh.setAttribute('data-sample-add-th', '1');
            var addBtn = document.createElement('span');
            addBtn.className = 'awz-allxls-grid__sample-toggle';
            addBtn.textContent = '+';
            var maxReached = (sheet._sampleCols || 1) >= 10;
            addBtn.title = maxReached
                ? (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_SAMPLE_MAX') || 'Максимальное количество колонок-примеров (10) достигнуто')
                : (BX.message('AWZ_ALLXLS_PROFILE_SETTINGS_ADD_SAMPLE_HINT') || 'Показать/добавить колонку с примерами из файла (до 10)');
            if (maxReached) addBtn.classList.add('awz-allxls-grid__sample-toggle--disabled');
            BX.bind(addBtn, 'click', function () {
                self.addSampleColumn(sheet, sheetIndex);
            });
            addTh.appendChild(addBtn);
            headRow.appendChild(addTh);
        },

        /**
         * Кнопка «+» в шапке: показывает ранее скрытую колонку-пример,
         * а если скрытых нет — добавляет новую (до 10)
         */
        addSampleColumn: function (sheet, sheetIndex) {
            // сначала показываем скрытую колонку с минимальным индексом
            for (var i = 1; i < (sheet._sampleCols || 1); i++) {
                if (sheet._sampleHidden && sheet._sampleHidden[i]) {
                    this.toggleSampleColumn(sheet, sheetIndex, i, false);
                    return;
                }
            }
            if ((sheet._sampleCols || 1) >= 10) return;
            var idx = sheet._sampleCols || 1;
            sheet._sampleCols = idx + 1;
            var self = this;
            (sheet.COLUMNS || []).forEach(function (col) {
                var entry = sheet._rows && sheet._rows[col.KEY];
                if (!entry) return;
                entry.row.appendChild(self.renderSampleCell(sheet, sheetIndex, col, idx));
            });
            this.renderSampleHeader(sheet, sheetIndex, sheet._headRow);
        },

        /**
         * Скрытие/раскрытие колонки-примера (удаление/возврат узлов из DOM)
         */
        toggleSampleColumn: function (sheet, sheetIndex, idx, hide) {
            sheet._sampleHidden = sheet._sampleHidden || {};
            sheet._sampleHidden[idx] = !!hide;
            var self = this;
            // th
            var th = sheet._headRow ? sheet._headRow.querySelector('th[data-sample-th="' + idx + '"]') : null;
            if (th) {
                if (hide) th.parentNode && th.parentNode.removeChild(th);
            }
            // td во всех строках
            (sheet.COLUMNS || []).forEach(function (col) {
                var cell = sheet._sampleCells && sheet._sampleCells[col.KEY] && sheet._sampleCells[col.KEY][idx];
                if (!cell) return;
                if (hide) {
                    cell.parentNode && cell.parentNode.removeChild(cell);
                } else {
                    var entry = sheet._rows && sheet._rows[col.KEY];
                    if (entry) self.insertSampleCell(entry.row, cell, idx);
                }
            });
            this.renderSampleHeader(sheet, sheetIndex, sheet._headRow);
        },

        /**
         * Вставка td колонки-примера в нужную позицию строки
         */
        insertSampleCell: function (row, cell, idx) {
            // ищем первую видимую колонку-пример с большим индексом — вставляем перед ней
            var next = row.querySelector('td[data-sample-cell]');
            if (next) {
                // next — первая доп. колонка; вставляем по порядку индексов
                var cells = row.querySelectorAll('td[data-sample-cell]');
                for (var i = 0; i < cells.length; i++) {
                    var cellIdx = parseInt(cells[i].getAttribute('data-sample-cell').split(':')[2], 10);
                    if (cellIdx > idx) {
                        row.insertBefore(cell, cells[i]);
                        return;
                    }
                }
                // иначе — перед последним элементом, если это кнопка раскрытия (td примера #1), вставляем в конец
                row.appendChild(cell);
                return;
            }
            row.appendChild(cell);
        },

        /**
         * Перемещение строки колонки: активные сверху, неактивные снизу.
         * Неактивные по умолчанию скрыты (класс на tbody).
         */
        applyColumnState: function (sheet, sheetIndex, col) {
            var entry = sheet._rows[col.KEY];
            if (!entry) return;
            var tbody = entry.row.parentNode;
            var include = col.INCLUDE !== 'N';
            var inactiveHidden = tbody.getAttribute('data-inactive-hidden') !== 'N';
            var selected = tbody.getAttribute('data-inactive-selected') || '';

            entry.row.classList.toggle('awz-allxls-grid__row--inactive', !include);
            // неактивные скрыты, кроме выбранного в select «показать неактивные» поля
            var visible = include || !inactiveHidden || selected === col.KEY;
            entry.row.style.display = visible ? '' : 'none';

            if (include) {
                tbody.insertBefore(entry.row, tbody.querySelector('.awz-allxls-grid__row--inactive'));
            } else {
                tbody.appendChild(entry.row);
            }

            // кешированные ссылки (renderSheet) вместо document.querySelector —
            // applyColumnState вызывается на каждую колонку, глобальный
            // поиск по документу на больших листах заметно тормозит
            var toggle = sheet._inactiveToggle
                || document.querySelector('[data-inactive-toggle="' + sheetIndex + '"]');
            if (toggle) {
                var hasInactive = tbody.querySelector('.awz-allxls-grid__row--inactive') !== null;
                toggle.style.display = hasInactive ? '' : 'none';
            }
            // select «Управление полями» показываем вместе с кнопкой
            var manageSelect = sheet._inactiveSelect
                || document.querySelector('[data-inactive-select="' + sheetIndex + '"]');
            if (manageSelect) {
                manageSelect.style.display = hasInactive ? '' : 'none';
                if (sheet._refreshInactiveSelect) sheet._refreshInactiveSelect();
            }
        }
    };

    window.Awz.Allxls.ProfilesEditSampleColumnsMixin = SampleColumnsMixin;

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
        applyMixin(window.Awz.Allxls.ProfilesEditSettings.prototype, SampleColumnsMixin);
    }

})(window);
