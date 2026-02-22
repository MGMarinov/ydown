(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function DownloadHistoryService(options) {
        options = options || {};
        this.storage = options.storage;
        this.urlUtils = options.urlUtils;
        this.baseUtils = options.baseUtils;
        this.sessionKey = String(options.sessionKey || 'video_tool_successful_downloads_session');
        this.successfulDownloads = {};
    }

    DownloadHistoryService.prototype.load = function () {
        this.successfulDownloads = this.storage.readSessionJson(this.sessionKey, {});
    };

    DownloadHistoryService.prototype.save = function () {
        this.storage.writeSessionJson(this.sessionKey, this.successfulDownloads);
    };

    DownloadHistoryService.prototype.getEntry = function (url) {
        var key = this.urlUtils.normalizeUrl(url);
        if (key === '') {
            return null;
        }
        var entry = this.successfulDownloads[key];
        return entry && typeof entry === 'object' ? entry : null;
    };

    DownloadHistoryService.prototype.remember = function (url) {
        var key = this.urlUtils.normalizeUrl(url);
        if (key === '') {
            return;
        }

        var nowIso = new Date().toISOString();
        var existing = this.successfulDownloads[key] && typeof this.successfulDownloads[key] === 'object'
            ? this.successfulDownloads[key]
            : {};

        this.successfulDownloads[key] = {
            count: Math.max(0, Number(existing.count || 0)) + 1,
            firstSuccessAt: typeof existing.firstSuccessAt === 'string' && existing.firstSuccessAt !== ''
                ? existing.firstSuccessAt
                : nowIso,
            lastSuccessAt: nowIso
        };

        this.save();
    };

    DownloadHistoryService.prototype.buildDuplicateMessage = function (url) {
        var entry = this.getEntry(url);
        if (!entry) {
            return '';
        }

        var count = Math.max(1, Number(entry.count || 1));
        var lastSuccess = this.baseUtils.formatTimestamp(entry.lastSuccessAt || '');
        if (count > 1) {
            return 'Are you sure you want to download this file again? You already downloaded this URL successfully ' + count + ' times in this session. Last successful download: ' + lastSuccess + '.';
        }

        return 'Are you sure you want to download this file again? You already downloaded this URL successfully in this session at ' + lastSuccess + '.';
    };

    DownloadHistoryService.prototype.buildDuplicateBlockedMessage = function (url) {
        var entry = this.getEntry(url);
        if (!entry) {
            return '';
        }

        var count = Math.max(1, Number(entry.count || 1));
        var lastSuccess = this.baseUtils.formatTimestamp(entry.lastSuccessAt || '');
        if (count > 1) {
            return 'This URL was already downloaded successfully ' + count + ' times in this session. Last successful download: ' + lastSuccess + '.';
        }

        return 'This URL was already downloaded successfully in this session at ' + lastSuccess + '.';
    };

    root.DownloadHistoryService = DownloadHistoryService;
})(window);
