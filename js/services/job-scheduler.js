(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function JobScheduler(options) {
        options = options || {};
        this.slots = Array.isArray(options.slots) ? options.slots : [];
        this.store = options.store;
        this.debugMode = options.debugMode === true;
        this.listeners = [];

        this.autoRunning = false;
        this.dispatchScheduled = false;
        this.activeBySlot = {};
    }

    JobScheduler.prototype.debugLog = function () {
        if (!this.debugMode || !global.console || typeof global.console.debug !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[YDOWN][Scheduler]');
        global.console.debug.apply(global.console, args);
    };

    JobScheduler.prototype.subscribe = function (listener) {
        if (typeof listener !== 'function') {
            return function () {};
        }
        this.listeners.push(listener);
        listener(this.getState());

        var self = this;
        return function () {
            self.listeners = self.listeners.filter(function (item) {
                return item !== listener;
            });
        };
    };

    JobScheduler.prototype.emit = function () {
        var snapshot = this.getState();
        this.listeners.forEach(function (listener) {
            try {
                listener(snapshot);
            } catch (error) {
            }
        });
    };

    JobScheduler.prototype.getState = function () {
        return {
            autoRunning: this.autoRunning,
            activeSlots: Object.keys(this.activeBySlot).length,
            activeBySlot: Object.assign({}, this.activeBySlot)
        };
    };

    JobScheduler.prototype.isRunning = function () {
        return this.autoRunning;
    };

    JobScheduler.prototype.start = function () {
        if (this.autoRunning) {
            return;
        }
        this.autoRunning = true;
        this.slots.forEach(function (slot) {
            slot.setManualInteractionLocked(true);
        });
        this.emit();
        this.requestDispatch();
    };

    JobScheduler.prototype.stop = function () {
        if (!this.autoRunning) {
            return;
        }
        this.autoRunning = false;
        this.slots.forEach(function (slot) {
            slot.setManualInteractionLocked(!slot.isIdle());
        });
        this.emit();
    };

    JobScheduler.prototype.requestDispatch = function () {
        var self = this;
        if (this.dispatchScheduled) {
            return;
        }
        this.dispatchScheduled = true;
        global.setTimeout(function () {
            self.dispatchScheduled = false;
            self.dispatch();
        }, 0);
    };

    JobScheduler.prototype.dispatch = function () {
        var self = this;
        if (!this.autoRunning) {
            return;
        }

        this.slots.forEach(function (slot) {
            if (!self.autoRunning) {
                return;
            }
            if (!slot || typeof slot.isIdle !== 'function' || !slot.isIdle()) {
                return;
            }
            if (self.activeBySlot[slot.key]) {
                return;
            }

            var job = self.store.assignNextJob(slot.key);
            if (!job) {
                return;
            }

            self.activeBySlot[slot.key] = job.id;
            self.emit();
            self.debugLog('dispatch', { slot: slot.key, jobId: job.id });

            var defaults = self.store.getDefaults();
            var requestedFormat = defaults && defaults.format === 'mp4' ? 'mp4' : 'mp3';
            if (typeof self.store.setJobOutputFormat === 'function') {
                self.store.setJobOutputFormat(job.id, requestedFormat);
            }
            slot.runQueuedJob(job, defaults, {
                onStage: function (stage, message) {
                    self.store.setJobStage(job.id, stage, message, slot.key);
                }
            }).then(function () {
                delete self.activeBySlot[slot.key];
                self.store.markDoneAndRemove(job.id);
                if (!self.autoRunning) {
                    slot.setManualInteractionLocked(false);
                }
                self.emit();
                self.requestDispatch();
            }).catch(function (error) {
                delete self.activeBySlot[slot.key];
                var message = error && error.message ? error.message : 'Queued download failed.';
                self.store.markFailed(job.id, message, slot.key);
                if (!self.autoRunning) {
                    slot.setManualInteractionLocked(false);
                }
                self.emit();
                self.requestDispatch();
            });
        });
    };

    root.JobScheduler = JobScheduler;
})(window);
