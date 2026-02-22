(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function JobListModalController(options) {
        options = options || {};
        this.store = options.store;
        this.scheduler = options.scheduler;
        this.utils = options.utils;

        this.openButton = document.getElementById('job-list-open');
        this.modal = document.getElementById('joblist-modal');
        this.closeButton = document.getElementById('joblist-modal-close');
        this.closeFooterButton = document.getElementById('joblist-close-footer');

        this.input = document.getElementById('job-list-input');
        this.addButton = document.getElementById('job-list-add');
        this.startButton = document.getElementById('job-list-start');
        this.stopButton = document.getElementById('job-list-stop');
        this.clearFailedButton = document.getElementById('job-list-clear-failed');

        this.defaultFormat = document.getElementById('job-default-format');
        this.defaultMp3Bitrate = document.getElementById('job-default-mp3-bitrate');
        this.defaultMp3Speed = document.getElementById('job-default-mp3-speed');
        this.defaultMp4Height = document.getElementById('job-default-mp4-height');
        this.defaultMp4Mode = document.getElementById('job-default-mp4-mode');
        this.outputSummary = document.getElementById('job-output-summary');

        this.feedback = document.getElementById('joblist-feedback');
        this.items = document.getElementById('joblist-items');
        this.statsPending = document.getElementById('joblist-stat-pending');
        this.statsRunning = document.getElementById('joblist-stat-running');
        this.statsFailed = document.getElementById('joblist-stat-failed');
        this.statsCompleted = document.getElementById('joblist-stat-completed');

        this.unsubscribeStore = null;
        this.unsubscribeScheduler = null;
        this.schedulerState = this.scheduler.getState();
        this.latestSnapshot = this.store.getSnapshot();
    }

    JobListModalController.prototype.getFieldContainer = function (element) {
        if (!element || typeof element.closest !== 'function') {
            return null;
        }
        return element.closest('.field');
    };

    JobListModalController.prototype.getDefaultOutputFormat = function () {
        return this.defaultFormat && this.defaultFormat.value === 'mp4' ? 'mp4' : 'mp3';
    };

    JobListModalController.prototype.updateDefaultOutputUi = function () {
        var format = this.getDefaultOutputFormat();

        var mp3Fields = [
            this.getFieldContainer(this.defaultMp3Bitrate),
            this.getFieldContainer(this.defaultMp3Speed)
        ];
        var mp4Fields = [
            this.getFieldContainer(this.defaultMp4Height),
            this.getFieldContainer(this.defaultMp4Mode)
        ];

        mp3Fields.forEach(function (field) {
            if (field) {
                field.hidden = format !== 'mp3';
            }
        });
        mp4Fields.forEach(function (field) {
            if (field) {
                field.hidden = format !== 'mp4';
            }
        });

        if (this.outputSummary) {
            if (format === 'mp3') {
                this.outputSummary.textContent = 'Current default output: Audio (MP3). Jobs will download MP3 files.';
            } else {
                this.outputSummary.textContent = 'Current default output: Video (MP4). Jobs will download MP4 files.';
            }
        }
    };

    JobListModalController.prototype.escapeHtml = function (value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    JobListModalController.prototype.isOpen = function () {
        return !!this.modal && this.modal.hidden === false;
    };

    JobListModalController.prototype.updateGlobalScrollLock = function () {
        var openModal = document.querySelector('.modal-backdrop.sichtbar:not([hidden])');
        document.body.style.overflow = openModal ? 'hidden' : '';
    };

    JobListModalController.prototype.open = function () {
        if (!this.modal) {
            return;
        }
        this.modal.hidden = false;
        void this.modal.offsetWidth;
        this.modal.classList.add('sichtbar');
        this.updateGlobalScrollLock();
        this.render();

        if (this.input) {
            this.input.focus();
        }
    };

    JobListModalController.prototype.close = function () {
        var self = this;
        if (!this.modal) {
            return;
        }
        this.modal.classList.remove('sichtbar');
        global.setTimeout(function () {
            self.modal.hidden = true;
            self.updateGlobalScrollLock();
        }, 220);
    };

    JobListModalController.prototype.setFeedback = function (text, type) {
        if (!this.feedback) {
            return;
        }
        this.feedback.textContent = String(text || '');
        this.feedback.classList.remove('is-ok', 'is-warn', 'is-error');
        if (type === 'ok') {
            this.feedback.classList.add('is-ok');
        } else if (type === 'error') {
            this.feedback.classList.add('is-error');
        } else if (type === 'warn') {
            this.feedback.classList.add('is-warn');
        }
    };

    JobListModalController.prototype.readDefaultsFromUi = function () {
        return {
            format: this.defaultFormat ? this.defaultFormat.value : 'mp3',
            mp3Bitrate: this.defaultMp3Bitrate ? Number.parseInt(String(this.defaultMp3Bitrate.value || 320), 10) : 320,
            mp3EncodingSpeed: this.defaultMp3Speed ? String(this.defaultMp3Speed.value || '2') : '2',
            mp4Height: this.defaultMp4Height ? Number.parseInt(String(this.defaultMp4Height.value || 1080), 10) : 1080,
            mp4BitrateMode: this.defaultMp4Mode ? String(this.defaultMp4Mode.value || 'average') : 'average'
        };
    };

    JobListModalController.prototype.writeDefaultsToUi = function (defaults) {
        if (this.defaultFormat) {
            this.defaultFormat.value = defaults.format;
        }
        if (this.defaultMp3Bitrate) {
            this.defaultMp3Bitrate.value = String(defaults.mp3Bitrate);
        }
        if (this.defaultMp3Speed) {
            this.defaultMp3Speed.value = String(defaults.mp3EncodingSpeed);
        }
        if (this.defaultMp4Height) {
            this.defaultMp4Height.value = String(defaults.mp4Height);
        }
        if (this.defaultMp4Mode) {
            this.defaultMp4Mode.value = String(defaults.mp4BitrateMode);
        }
        this.updateDefaultOutputUi();
    };

    JobListModalController.prototype.bindEvents = function () {
        var self = this;

        if (this.openButton) {
            this.openButton.addEventListener('click', function () {
                self.open();
            });
        }

        if (this.closeButton) {
            this.closeButton.addEventListener('click', function () {
                self.close();
            });
        }

        if (this.closeFooterButton) {
            this.closeFooterButton.addEventListener('click', function () {
                self.close();
            });
        }

        if (this.modal) {
            this.modal.addEventListener('click', function (event) {
                if (event.target === self.modal) {
                    self.close();
                }
            });
        }

        if (this.addButton) {
            this.addButton.addEventListener('click', function () {
                var text = self.input ? self.input.value : '';
                var result = self.store.enqueueFromText(text);
                if (result.added > 0 && self.input) {
                    self.input.value = '';
                }

                if (result.added > 0) {
                    self.setFeedback('Added ' + result.added + ' job(s).', 'ok');
                    if (self.scheduler.isRunning()) {
                        self.scheduler.requestDispatch();
                    }
                    return;
                }

                if (result.invalid > 0 || result.duplicates > 0) {
                    self.setFeedback('No jobs added. Invalid: ' + result.invalid + ', duplicates: ' + result.duplicates + '.', 'warn');
                    return;
                }

                self.setFeedback('No valid URLs found in input.', 'warn');
            });
        }

        if (this.startButton) {
            this.startButton.addEventListener('click', function () {
                self.scheduler.start();
                self.setFeedback('Auto mode started.', 'ok');
            });
        }

        if (this.stopButton) {
            this.stopButton.addEventListener('click', function () {
                self.scheduler.stop();
                self.setFeedback('Auto mode stopped. Running jobs continue.', 'warn');
            });
        }

        if (this.clearFailedButton) {
            this.clearFailedButton.addEventListener('click', function () {
                self.store.clearFailed();
                self.setFeedback('Failed jobs cleared.', 'ok');
            });
        }

        var defaultsHandler = function () {
            self.store.setDefaults(self.readDefaultsFromUi());
            self.updateDefaultOutputUi();
            self.setFeedback('Default profile saved.', 'ok');
        };

        [this.defaultFormat, this.defaultMp3Bitrate, this.defaultMp3Speed, this.defaultMp4Height, this.defaultMp4Mode]
            .filter(function (item) { return !!item; })
            .forEach(function (element) {
                element.addEventListener('change', defaultsHandler);
            });

        if (this.items) {
            this.items.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-action][data-job-id]');
                if (!button) {
                    return;
                }

                var action = String(button.getAttribute('data-action') || '');
                var jobId = String(button.getAttribute('data-job-id') || '');
                if (action === 'retry') {
                    if (self.store.retryJob(jobId)) {
                        self.setFeedback('Retry queued.', 'ok');
                        if (self.scheduler.isRunning()) {
                            self.scheduler.requestDispatch();
                        }
                    }
                    return;
                }

                if (action === 'remove') {
                    if (self.store.removeJob(jobId)) {
                        self.setFeedback('Job removed.', 'ok');
                    }
                }
            });
        }
    };

    JobListModalController.prototype.renderJobs = function (snapshot) {
        if (!this.items) {
            return;
        }

        var jobs = Array.isArray(snapshot.jobs) ? snapshot.jobs.slice() : [];
        jobs.sort(function (a, b) {
            return Number(a.createdAt || 0) - Number(b.createdAt || 0);
        });

        if (!jobs.length) {
            this.items.innerHTML = '<div class="joblist-empty">No queued jobs yet.</div>';
            return;
        }

        var html = jobs.map(function (job) {
            var status = String(job.status || 'pending');
            var outputFormat = String(job.outputFormat || 'mp3').toLowerCase() === 'mp4' ? 'MP4' : 'MP3';
            var slot = job.slotKey ? ('Slot ' + String(job.slotKey).toUpperCase()) : '-';
            var actions = '';
            if (status === 'failed') {
                actions = '<button type="button" class="joblist-mini" data-action="retry" data-job-id="' + job.id + '">Retry</button>'
                    + '<button type="button" class="joblist-mini is-danger" data-action="remove" data-job-id="' + job.id + '">Remove</button>';
            } else if (status === 'pending') {
                actions = '<button type="button" class="joblist-mini is-danger" data-action="remove" data-job-id="' + job.id + '">Remove</button>';
            }

            return '<div class="joblist-item status-' + status + '">'
                + '<div class="joblist-item-main">'
                + '<div class="joblist-item-url" title="' + this.escapeHtml(job.url) + '">' + this.escapeHtml(job.url) + '</div>'
                + '<div class="joblist-item-meta"><span class="joblist-badge joblist-badge-format">' + this.escapeHtml(outputFormat) + '</span><span class="joblist-badge">' + this.escapeHtml(status) + '</span><span>' + this.escapeHtml(slot) + '</span></div>'
                + '<div class="joblist-item-msg">' + this.escapeHtml(job.message || '') + '</div>'
                + '</div>'
                + '<div class="joblist-item-actions">' + actions + '</div>'
                + '</div>';
        }, this).join('');

        this.items.innerHTML = html;
    };

    JobListModalController.prototype.render = function () {
        var snapshot = this.latestSnapshot;
        var schedulerState = this.schedulerState;

        this.writeDefaultsToUi(snapshot.defaults);

        var pending = 0;
        var running = 0;
        var failed = 0;

        snapshot.jobs.forEach(function (job) {
            if (job.status === 'pending') {
                pending += 1;
            } else if (job.status === 'failed') {
                failed += 1;
            } else {
                running += 1;
            }
        });

        if (this.statsPending) {
            this.statsPending.textContent = String(pending);
        }
        if (this.statsRunning) {
            this.statsRunning.textContent = String(running);
        }
        if (this.statsFailed) {
            this.statsFailed.textContent = String(failed);
        }
        if (this.statsCompleted) {
            this.statsCompleted.textContent = String(snapshot.completedCount || 0);
        }

        if (this.startButton) {
            var outputLabel = snapshot.defaults.format === 'mp4' ? 'MP4' : 'MP3';
            this.startButton.textContent = 'Start auto (' + outputLabel + ')';
            this.startButton.disabled = schedulerState.autoRunning || (pending <= 0 && running <= 0);
        }
        if (this.stopButton) {
            this.stopButton.disabled = !schedulerState.autoRunning;
        }

        this.renderJobs(snapshot);
    };

    JobListModalController.prototype.handleEscape = function () {
        if (!this.isOpen()) {
            return false;
        }
        this.close();
        return true;
    };

    JobListModalController.prototype.init = function () {
        var self = this;

        this.bindEvents();
        this.unsubscribeStore = this.store.subscribe(function (snapshot) {
            self.latestSnapshot = snapshot;
            self.render();
        });
        this.unsubscribeScheduler = this.scheduler.subscribe(function (state) {
            self.schedulerState = state;
            self.render();
        });

        this.render();
        this.setFeedback('Ready. Paste URLs and click Add jobs.', '');
    };

    root.JobListModalController = JobListModalController;
})(window);
