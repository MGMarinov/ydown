(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function AppController(options) {
        options = options || {};
        this.config = options.config || root.config || {};
        this.storageKeys = this.config.storageKeys || {};

        this.storage = new root.StorageService();
        this.urlUtils = new root.UrlUtils();
        this.baseUtils = new root.BaseUtils();
        this.progressMapper = new root.ProgressMapper(this.config.constants || {});
        this.apiClient = new root.ApiClient({ basePath: global.location.pathname });

        this.debugMode = false;
        try {
            var params = new URLSearchParams(global.location.search || '');
            this.debugMode = params.get('debug') === '1' || this.storage.readLocal(this.storageKeys.debugMode || 'video_tool_debug_mode', '') === '1';
        } catch (error) {
            this.debugMode = this.storage.readLocal(this.storageKeys.debugMode || 'video_tool_debug_mode', '') === '1';
        }

        this.slots = [];
        this.modalController = null;
        this.themeController = null;
        this.historyService = null;
        this.jobListStore = null;
        this.jobScheduler = null;
        this.jobListModalController = null;
    }

    AppController.prototype.debugLog = function () {
        if (!this.debugMode || !global.console || typeof global.console.debug !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[YDOWN]');
        global.console.debug.apply(global.console, args);
    };

    AppController.prototype.createModalController = function () {
        var self = this;
        this.modalController = new root.ModalController({
            utils: this.baseUtils,
            getPrimaryFocus: function () {
                if (self.slots.length > 0 && self.slots[0].startButton) {
                    return self.slots[0].startButton;
                }
                return null;
            }
        });
    };

    AppController.prototype.createControllers = function () {
        this.createModalController();

        this.themeController = new root.ThemeController({
            storage: this.storage,
            storageKey: this.storageKeys.themeMode || 'video_tool_theme_mode'
        });

        this.historyService = new root.DownloadHistoryService({
            storage: this.storage,
            urlUtils: this.urlUtils,
            baseUtils: this.baseUtils,
            sessionKey: this.storageKeys.successfulDownloadsSession || 'video_tool_successful_downloads_session'
        });

        this.jobListStore = new root.JobListStore({
            storage: this.storage,
            urlUtils: this.urlUtils,
            jobsKey: this.storageKeys.jobListJobs || 'video_tool_job_list_jobs',
            defaultsKey: this.storageKeys.jobListDefaults || 'video_tool_job_list_defaults',
            metaKey: this.storageKeys.jobListMeta || 'video_tool_job_list_meta'
        });
    };

    AppController.prototype.createSlots = function () {
        var self = this;
        var specs = Array.isArray(this.config.slotSpecs) ? this.config.slotSpecs : [];

        this.slots = specs.map(function (spec) {
            return new root.SlotController({
                spec: spec,
                config: self.config,
                storage: self.storage,
                apiClient: self.apiClient,
                modalController: self.modalController,
                historyService: self.historyService,
                progressMapper: self.progressMapper,
                urlUtils: self.urlUtils,
                baseUtils: self.baseUtils,
                debugMode: self.debugMode
            });
        }).filter(function (slot) {
            return slot && slot.isValid();
        });
    };

    AppController.prototype.createJobAutomation = function () {
        this.jobScheduler = new root.JobScheduler({
            slots: this.slots,
            store: this.jobListStore,
            debugMode: this.debugMode
        });

        this.jobListModalController = new root.JobListModalController({
            store: this.jobListStore,
            scheduler: this.jobScheduler,
            utils: this.baseUtils
        });
    };

    AppController.prototype.bindGlobalEvents = function () {
        var self = this;

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            if (self.modalController && self.modalController.handleEscape()) {
                return;
            }

            if (self.jobListModalController && self.jobListModalController.handleEscape()) {
                return;
            }

            if (self.themeController) {
                self.themeController.handleEscape();
            }
        });
    };

    AppController.prototype.init = function () {
        this.createControllers();
        this.createSlots();

        if (!this.slots.length) {
            return;
        }

        this.createJobAutomation();

        this.modalController.init();
        this.themeController.init();
        this.historyService.load();
        this.jobListStore.load();
        this.jobListModalController.init();

        this.slots.forEach(function (slot) {
            slot.bindEvents();
            slot.init();
        });

        this.bindGlobalEvents();
        this.debugLog('initialized', { slotCount: this.slots.length, debugMode: this.debugMode });
    };

    root.AppController = AppController;
})(window);
