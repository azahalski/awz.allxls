/**
 * AWZ.AllXLS: шаг 4 — запуск обработки профиля с прогресс-баром (BX.UI.ProgressBar).
 * Использование:
 *   var ob = new Awz.Allxls.ProfilesEditProcess({id: 1, listUrl: '...'});
 *   ob.init();
 *
 * Логика: обработка выполняется порциями на контроллере (processAction).
 * Контроллер возвращает текущий прогресс:
 *   {
 *     processed: 120,        // обработано элементов
 *     total: 500 | null,     // финальное количество может отсутствовать
 *     percent: 42.5,         // процент чтения файла (когда total неизвестен):
 *                            // по байтам для CSV, по строкам листа для XLSX/ODS
 *     totalBytes: 1048576,   // размер файла источника
 *     bytesRead: 445644,     // текущая позиция чтения файла
 *     done: false,           // обработка завершена
 *     errors: ['...'],       // ошибки контроллера
 *     stats: {ключ: значение} // дополнительная статистика
 *   }
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var ProfilesEditProcess = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.processAction = options.processAction || 'awz:allxls.api.pimport.import';
        this.barId = options.barId || 'awz_allxls_profile_process_bar';
        this.statusId = options.statusId || 'awz_allxls_profile_process_status';
        this.itemId = options.itemId || 'awz_allxls_profile_process_item';
        this.statsId = options.statsId || 'awz_allxls_profile_process_stats';
        this.errorsId = options.errorsId || 'awz_allxls_profile_process_errors';
        this.startBtnId = options.startBtnId || 'awz_allxls_profile_process_start';
        this.continueBtnId = options.continueBtnId || 'awz_allxls_profile_process_continue';
        this.finishBtnId = options.finishBtnId || 'awz_allxls_profile_process_finish';
        this.nextBtnId = options.nextBtnId || 'awz_allxls_profile_process_next';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_process_save';
        this.debugId = options.debugId || 'awz_allxls_profile_process_debug';
        this.saveParamsAction = options.saveParamsAction || 'awz:allxls.api.pimport.saveparams';
        this.finishAction = options.finishAction || 'awz:allxls.api.pimport.finish';
        this.interrupted = options.interrupted || null; // состояние на момент обрыва
        this.reset = false; // сброс состояния при новом запуске
        this.hitTimeId = options.hitTimeId || 'awz_allxls_profile_process_hit_time';
        this.requestDelayId = options.requestDelayId || 'awz_allxls_profile_process_request_delay';
        this.timeId = options.timeId || 'awz_allxls_profile_process_time';
        this.columnsId = options.columnsId || 'awz_allxls_profile_process_columns';
        this.stopBtnId = options.stopBtnId || 'awz_allxls_profile_process_stop';

        this.progressBar = null;
        this.running = false;
        this.stopped = false; // импорт остановлен по ссылке «Остановить импорт»
        this.processed = 0;
        this.total = 0;      // 0 — финальное количество неизвестно
        this.percent = 0;    // процент чтения файла (когда total неизвестен)
        this.errors = [];
        this.stats = {};
        this.resumed = false; // обработка возобновлена с сохраненного состояния
        this.startTime = 0;   // время старта обработки (для динамического таймера)
        this.elapsedBase = 0; // накопленное время работы из состояния (продолжение после обрыва)
        this.timerId = null;
        this.inflight = false;      // запрос в полете
        this.tickPending = false;   // запрос запланирован (пауза между запросами)
        this.watchdogWorker = null; // watchdog для фоновых вкладок (Worker)
        this.watchdogId = null;     // fallback watchdog (setInterval)
        this.cronPollId = null;     // фоновый опрос статистики cron-запуска (setInterval)
    };

    ProfilesEditProcess.prototype = {
        init: function () {
            this.renderProgressBar();
            var self = this;
            var btn = document.getElementById(this.startBtnId);
            if (btn) {
                BX.bind(btn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.start(true);
                });
            }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) {
                BX.bind(contBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.start(false);
                });
            }
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) {
                BX.bind(finBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.finish();
                });
            }
            // кнопка «Следующий элемент» (режим импорта по одному элементу)
            var nextBtn = document.getElementById(this.nextBtnId);
            if (nextBtn) {
                BX.bind(nextBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    // продолжение без сброса: сервер обработает одну строку
                    // и снова остановится (режим STEP_BY_STEP)
                    self.start(false);
                });
            }
            // ссылка остановки импорта рядом с прогресс-баром
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) {
                BX.bind(stopBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.stop();
                });
            }
            // сохранение настроек обработки без запуска импорта
            var saveBtn = document.getElementById(this.saveBtnId);
            if (saveBtn) {
                BX.bind(saveBtn, 'click', function (e) {
                    BX.PreventDefault(e);
                    self.saveSettings();
                });
            }
            // при загрузке страницы подгружаем сохраненную статистику из SETTINGS['PROCESS']:
            // приоритет — отображаемая статистика STATS_VIEW (в т.ч. предыдущего завершенного
            // запуска), обнуляется только при новом запуске импорта
            if (this.interrupted && typeof this.interrupted === 'object') {
                this.processed = parseInt(this.interrupted.PROCESSED, 10) || 0;
                var viewStats = (this.interrupted.STATS_VIEW && Object.keys(this.interrupted.STATS_VIEW).length)
                    ? this.interrupted.STATS_VIEW
                    : (this.interrupted.STATS || {});
                this.stats = viewStats;
                this.errors = this.interrupted.ERRORS || [];
                // процент прогресса из сохраненной статистики — для прогресс-бара
                this.percent = parseFloat(viewStats['Прогресс, %']) || 0;
                // статистика и ошибки выводятся всегда, если есть данные
                if (Object.keys(this.stats).length) this.renderStats();
                if (this.errors.length) this.renderErrors();
                // прерванный импорт: дополнительно показываем прогресс и статус
                if (this.processed > 0 && this.interrupted.DONE !== 'Y') {
                    // кнопка «Запустить» доступна только при новом запуске
                    if (btn) btn.style.display = 'none';
                    if (this.interrupted.COL_STATS) {
                        this.renderColumns(this.topColumnsFromState(this.interrupted.COL_STATS));
                    }
                    this.updateProgressBar();
                    if (this.interrupted.SOURCE !== 'cron') {
                        this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED')
                            + ' (' + this.processed + ')', true);
                    }
                } else {
                    this.updateProgressBar();
                }
            }
            // факт запуска на crontab проверяется один раз при загрузке
            // финального этапа: фоновый опрос стартует только если профиль
            // реально запущен cron-процессом (см. startCronPoll)
            this.startCronPoll();
        },

        /**
         * Факт запуска проверяется один раз при загрузке финального этапа:
         * если профиль запущен на crontab — скрываются ручные кнопки,
         * включается статус «запущен на crontab» и фоновый опрос статистики
         * раз в 20 сек (cronstatus -> stats_view). Иначе — никаких
         * фоновых запросов.
         */
        startCronPoll: function (skipCheck) {
            var self = this;
            if (!skipCheck) {
                // единственная проверка при загрузке страницы
                BX.ajax.runAction('awz:allxls.api.profiles.cronstatus', {
                    data: { id: this.id }
                }).then(function (resp) {
                    var data = resp && resp.data;
                    if (!data) return;
                    var isRunning = data.running === true || data.running === 'Y';
                    if (!isRunning) {
                        self.cronPollRunning = false;
                        return; // профиль не запущен — опрос не нужен
                    }
                    self.startCronPoll(true);
                    self.applyCronStats(data);
                }, function () {});
                return;
            }
            // кнопки ручного запуска неактуальны, пока работает cron
            ['continueBtnId', 'finishBtnId', 'nextBtnId'].forEach(function (key) {
                var node = document.getElementById(self[key]);
                if (node) node.style.display = 'none';
            });
            this.cronPollRunning = true;
            this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_CRON'), false);
            if (this.cronPollId) clearInterval(this.cronPollId);
            this.cronPollId = setInterval(function () { self.cronPollTick(); }, 20000);
        },

        stopCronPoll: function (showInterrupted) {
            if (this.cronPollId) {
                clearInterval(this.cronPollId);
                this.cronPollId = null;
            }
            this.cronPollRunning = false;
            this.cronIdleTicks = 0;
            if (showInterrupted !== false) {
                this.showInterruptedButtons();
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED')
                    + ' (' + this.processed + ')', true);
            }
        },

        cronPollTick: function () {
            if (this.running) return; // ручной запуск в браузере активен
            var self = this;
            BX.ajax.runAction('awz:allxls.api.profiles.cronstatus', {
                data: { id: this.id }
            }).then(function (resp) {
                var data = resp && resp.data;
                if (!data) return;
                var isRunning = data.running === true || data.running === 'Y';
                var wasRunning = !!self.cronPollRunning;
                self.cronPollRunning = isRunning;
                if (isRunning) {
                    self.applyCronStats(data);
                    if (!self.cronPollId) self.startCronPoll(true);
                    return;
                }
                self.applyCronStats(data);
                // профиль не запущен — фоновый опрос не нужен
                if (self.cronPollId) {
                    clearInterval(self.cronPollId);
                    self.cronPollId = null;
                }
                if (wasRunning) {
                    self.showInterruptedButtons();
                    self.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED')
                        + ' (' + self.processed + ')', true);
                }
            }, function () {});
        },

        applyCronStats: function (data) {
            var sv = data && data.stats_view;
            if (!sv || typeof sv !== 'object') return;
            // общее время работы — elapsed с момента старта прогона (включая
            // паузы между cron-вызовами), обновляется на каждом опросе
            if (data.run_started > 0) {
                var el = Math.max(0, Math.floor(Date.now() / 1000 - parseInt(data.run_started, 10)));
                var h = Math.floor(el / 3600), m = Math.floor((el % 3600) / 60), s = el % 60;
                sv['Общее время работы'] = (h > 0 ? h + ' ч ' : '') + (m > 0 ? m + ' мин ' : '') + s + ' сек';
            }
            this.stats = sv;
            var processed = parseInt(sv['Обработано строк'], 10);
            if (processed > 0) this.processed = processed;
            var percent = parseFloat(sv['Прогресс, %']);
            if (percent > 0) this.percent = percent;
            this.renderStats();
            this.updateProgressBar();
        },

        /**
         * Топ-10 колонок из сохраненного состояния.
         */
        topColumnsFromState: function (colStats) {
            var list = [];
            for (var alias in colStats) {
                if (colStats.hasOwnProperty(alias)) {
                    list.push({
                        alias: alias,
                        name: colStats[alias].name || '',
                        fields: colStats[alias].fields || '',
                        time: colStats[alias].time || 0,
                        count: colStats[alias].count || 0
                    });
                }
            }
            list.sort(function (a, b) { return b.time - a.time; });
            return list.slice(0, 10);
        },

        /**
         * Инициализация стандартного BX.UI.ProgressBar.
         * Пока total неизвестен — работает в режиме счетчика (без процентов).
         */
        renderProgressBar: function () {
            var node = document.getElementById(this.barId);
            if (!node || !BX.UI || !BX.UI.ProgressBar) return;

            this.progressBar = new BX.UI.ProgressBar({
                container: node,
                value: 0,
                maxValue: 0,
                statusType: BX.UI.ProgressBar.Status.COUNTER,
                textBefore: '',
                textAfter: ''
            });
            this.progressBar.renderTo(node);
        },

        start: function (reset) {
            if (this.running) return;
            this.running = true;
            // новый запуск сбрасывает состояние и статистику, продолжение — нет
            this.reset = (reset !== false);
            if (this.reset) {
                this.processed = 0;
                this.total = 0;
                this.errors = [];
                this.stats = {};
                this.percent = 0;
            }
            this.resumed = false;
            this.stopped = false;
            // при продолжении таймер стартует с накопленного времени работы
            this.elapsedBase = this.reset ? 0 : (parseFloat(this.interrupted && this.interrupted.TOTAL_TIME) || 0);
            this.startTime = Date.now();

            var btn = document.getElementById(this.startBtnId);
            if (btn) btn.disabled = true;
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) contBtn.style.display = 'none';
            var nextBtn = document.getElementById(this.nextBtnId);
            if (nextBtn) nextBtn.style.display = 'none';

            this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_RUNNING'));
            this.renderStats();
            this.renderErrors();
            this.startTimer();
            this.startWatchdog();
            this.showStopLink(true);

            // параметры обработки сохраняются перед любым запуском (новый/продолжение):
            // без этого опции финального этапа (замена даты, системные флаги)
            // не попадают в профиль при продолжении прерванного импорта
            this.saveParams();
        },

        /**
         * Сохранение параметров запуска (время хита, задержка, журнал).
         */
        collectParams: function () {
            return {
                id: this.id,
                hitTimeLimit: parseInt(this.getOptionValue(this.hitTimeId), 10) || 0,
                maxExecutionTime: 0,
                logErrors: this.getCheckboxValue('awz_allxls_profile_process_log_errors') ? 'Y' : 'N',
                hashType: this.getOptionValue('awz_allxls_profile_process_hash_type') || 'item',
                hashMemCache: this.getOptionValue('awz_allxls_profile_process_hash_mem') || 'N',
                hashUsedColumns: this.getOptionValue('awz_allxls_profile_process_hash_used') || 'N',
                skipIfNotFound: this.getCheckboxValue('awz_allxls_profile_process_skip_not_found') ? 'Y' : 'N',
                systemReplaceDate: this.getCheckboxValue('awz_allxls_profile_process_sys_replace_date') ? 'Y' : 'N',
                systemFacet: this.getCheckboxValue('awz_allxls_profile_process_sys_facet') ? 'Y' : 'N',
                systemMeta: this.getCheckboxValue('awz_allxls_profile_process_sys_meta') ? 'Y' : 'N',
                systemSearchNew: this.getCheckboxValue('awz_allxls_profile_process_sys_search_new') ? 'Y' : 'N',
                systemSearchUpdate: this.getCheckboxValue('awz_allxls_profile_process_sys_search_update') ? 'Y' : 'N',
                systemResizePictures: this.getCheckboxValue('awz_allxls_profile_process_sys_resize') ? 'Y' : 'N',
                systemIblockCache: this.getCheckboxValue('awz_allxls_profile_process_sys_iblock_cache') ? 'Y' : 'N',
                finishCleanMode: this.getOptionValue('awz_allxls_profile_process_finish_clean_mode') || '',
                finishCleanScope: this.getOptionValue('awz_allxls_profile_process_finish_clean_scope') || 'profile',
                systemUpdateOrder: this.getOptionValue('awz_allxls_profile_process_sys_update_order') || 'fields_first',
                stepByStep: this.getCheckboxValue('awz_allxls_profile_process_step_by_step') ? 'Y' : 'N',
                requestDelay: parseInt(this.getOptionValue(this.requestDelayId), 10) || 0
            };
        },

        saveParams: function () {
            var self = this;
            BX.ajax.runAction(this.saveParamsAction, {
                data: this.collectParams()
            }).then(function () {
                // новый запуск — с reset, продолжение — без сброса состояния
                self.tick(self.reset);
            }, function (response) {
                self.running = false;
                self.stopTimer();
                self.stopWatchdog();
                // после ошибки импорт считается прерванным: продолжить/завершить
                self.showInterruptedButtons();
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        /**
         * Сохранение настроек обработки без запуска импорта.
         */
        saveSettings: function () {
            var self = this;
            BX.ajax.runAction(this.saveParamsAction, {
                data: this.collectParams()
            }).then(function () {
                self.setStatus(BX.message('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED'), false);
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        /**
         * Принудительное завершение прерванного импорта.
         */
        finish: function () {
            var self = this;
            BX.ajax.runAction(this.finishAction, {
                data: {id: this.id}
            }).then(function () {
                self.showStartButton();
                self.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_FINISHED'));
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        /**
         * Динамический таймер времени работы обработки.
         */
        startTimer: function () {
            this.stopTimer();
            var self = this;
            this.updateTime();
            this.timerId = setInterval(function () { self.updateTime(); }, 500);
        },

        stopTimer: function () {
            if (this.timerId) {
                clearInterval(this.timerId);
                this.timerId = null;
            }
        },

        updateTime: function () {
            var node = document.getElementById(this.timeId);
            if (!node) return;
            var elapsed = this.elapsedBase + (Date.now() - this.startTime) / 1000;
            var min = Math.floor(elapsed / 60);
            var sec = Math.round(elapsed % 60);
            node.textContent = BX.message('AWZ_ALLXLS_PROFILE_PROCESS_TIME') + ': '
                + (min > 0 ? min + ' мин ' : '') + sec + ' сек';
        },

        /**
         * Один запрос к контроллеру + обновление прогресса.
         * Следующий запрос отправляется, пока контроллер не вернет done.
         */
        tick: function (reset) {
            var self = this;
            var hitTime = parseInt(this.getOptionValue(this.hitTimeId), 10) || 0;
            // макс. время скрипта больше не задается в UI: сервер сам
            // вычисляет лимит из времени хита (hitTimeLimit + 10 сек)
            var maxTime = 0;
            var logNode = document.getElementById('awz_allxls_profile_process_log_errors');
            var logErrors = !!(logNode && logNode.checked);
            this.inflight = true;
            this.lastActivity = Date.now();
            BX.ajax.runAction(this.processAction, {
                data: {
                    id: this.id,
                    processed: this.processed,
                    total: this.total,
                    hitTimeLimit: hitTime,
                    maxExecutionTime: maxTime,
                    reset: reset ? 'Y' : 'N',
                    logErrors: logErrors ? 'Y' : 'N'
                }
            }).then(function (response) {
                self.inflight = false;
                self.lastActivity = Date.now();
                self.handleResponse(response.data || {});
            }, function (response) {
                self.inflight = false;
                self.running = false;
                self.stopTimer();
                self.stopWatchdog();
                // после ошибки импорт считается прерванным: продолжить/завершить
                self.showInterruptedButtons();
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        /**
         * Пауза между запросами (задержка, сек) — затем следующий tick.
         */
        tickDelayed: function (reset) {
            var self = this;
            var delay = parseInt(this.getOptionValue(this.requestDelayId), 10) || 0;
            if (delay <= 0) {
                this.tick(reset);
                return;
            }
            // флаг запланированного запроса: watchdog не должен
            // запустить параллельный tick во время паузы
            this.tickPending = true;
            setTimeout(function () {
                self.tickPending = false;
                if (self.stopped || !self.running) return;
                self.tick(reset);
            }, delay * 1000);
        },

        /**
         * Watchdog для фоновых вкладок: браузеры троттлят таймеры/цепочки
         * ajax-callbacks, и импорт в неактивной вкладке останавливается.
         * Web Worker не троттлится — тикает каждые 5 секунд и перезапускает
         * цепочку, если запрос не в полете (running, но inflight=false).
         */
        startWatchdog: function () {
            var self = this;
            this.stopWatchdog();
            var onTick = function () {
                if (!self.running || self.inflight || self.tickPending) return;
                // перезапускаем только если ответов нет дольше 15 сек:
                // исключаем гонку с обычной цепочкой handleResponse->tick
                if (Date.now() - (self.lastActivity || 0) < 15000) return;
                self.tick();
            };
            try {
                if (typeof Worker !== 'undefined') {
                    var blob = new Blob(
                        ['setInterval(function(){postMessage(0)},5000);'],
                        {type: 'application/javascript'}
                    );
                    this.watchdogWorker = new Worker(URL.createObjectURL(blob));
                    this.watchdogWorker.onmessage = onTick;
                    return;
                }
            } catch (e) { /* CSP/старый браузер — фолбэк ниже */ }
            this.watchdogId = setInterval(onTick, 5000);
        },

        stopWatchdog: function () {
            if (this.watchdogWorker) {
                this.watchdogWorker.terminate();
                this.watchdogWorker = null;
            }
            if (this.watchdogId) {
                clearInterval(this.watchdogId);
                this.watchdogId = null;
            }
        },

        /**
         * Остановка импорта по ссылке рядом с прогресс-баром.
         * Текущий хит завершается, состояние сохраняется на сервере,
         * следующий запрос не отправляется — импорт переводится в режим «прерван».
         */
        stop: function () {
            if (!this.running) return;
            this.stopped = true;
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) stopBtn.style.opacity = '0.5';
        },

        /**
         * Показ/скрытие ссылки остановки импорта.
         */
        showStopLink: function (show) {
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) {
                stopBtn.style.display = show ? '' : 'none';
                stopBtn.style.opacity = '';
            }
        },

        /**
         * Состояние «импорт прерван»: скрыть «Запустить», показать «Продолжить»/«Завершить».
         */
        showInterruptedButtons: function () {
            var startBtn = document.getElementById(this.startBtnId);
            if (startBtn) { startBtn.disabled = false; startBtn.style.display = ''; }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) { contBtn.disabled = false; contBtn.style.display = ''; }
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) { finBtn.disabled = false; finBtn.style.display = ''; }
            this.showStopLink(false);
        },

        /**
         * Состояние «импорт завершен»: показать только «Запустить».
         */
        showStartButton: function () {
            var startBtn = document.getElementById(this.startBtnId);
            if (startBtn) { startBtn.disabled = false; startBtn.style.display = ''; }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) contBtn.style.display = 'none';
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) finBtn.style.display = 'none';
            var nextBtn = document.getElementById(this.nextBtnId);
            if (nextBtn) nextBtn.style.display = 'none';
            this.showStopLink(false);
        },

        getOptionValue: function (id) {
            var node = document.getElementById(id);
            return node ? node.value : '';
        },

        getCheckboxValue: function (id) {
            var node = document.getElementById(id);
            return !!(node && node.checked);
        },

        /**
         * Режим «импорт по одному элементу»: опция финального этапа.
         * Сервер обрабатывает одну строку за запрос и останавливается,
         * следующий элемент запускается кнопкой «Следующий элемент».
         */
        isStepByStep: function () {
            return this.getCheckboxValue('awz_allxls_profile_process_step_by_step');
        },

        handleResponse: function (data) {
            var prevProcessed = this.processed;
            this.processed = parseInt(data.processed, 10) || this.processed;

            // синхронизация накопленного времени работы с сервером
            if (typeof data.totalTime !== 'undefined' && data.totalTime !== null) {
                this.elapsedBase = parseFloat(data.totalTime) || 0;
                this.startTime = Date.now();
            }

            // первый ответ: сервер вернул сохраненное состояние — обработка возобновлена
            if (!this.resumed && prevProcessed === 0 && this.processed > 0 && !data.done) {
                this.resumed = true;
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_RESUME') + ' (' + this.processed + ')');
            }

            // финальное количество может не передаваться
            if (data.total !== null && typeof data.total !== 'undefined') {
                this.total = parseInt(data.total, 10) || 0;
            }

            // прогресс чтения файла в процентах (когда total неизвестен):
            // по счетчику байтов для CSV или по строкам листа для XLSX/ODS
            if (typeof data.percent !== 'undefined' && data.percent !== null) {
                this.percent = parseFloat(data.percent) || 0;
            }

            if (data.errors && data.errors.length) {
                this.errors = this.errors.concat(data.errors);
                this.renderErrors();
            }

            if (data.stats) {
                for (var key in data.stats) {
                    if (data.stats.hasOwnProperty(key)) {
                        this.stats[key] = data.stats[key];
                    }
                }
                this.renderStats();
            }

            if (data.item) {
                this.renderItem(data.item);
            }

            if (data.debug) {
                this.renderDebug(data.debug, data.item || null);
            }

            if (data.columns && data.columns.length) {
                this.renderColumns(data.columns);
            }

            this.updateProgressBar();

            if (data.done) {
                this.running = false;
                this.stopTimer();
                this.stopWatchdog();
                this.showStartButton();
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DONE'));
            } else if (this.stopped) {
                // остановка по ссылке: текущий хит завершен, состояние сохранено на сервере
                this.running = false;
                this.stopTimer();
                this.stopWatchdog();
                this.showInterruptedButtons();
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_STOPPED'), true);
            } else if (this.isStepByStep()) {
                // режим «по одному элементу»: строка обработана — останавливаем
                // цепочку, следующий элемент запускается кнопкой «Следующий элемент»
                this.running = false;
                this.stopTimer();
                this.stopWatchdog();
                this.showInterruptedButtons();
                var nextBtn = document.getElementById(this.nextBtnId);
                if (nextBtn) { nextBtn.disabled = false; nextBtn.style.display = ''; }
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_STEP_STOPPED'));
            } else {
                this.tickDelayed();
            }
        },

        /**
         * Топ-10 самых нагруженных колонок по времени обработки.
         */
        renderColumns: function (columns) {
            var node = document.getElementById(this.columnsId);
            if (!node) return;
            if (!columns || !columns.length) {
                node.innerHTML = '';
                return;
            }
            var html = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COLUMNS'))
                + '</div><div style="overflow-x:auto;"><table style="margin-top:6px;border-collapse:collapse;min-width:680px;">'
                + '<tr style="background:#e8edf3;">'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;">#</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_ALIAS')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_NAME')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;max-width:300px;overflow-wrap:anywhere;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_FIELDS')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_TIME')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;white-space:nowrap;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_COUNT')) + '</th>'
                + '</tr>';
            columns.forEach(function (col, idx) {
                var bg = (idx % 2 === 0) ? '#ffffff' : '#f2f5f9';
                var cell = 'padding:4px 10px;border:1px solid #e0e5ea;';
                html += '<tr style="background:' + bg + ';">'
                    + '<td style="' + cell + '">' + (idx + 1) + '</td>'
                    + '<td style="' + cell + 'white-space:nowrap;">' + BX.util.htmlspecialchars(String(col.alias)) + '</td>'
                    + '<td style="' + cell + '">' + BX.util.htmlspecialchars(String(col.name || '')) + '</td>'
                    + '<td style="' + cell + 'max-width:300px;overflow-wrap:anywhere;">' + BX.util.htmlspecialchars(String(col.fields || '')) + '</td>'
                    + '<td style="' + cell + 'white-space:nowrap;"><b>' + (Math.round(col.time * 10000) / 10000) + '</b> сек</td>'
                    + '<td style="' + cell + 'white-space:nowrap;">' + col.count + '</td>'
                    + '</tr>';
            });
            html += '</table></div>';
            node.innerHTML = html;
        },

        updateProgressBar: function () {
            if (!this.progressBar) return;

            this.progressBar.setTextBefore(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_PROCESSED') + ': ' + this.processed);

            if (this.total > 0) {
                // известное количество — проценты
                this.progressBar.setMaxValue(this.total);
                this.progressBar.setValue(this.processed);
                this.progressBar.setStatusType(BX.UI.ProgressBar.Status.PERCENT);
            } else if (this.percent > 0) {
                // количество неизвестно — проценты чтения файла
                // (по счетчику байтов для CSV или по строкам листа для XLSX/ODS)
                this.progressBar.setMaxValue(100);
                this.progressBar.setValue(Math.min(100, Math.round(this.percent)));
                this.progressBar.setStatusType(BX.UI.ProgressBar.Status.PERCENT);
            } else {
                // количество неизвестно — режим счетчика
                this.progressBar.setMaxValue(0);
                this.progressBar.setValue(this.processed);
                this.progressBar.setStatusType(BX.UI.ProgressBar.Status.COUNTER);
            }
            this.progressBar.update();
        },

        setStatus: function (text, isError) {
            var node = document.getElementById(this.statusId);
            if (!node) return;
            node.textContent = text || '';
            node.style.color = isError ? '#d9534f' : '';
        },

        /**
         * Вывод JSON обрабатываемого элемента в поле на странице.
         * textarea создается один раз: пользовательское расширение поля
         * (resize) и позиция скролла не сбрасываются на каждом тике импорта.
         */
        renderItem: function (item) {
            var node = document.getElementById(this.itemId);
            if (!node) return;
            var json;
            try {
                json = JSON.stringify(item, null, 2);
            } catch (e) {
                json = String(item);
            }
            var ta = node.querySelector('textarea');
            if (!ta) {
                node.innerHTML = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                    + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ITEM'))
                    + '</div><textarea readonly style="width:100%;height:120px;margin-top:6px;font-family:monospace;'
                    + 'font-size:12px;box-sizing:border-box;"></textarea>';
                ta = node.querySelector('textarea');
            }
            if (!ta) return;
            // сохраняем позицию скролла (пользователь мог читать середину JSON)
            var scrollTop = ta.scrollTop;
            ta.value = json;
            ta.scrollTop = scrollTop;
        },

        /**
         * Техническая информация по обработанной строке:
         * значения колонок из файла, сработавшие правила обработки,
         * причина пропуска, фильтр поиска и режим обработки элемента.
         */
        renderDebug: function (debug, item) {
            var node = document.getElementById(this.debugId);
            if (!node) return;
            debug = debug || {};
            var esc = BX.util.htmlspecialchars;
            var html = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG')) + '</div>';

            // значения колонок из файла + финальные значения (результат обработки)
            if (debug.raw && debug.raw.length) {
                var cols = debug.cols || {};
                var rulesCol = debug.rules_col || {};
                html += '<div style="margin-top:6px;font-weight:bold;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RAW')) + '</div>'
                    + '<table style="margin-top:4px;border-collapse:collapse;width:100%;">'
                    + '<tr style="background:#e8edf3;">'
                    + '<th style="padding:3px 8px;border:1px solid #e0e5ea;text-align:left;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_COL_NAME')) + '</th>'
                    + '<th style="padding:3px 8px;border:1px solid #e0e5ea;text-align:left;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_VALUE')) + '</th>'
                    + '<th style="padding:3px 8px;border:1px solid #e0e5ea;text-align:left;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_FINAL')) + '</th>'
                    + '<th style="padding:3px 8px;border:1px solid #e0e5ea;text-align:left;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RULES_COL')) + '</th></tr>';
                debug.raw.forEach(function (c) {
                    var finalHtml = '';
                    if (cols[c.key] && cols[c.key].length) {
                        cols[c.key].forEach(function (f) {
                            if (finalHtml) finalHtml += '; ';
                            finalHtml += esc(String(f.field)) + ' = ' + esc(String(f.value));
                        });
                    } else if (debug.skip_rule) {
                        finalHtml = '<span style="color:#d9534f;">'
                            + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_ROW')) + '</span>';
                    } else if (debug.skip_type) {
                        finalHtml = esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_HASH'));
                    } else {
                        finalHtml = '&mdash;';
                    }
                    // сработавшие правила колонки (поле: описание условия → действие)
                    var rulesHtml = '';
                    if (rulesCol[c.key] && rulesCol[c.key].length) {
                        rulesCol[c.key].forEach(function (r, i) {
                            if (i) rulesHtml += '<br>';
                            rulesHtml += '<span style="color:#2067b0;">'
                                + esc(String(r.field)) + '</span>: ' + esc(String(r.text));
                        });
                    } else {
                        rulesHtml = '&mdash;';
                    }
                    var dcell = 'padding:3px 8px;border:1px solid #e0e5ea;max-width:280px;overflow-wrap:anywhere;';
                    html += '<tr><td style="' + dcell + '">'
                        + esc(String(c.col || '') + (c.header ? ' (' + c.header + ')' : ''))
                        + '</td><td style="' + dcell + '">'
                        + esc(String(c.value || ''))
                        + '</td><td style="' + dcell + '">'
                        + finalHtml
                        + '</td><td style="' + dcell + '">'
                        + rulesHtml + '</td></tr>';
                });
                html += '</table>';
            }

            // сработавшие правила обработки
            html += '<div style="margin-top:10px;font-weight:bold;">'
                + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_RULES')) + '</div>';
            if (debug.rules && debug.rules.length) {
                html += '<ul style="margin-top:4px;">';
                debug.rules.forEach(function (r) {
                    var line = esc(String(r.field || '?')) + ': ' + esc(String(r.type || ''));
                    if (r.action) line += ' → ' + esc(String(r.action));
                    if (r.aval) line += ' (' + esc(String(r.aval)) + ')';
                    html += '<li>' + line + '</li>';
                });
                html += '</ul>';
            } else {
                html += '<div class="awz-allxls-row__desc" style="margin-top:4px;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_NONE')) + '</div>';
            }

            // значения всех макросов (для отладки правил)
            if (debug.macros && Object.keys(debug.macros).length) {
                html += '<div style="margin-top:10px;font-weight:bold;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MACROS')) + '</div><ul style="margin-top:4px;">';
                Object.keys(debug.macros).forEach(function (k) {
                    var v = debug.macros[k];
                    html += '<li>' + esc(k) + ': '
                        + (Array.isArray(v) ? v.join(' | ') : String(v)) + '</li>';
                });
                html += '</ul>';
            }

            // пропуск по правилу
            if (debug.skip_rule) {
                html += '<div style="margin-top:10px;font-weight:bold;color:#d9534f;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP')) + '</div><ul style="margin-top:4px;">';
                html += '<li>' + esc(JSON.stringify(debug.skip_rule)) + '</li>';
                if (debug.skip_value !== null && debug.skip_value !== '') {
                    html += '<li>' + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SKIP_VALUE'))
                        + ': ' + esc(String(debug.skip_value)) + '</li>';
                }
                html += '</ul>';
            }

            // фильтр поиска / режим обработки (из item.debug)
            var itemDebug = (item && item.debug) ? item.debug : null;
            if (itemDebug) {
                html += '<div style="margin-top:10px;font-weight:bold;">'
                    + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_SEARCH')) + '</div><ul style="margin-top:4px;">';
                html += '<li>' + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE')) + ': '
                    + esc(itemDebug.mode === 'update'
                        ? BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_UPDATE')
                        : BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_NEW')) + '</li>';
                var times = itemDebug.times || null;
                if (times) {
                    var opLabel = times.op === 'update'
                        ? BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_UPDATE')
                        : BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_MODE_NEW');
                    html += '<li>' + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_TIME')) + ': '
                        + esc(opLabel) + ' — поиск ' + Number(times.find || 0).toFixed(4)
                        + ' сек, ' + esc(times.op === 'update'
                            ? BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_T_UPDATE')
                            : BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_T_ADD')) + ' '
                        + Number(times.save || 0).toFixed(4) + ' сек</li>';
                }
                if (itemDebug.filter && Object.keys(itemDebug.filter).length) {
                    html += '<li>' + esc(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DEBUG_FILTER'))
                        + ': ' + esc(JSON.stringify(itemDebug.filter)) + '</li>';
                }
                html += '</ul>';
            }
            node.innerHTML = html;
        },

        renderStats: function () {
            var node = document.getElementById(this.statsId);
            if (!node) return;
            var keys = Object.keys(this.stats);
            if (!keys.length) {
                node.innerHTML = '';
                return;
            }
            var html = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_STATS'))
                + '</div><table class="awz-allxls-stats-table" style="margin-top:6px;border-collapse:collapse;width:100%;">';
            keys.forEach(function (key, idx) {
                var bg = (idx % 2 === 0) ? '#ffffff' : '#f2f5f9';
                html += '<tr style="background:' + bg + ';">'
                    + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">' + BX.util.htmlspecialchars(key)
                    + '</td><td style="padding:4px 10px;border:1px solid #e0e5ea;"><b>' + BX.util.htmlspecialchars(String(this.stats[key]))
                    + '</b></td></tr>';
            }, this);
            html += '</table>';
            node.innerHTML = html;
        },

        renderErrors: function () {
            var node = document.getElementById(this.errorsId);
            if (!node) return;
            if (!this.errors.length) {
                node.innerHTML = '';
                return;
            }
            var html = '<div class="adm-info-message" style="color:#d9534f;">'
                + '<div class="adm-info-message-title">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERRORS_TITLE'))
                + '</div><ul>';
            this.errors.forEach(function (msg) {
                html += '<li>' + BX.util.htmlspecialchars(String(msg)) + '</li>';
            });
            html += '</ul></div>';
            node.innerHTML = html;
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
    window.Awz.Allxls.ProfilesEditProcess = ProfilesEditProcess;
})(window);
