(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function JobListStore(options) {
        options = options || {};
        this.storage = options.storage;
        this.urlUtils = options.urlUtils;
        this.keys = {
            jobs: String(options.jobsKey || 'video_tool_job_list_jobs'),
            defaults: String(options.defaultsKey || 'video_tool_job_list_defaults'),
            meta: String(options.metaKey || 'video_tool_job_list_meta')
        };
        this.listeners = [];
        this.jobs = [];
        this.completedCount = 0;
        this.defaults = {
            format: 'mp3',
            mp3Bitrate: 320,
            mp3EncodingSpeed: '2',
            mp4Height: 1080,
            mp4BitrateMode: 'average'
        };
        this.mp3Bitrates = [96, 128, 160, 192, 256, 320];
    }

    JobListStore.prototype.generateJobId = function () {
        return 'joblist_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
    };

    JobListStore.prototype.readJson = function (key, fallback) {
        var raw = this.storage.readLocal(key, '');
        if (raw === '') {
            return fallback;
        }
        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (error) {
            return fallback;
        }
    };

    JobListStore.prototype.writeJson = function (key, value) {
        try {
            this.storage.writeLocal(key, JSON.stringify(value));
        } catch (error) {
        }
    };

    JobListStore.prototype.normalizeMp3Bitrate = function (value) {
        var parsed = Math.max(0, Number.parseInt(String(value || 0), 10) || 0);
        if (parsed <= 0) {
            return 320;
        }
        var nearest = 320;
        var diff = Number.MAX_SAFE_INTEGER;
        for (var i = 0; i < this.mp3Bitrates.length; i += 1) {
            var candidate = this.mp3Bitrates[i];
            var candidateDiff = Math.abs(candidate - parsed);
            if (candidateDiff < diff) {
                diff = candidateDiff;
                nearest = candidate;
            }
        }
        return nearest;
    };

    JobListStore.prototype.normalizeDefaults = function (value) {
        var raw = value && typeof value === 'object' ? value : {};
        var format = String(raw.format || this.defaults.format).toLowerCase() === 'mp4' ? 'mp4' : 'mp3';
        var speed = String(raw.mp3EncodingSpeed || this.defaults.mp3EncodingSpeed);
        if (!['0', '2', '9'].includes(speed)) {
            speed = '2';
        }
        var mode = String(raw.mp4BitrateMode || this.defaults.mp4BitrateMode).toLowerCase();
        if (!['average', 'highest'].includes(mode)) {
            mode = 'average';
        }

        return {
            format: format,
            mp3Bitrate: this.normalizeMp3Bitrate(raw.mp3Bitrate),
            mp3EncodingSpeed: speed,
            mp4Height: Math.max(240, Number.parseInt(String(raw.mp4Height || this.defaults.mp4Height), 10) || 1080),
            mp4BitrateMode: mode
        };
    };

    JobListStore.prototype.normalizeJob = function (job) {
        if (!job || typeof job !== 'object') {
            return null;
        }
        var url = String(job.url || '').trim();
        if (!this.urlUtils.isHttpUrl(url)) {
            return null;
        }
        var normalizedUrl = this.urlUtils.normalizeUrl(url);
        var status = String(job.status || 'pending').toLowerCase();
        if (!['pending', 'assigned', 'scanning', 'running', 'failed'].includes(status)) {
            status = 'pending';
        }
        var outputFormat = String(job.outputFormat || this.defaults.format || 'mp3').toLowerCase() === 'mp4' ? 'mp4' : 'mp3';

        return {
            id: String(job.id || this.generateJobId()),
            url: url,
            normalizedUrl: normalizedUrl,
            outputFormat: outputFormat,
            status: status,
            slotKey: String(job.slotKey || ''),
            message: String(job.message || ''),
            attempts: Math.max(0, Number.parseInt(String(job.attempts || 0), 10) || 0),
            createdAt: Number.parseInt(String(job.createdAt || Date.now()), 10) || Date.now(),
            updatedAt: Number.parseInt(String(job.updatedAt || Date.now()), 10) || Date.now(),
            lastError: String(job.lastError || '')
        };
    };

    JobListStore.prototype.persist = function () {
        this.writeJson(this.keys.jobs, this.jobs);
        this.writeJson(this.keys.defaults, this.defaults);
        this.writeJson(this.keys.meta, { completedCount: this.completedCount });
    };

    JobListStore.prototype.emit = function () {
        var snapshot = this.getSnapshot();
        this.listeners.forEach(function (listener) {
            try {
                listener(snapshot);
            } catch (error) {
            }
        });
    };

    JobListStore.prototype.subscribe = function (listener) {
        if (typeof listener !== 'function') {
            return function () {};
        }
        this.listeners.push(listener);
        listener(this.getSnapshot());

        var self = this;
        return function () {
            self.listeners = self.listeners.filter(function (item) {
                return item !== listener;
            });
        };
    };

    JobListStore.prototype.load = function () {
        this.defaults = this.normalizeDefaults(this.readJson(this.keys.defaults, this.defaults));

        var rawJobs = this.readJson(this.keys.jobs, []);
        if (!Array.isArray(rawJobs)) {
            rawJobs = [];
        }
        this.jobs = rawJobs.map(this.normalizeJob.bind(this)).filter(function (item) {
            return item !== null;
        });

        var meta = this.readJson(this.keys.meta, {});
        this.completedCount = Math.max(0, Number.parseInt(String(meta.completedCount || 0), 10) || 0);

        this.persist();
        this.emit();
    };

    JobListStore.prototype.getSnapshot = function () {
        var jobs = this.jobs.map(function (item) {
            return {
                id: item.id,
                url: item.url,
                normalizedUrl: item.normalizedUrl,
                outputFormat: item.outputFormat,
                status: item.status,
                slotKey: item.slotKey,
                message: item.message,
                attempts: item.attempts,
                createdAt: item.createdAt,
                updatedAt: item.updatedAt,
                lastError: item.lastError
            };
        });

        return {
            jobs: jobs,
            defaults: {
                format: this.defaults.format,
                mp3Bitrate: this.defaults.mp3Bitrate,
                mp3EncodingSpeed: this.defaults.mp3EncodingSpeed,
                mp4Height: this.defaults.mp4Height,
                mp4BitrateMode: this.defaults.mp4BitrateMode
            },
            completedCount: this.completedCount
        };
    };

    JobListStore.prototype.getDefaults = function () {
        return this.getSnapshot().defaults;
    };

    JobListStore.prototype.setDefaults = function (value) {
        this.defaults = this.normalizeDefaults(Object.assign({}, this.defaults, value || {}));
        this.persist();
        this.emit();
        return this.defaults;
    };

    JobListStore.prototype.hasPendingJobs = function () {
        return this.jobs.some(function (item) {
            return item.status === 'pending';
        });
    };

    JobListStore.prototype.findById = function (jobId) {
        var id = String(jobId || '');
        return this.jobs.find(function (item) {
            return item.id === id;
        }) || null;
    };

    JobListStore.prototype.enqueueFromText = function (rawText) {
        var text = String(rawText || '');
        var lines = text.split(/\r?\n/);
        var added = 0;
        var invalid = 0;
        var duplicates = 0;

        var known = {};
        this.jobs.forEach(function (item) {
            known[item.normalizedUrl] = true;
        });

        for (var i = 0; i < lines.length; i += 1) {
            var line = String(lines[i] || '').trim();
            if (line === '') {
                continue;
            }
            if (!this.urlUtils.isHttpUrl(line)) {
                invalid += 1;
                continue;
            }

            var normalized = this.urlUtils.normalizeUrl(line);
            if (normalized === '' || known[normalized]) {
                duplicates += 1;
                continue;
            }

            known[normalized] = true;
            this.jobs.push({
                id: this.generateJobId(),
                url: line,
                normalizedUrl: normalized,
                outputFormat: this.defaults.format === 'mp4' ? 'mp4' : 'mp3',
                status: 'pending',
                slotKey: '',
                message: 'Waiting for a free slot.',
                attempts: 0,
                createdAt: Date.now(),
                updatedAt: Date.now(),
                lastError: ''
            });
            added += 1;
        }

        if (added > 0) {
            this.persist();
            this.emit();
        }

        return {
            added: added,
            invalid: invalid,
            duplicates: duplicates
        };
    };

    JobListStore.prototype.assignNextJob = function (slotKey) {
        var key = String(slotKey || '').trim();
        if (key === '') {
            return null;
        }

        var next = this.jobs.find(function (item) {
            return item.status === 'pending';
        });
        if (!next) {
            return null;
        }

        next.status = 'assigned';
        next.slotKey = key;
        next.outputFormat = String(next.outputFormat || this.defaults.format || 'mp3').toLowerCase() === 'mp4' ? 'mp4' : 'mp3';
        next.message = 'Assigned to ' + key.toUpperCase() + '.';
        next.updatedAt = Date.now();
        this.persist();
        this.emit();
        return Object.assign({}, next);
    };

    JobListStore.prototype.setJobOutputFormat = function (jobId, outputFormat) {
        var job = this.findById(jobId);
        if (!job) {
            return;
        }
        job.outputFormat = String(outputFormat || 'mp3').toLowerCase() === 'mp4' ? 'mp4' : 'mp3';
        job.updatedAt = Date.now();
        this.persist();
        this.emit();
    };

    JobListStore.prototype.setJobStage = function (jobId, stage, message, slotKey) {
        var job = this.findById(jobId);
        if (!job) {
            return;
        }

        var nextStage = String(stage || '').toLowerCase();
        if (!['pending', 'assigned', 'scanning', 'running', 'failed'].includes(nextStage)) {
            nextStage = job.status;
        }

        job.status = nextStage;
        if (slotKey) {
            job.slotKey = String(slotKey);
        }
        job.message = String(message || job.message || '');
        job.updatedAt = Date.now();

        this.persist();
        this.emit();
    };

    JobListStore.prototype.markFailed = function (jobId, message, slotKey) {
        var job = this.findById(jobId);
        if (!job) {
            return;
        }

        job.status = 'failed';
        job.slotKey = slotKey ? String(slotKey) : '';
        job.message = String(message || 'Download failed.');
        job.lastError = job.message;
        job.attempts = Math.max(0, job.attempts) + 1;
        job.updatedAt = Date.now();

        this.persist();
        this.emit();
    };

    JobListStore.prototype.markDoneAndRemove = function (jobId) {
        var id = String(jobId || '');
        var before = this.jobs.length;
        this.jobs = this.jobs.filter(function (item) {
            return item.id !== id;
        });

        if (this.jobs.length !== before) {
            this.completedCount += 1;
            this.persist();
            this.emit();
        }
    };

    JobListStore.prototype.retryJob = function (jobId) {
        var job = this.findById(jobId);
        if (!job || job.status !== 'failed') {
            return false;
        }

        job.status = 'pending';
        job.slotKey = '';
        job.message = 'Retry queued.';
        job.updatedAt = Date.now();

        this.persist();
        this.emit();
        return true;
    };

    JobListStore.prototype.removeJob = function (jobId) {
        var id = String(jobId || '');
        var before = this.jobs.length;
        this.jobs = this.jobs.filter(function (item) {
            return item.id !== id;
        });
        if (this.jobs.length !== before) {
            this.persist();
            this.emit();
            return true;
        }
        return false;
    };

    JobListStore.prototype.clearFailed = function () {
        var before = this.jobs.length;
        this.jobs = this.jobs.filter(function (item) {
            return item.status !== 'failed';
        });
        if (this.jobs.length !== before) {
            this.persist();
            this.emit();
        }
    };

    root.JobListStore = JobListStore;
})(window);
