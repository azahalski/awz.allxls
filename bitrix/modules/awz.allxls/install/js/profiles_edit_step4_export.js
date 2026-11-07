/**
 * AWZ.AllXLS: шаг 4 (экспорт) — пошаговая выгрузка с прогресс-баром.
 * Клон ProfilesEditProcess с действиями контроллера pexport
 * и ссылкой на файл выгрузки по завершении.
 */
(function (window) {
    'use strict';

    if (!window.Awz) window.Awz = {};

    var ProfilesEditExportProcess = function (options) {
        options = options || {};
        this.id = parseInt(options.id, 10) || 0;
        this.listUrl = options.listUrl || '/bitrix/admin/awz_allxls_profiles_list.php';
        this.processAction = options.processAction || 'awz:allxls.api.pexport.export';
        this.barId = options.barId || 'awz_allxls_profile_process_bar';
        this.statusId = options.statusId || 'awz_allxls_profile_process_status';
        this.itemId = options.itemId || 'awz_allxls_profile_process_item';
        this.statsId = options.statsId || 'awz_allxls_profile_process_stats';
        this.errorsId = options.errorsId || 'awz_allxls_profile_process_errors';
        this.startBtnId = options.startBtnId || 'awz_allxls_profile_process_start';
        this.continueBtnId = options.continueBtnId || 'awz_allxls_profile_process_continue';
        this.finishBtnId = options.finishBtnId || 'awz_allxls_profile_process_finish';
        this.saveParamsAction = options.saveParamsAction || 'awz:allxls.api.pexport.saveparams';
        this.finishAction = options.finishAction || 'awz:allxls.api.pexport.finish';
        this.interrupted = options.interrupted || null;
        this.reset = false;
        this.hitTimeId = options.hitTimeId || 'awz_allxls_profile_process_hit_time';
        this.maxTimeId = options.maxTimeId || 'awz_allxls_profile_process_max_time';
        this.timeId = options.timeId || 'awz_allxls_profile_process_time';
        this.stopBtnId = options.stopBtnId || 'awz_allxls_profile_process_stop';
        this.saveBtnId = options.saveBtnId || 'awz_allxls_profile_process_save';
        this.stepByStepId = options.stepByStepId || 'awz_allxls_profile_process_step_by_step';
        this.requestDelayId = options.requestDelayId || 'awz_allxls_profile_process_request_delay';
        this.debugId = options.debugId || 'awz_allxls_profile_process_debug';

        this.progressBar = null;
        this.running = false;
        this.stopped = false;
        this.processed = 0;
        this.percent = 0;
        this.errors = [];
        this.stats = {};
        this.fileUrl = '';
        this.resumed = false;
        this.startTime = 0;
        this.elapsedBase = 0;
        this.timerId = null;
        this.cronPollId = null; // фоновый опрос статистики cron-запуска (setInterval)
        this.delayTimerId = null; // пауза между запросами (setTimeout перед следующим tick)
    };

    ProfilesEditExportProcess.prototype = {
        init: function () {
            this.renderProgressBar();
            var self = this;
            var btn = document.getElementById(this.startBtnId);
            if (btn) {
                BX.bind(btn, 'click', function (e) { BX.PreventDefault(e); self.start(true); });
            }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) {
                BX.bind(contBtn, 'click', function (e) { BX.PreventDefault(e); self.start(false); });
            }
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) {
                BX.bind(finBtn, 'click', function (e) { BX.PreventDefault(e); self.finish(); });
            }
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) {
                BX.bind(stopBtn, 'click', function (e) { BX.PreventDefault(e); self.stop(); });
            }
            // сохранение настроек обработки без запуска выгрузки
            var saveBtn = document.getElementById(this.saveBtnId);
            if (saveBtn) {
                BX.bind(saveBtn, 'click', function (e) { BX.PreventDefault(e); self.saveSettings(); });
            }
            if (this.interrupted && typeof this.interrupted === 'object') {
                this.processed = parseInt(this.interrupted.PROCESSED, 10) || 0;
                var viewStats = (this.interrupted.STATS_VIEW && Object.keys(this.interrupted.STATS_VIEW).length)
                    ? this.interrupted.STATS_VIEW
                    : (this.interrupted.STATS || {});
                this.stats = viewStats;
                this.errors = this.interrupted.ERRORS || [];
                this.fileUrl = this.interrupted.FILE || '';
                if (Object.keys(this.stats).length) this.renderStats();
                if (this.errors.length) this.renderErrors();
                if (this.processed > 0 && this.interrupted.DONE !== 'Y') {
                    if (btn) btn.style.display = 'none';
                    this.updateProgressBar();
                    if (this.interrupted.SOURCE !== 'cron') {
                        this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED') + ' (' + this.processed + ')', true);
                    }
                } else {
                    this.updateProgressBar();
                    if (this.interrupted.DONE === 'Y' && this.fileUrl) {
                        this.renderFileLink();
                    }
                }
            }
            // факт запуска на crontab проверяется один раз при загрузке
            // финального этапа: фоновый опрос стартует только если выгрузка
            // реально запущена cron-процессом (см. startCronPoll)
            this.startCronPoll();
        },

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
            this.reset = (reset !== false);
            if (this.reset) {
                this.processed = 0;
                this.errors = [];
                this.stats = {};
                this.percent = 0;
                this.fileUrl = '';
            }
            this.resumed = false;
            this.stopped = false;
            this.elapsedBase = this.reset ? 0 : (parseFloat(this.interrupted && this.interrupted.TOTAL_TIME) || 0);
            this.startTime = Date.now();

            var btn = document.getElementById(this.startBtnId);
            if (btn) btn.disabled = true;
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) contBtn.style.display = 'none';

            this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_RUNNING'));
            this.renderStats();
            this.renderErrors();
            this.startTimer();
            this.showStopLink(true);

            if (this.reset) {
                this.saveParams();
            } else {
                this.tick();
            }
        },

        saveParams: function () {
            var self = this;
            var hitTime = parseInt(this.getOptionValue(this.hitTimeId), 10) || 0;
            var maxTime = (parseInt(this.getOptionValue(this.maxTimeId), 10) || 0) * 60;
            var logNode = document.getElementById('awz_allxls_profile_process_log_errors');
            var logErrors = !!(logNode && logNode.checked);
            BX.ajax.runAction(this.saveParamsAction, {
                data: {
                    id: this.id,
                    hitTimeLimit: hitTime,
                    maxExecutionTime: maxTime,
                    logErrors: logErrors ? 'Y' : 'N',
                    requestDelay: parseInt(this.getOptionValue(this.requestDelayId), 10) || 0,
                    stepByStep: this.getCheckboxValue(this.stepByStepId) ? 'Y' : 'N'
                }
            }).then(function () {
                self.tick(true);
            }, function (response) {
                self.running = false;
                self.stopTimer();
                self.showInterruptedButtons();
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        saveSettings: function () {
            var self = this;
            var maxTime = (parseInt(this.getOptionValue(this.maxTimeId), 10) || 0) * 60;
            BX.ajax.runAction(this.saveParamsAction, {
                data: {
                    id: this.id,
                    hitTimeLimit: parseInt(this.getOptionValue(this.hitTimeId), 10) || 0,
                    maxExecutionTime: maxTime,
                    logErrors: this.getCheckboxValue('awz_allxls_profile_process_log_errors') ? 'Y' : 'N',
                    requestDelay: parseInt(this.getOptionValue(this.requestDelayId), 10) || 0,
                    stepByStep: this.getCheckboxValue(this.stepByStepId) ? 'Y' : 'N'
                }
            }).then(function () {
                self.setStatus(BX.message('AWZ_ALLXLS_S_PROFILES_EDIT_STEP4_SAVED'), false);
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        finish: function () {
            var self = this;
            BX.ajax.runAction(this.finishAction, {
                data: {id: this.id}
            }).then(function (response) {
                self.showStartButton();
                if (response.data && response.data.file_url) {
                    self.fileUrl = response.data.file_url;
                }
                self.renderFileLink();
                self.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_FINISHED'));
            }, function (response) {
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

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
            if (this.delayTimerId) {
                clearTimeout(this.delayTimerId);
                this.delayTimerId = null;
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

        tick: function (reset) {
            var self = this;
            var hitTime = parseInt(this.getOptionValue(this.hitTimeId), 10) || 0;
            var maxTime = (parseInt(this.getOptionValue(this.maxTimeId), 10) || 0) * 60;
            var logNode = document.getElementById('awz_allxls_profile_process_log_errors');
            var logErrors = !!(logNode && logNode.checked);
            BX.ajax.runAction(this.processAction, {
                data: {
                    id: this.id,
                    hitTimeLimit: hitTime,
                    maxExecutionTime: maxTime,
                    reset: reset ? 'Y' : 'N',
                    logErrors: logErrors ? 'Y' : 'N',
                    requestDelay: parseInt(this.getOptionValue(this.requestDelayId), 10) || 0,
                    stepByStep: this.getCheckboxValue(this.stepByStepId) ? 'Y' : 'N'
                }
            }).then(function (response) {
                self.handleResponse(response.data || {});
            }, function (response) {
                self.running = false;
                self.stopTimer();
                self.showInterruptedButtons();
                self.setStatus(self.errorMessage(response, BX.message('AWZ_ALLXLS_PROFILE_PROCESS_ERROR')), true);
            });
        },

        getCheckboxValue: function (id) {
            var node = document.getElementById(id);
            return !!(node && node.checked);
        },

        /** Пауза между запросами (сек): следующий tick через задержку */
        nextTick: function () {
            var self = this;
            var delay = parseInt(this.getOptionValue(this.requestDelayId), 10) || 0;
            if (delay > 0) {
                this.delayTimerId = setTimeout(function () {
                    self.delayTimerId = null;
                    self.tick();
                }, delay * 1000);
            } else {
                this.tick();
            }
        },

        stop: function () {
            if (!this.running) return;
            this.stopped = true;
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) stopBtn.style.opacity = '0.5';
        },

        showStopLink: function (show) {
            var stopBtn = document.getElementById(this.stopBtnId);
            if (stopBtn) {
                stopBtn.style.display = show ? '' : 'none';
                stopBtn.style.opacity = '';
            }
        },

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
            ['continueBtnId', 'finishBtnId'].forEach(function (key) {
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
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED') + ' (' + this.processed + ')', true);
            }
        },

        cronPollTick: function () {
            if (this.running) return;
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
                    self.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_INTERRUPTED') + ' (' + self.processed + ')', true);
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
            var processed = parseInt(sv['Выгружено строк'], 10);
            if (processed > 0) this.processed = processed;
            var percent = parseFloat(sv['Прогресс, %']);
            if (percent > 0) this.percent = percent;
            this.renderStats();
            this.updateProgressBar();
        },

        showInterruptedButtons: function () {
            var startBtn = document.getElementById(this.startBtnId);
            if (startBtn) { startBtn.disabled = false; startBtn.style.display = ''; }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) { contBtn.disabled = false; contBtn.style.display = ''; }
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) { finBtn.disabled = false; finBtn.style.display = ''; }
            this.showStopLink(false);
        },

        showStartButton: function () {
            var startBtn = document.getElementById(this.startBtnId);
            if (startBtn) { startBtn.disabled = false; startBtn.style.display = ''; }
            var contBtn = document.getElementById(this.continueBtnId);
            if (contBtn) contBtn.style.display = 'none';
            var finBtn = document.getElementById(this.finishBtnId);
            if (finBtn) finBtn.style.display = 'none';
            this.showStopLink(false);
        },

        getOptionValue: function (id) {
            var node = document.getElementById(id);
            return node ? node.value : '';
        },

        handleResponse: function (data) {
            var prevProcessed = this.processed;
            this.processed = parseInt(data.processed, 10) || this.processed;

            if (typeof data.totalTime !== 'undefined' && data.totalTime !== null) {
                this.elapsedBase = parseFloat(data.totalTime) || 0;
                this.startTime = Date.now();
            }

            if (!this.resumed && prevProcessed === 0 && this.processed > 0 && !data.done) {
                this.resumed = true;
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_RESUME') + ' (' + this.processed + ')');
            }

            if (typeof data.percent !== 'undefined' && data.percent !== null) {
                this.percent = parseFloat(data.percent) || 0;
            }

            if (data.file_url) {
                this.fileUrl = data.file_url;
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

            this.updateProgressBar();

            if (data.debug) {
                this.renderDebug(data.debug);
            }

            if (data.done) {
                this.running = false;
                this.stopTimer();
                this.showStartButton();
                this.renderFileLink();
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_DONE'));
            } else if (this.stopped) {
                this.running = false;
                this.stopTimer();
                this.showInterruptedButtons();
                this.setStatus(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_STOPPED'), true);
            } else if (data.step_by_step) {
                // режим «по одному элементу»: цепочка останавливается после
                // каждого элемента, следующий — кнопка «Продолжить»
                this.running = false;
                this.stopTimer();
                this.showInterruptedButtons();
                var item = data.item || {};
                this.setStatus(item.skip
                    ? BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_ITEM_SKIPPED') + ' (' + this.processed + ')'
                    : (item.id
                        ? BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_ITEM') + ' #' + item.id + ' (' + this.processed + ')'
                        : BX.message('AWZ_ALLXLS_PROFILE_PROCESS_STOPPED')));
            } else {
                this.nextTick();
            }
        },

        /**
         * Подробная техническая информация по выгруженному элементу
         * (режим «по одному элементу»).
         */
        renderDebug: function (debug) {
            var node = document.getElementById(this.debugId);
            if (!node) return;
            if (!debug || !debug.columns || !debug.columns.length) {
                node.innerHTML = '';
                return;
            }
            var html = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG'))
                + '</div><table class="awz-allxls-stats-table" style="margin-top:6px;border-collapse:collapse;width:100%;">';
            html += '<tr style="background:#e8eef7;">'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_COL')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_FIELD')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_RAW')) + '</th>'
                + '<th style="padding:4px 10px;border:1px solid #e0e5ea;text-align:left;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_VALUE')) + '</th>'
                + '</tr>';
            debug.columns.forEach(function (col, idx) {
                var bg = (idx % 2 === 0) ? '#ffffff' : '#f2f5f9';
                html += '<tr style="background:' + bg + ';">'
                    + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">' + BX.util.htmlspecialchars(col.name) + '</td>'
                    + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">' + BX.util.htmlspecialchars(col.field) + '</td>'
                    + '<td style="padding:4px 10px;border:1px solid #e0e5ea;">' + BX.util.htmlspecialchars(col.raw) + '</td>'
                    + '<td style="padding:4px 10px;border:1px solid #e0e5ea;"><b>' + BX.util.htmlspecialchars(col.value) + '</b></td>'
                    + '</tr>';
            });
            html += '</table>';
            html += '<div style="margin-top:6px;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_EXP_DEBUG_ELEM')) + ': <b>#'
                + BX.util.htmlspecialchars(String(debug.element_id || ''))
                + '</b>'
                + (debug.sheet ? ' · ' + BX.util.htmlspecialchars(String(debug.sheet)) : '')
                + (debug.row ? ' · ' + BX.util.htmlspecialchars(String(debug.row)) : '')
                + (debug.skip
                    ? ' · <span style="color:#d9534f;">' + BX.util.htmlspecialchars(String(debug.skip_reason)) + '</span>'
                    : '')
                + '</div>';
            node.innerHTML = html;
        },

        /**
         * Ссылка на файл выгрузки.
         */
        renderFileLink: function () {
            var node = document.getElementById(this.itemId);
            if (!node) return;
            if (!this.fileUrl) {
                node.innerHTML = '';
                return;
            }
            node.innerHTML = '<div class="awz-allxls-card__title" style="font-size:14px;">'
                + BX.util.htmlspecialchars(BX.message('AWZ_ALLXLS_EXPORT_FILE')) + ':</div>'
                + '<a href="' + BX.util.htmlspecialchars(this.fileUrl) + '" target="_blank" style="display:inline-block;margin-top:6px;">'
                + BX.util.htmlspecialchars(this.fileUrl) + '</a>';
        },

        updateProgressBar: function () {
            if (!this.progressBar) return;
            this.progressBar.setTextBefore(BX.message('AWZ_ALLXLS_PROFILE_PROCESS_PROCESSED') + ': ' + this.processed);
            if (this.percent > 0) {
                this.progressBar.setMaxValue(100);
                this.progressBar.setValue(Math.min(100, Math.round(this.percent)));
                this.progressBar.setStatusType(BX.UI.ProgressBar.Status.PERCENT);
            } else {
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
    window.Awz.Allxls.ProfilesEditExportProcess = ProfilesEditExportProcess;
})(window);
