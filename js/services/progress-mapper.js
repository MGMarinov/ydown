(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function ProgressMapper(constants) {
        this.constants = constants || {};
        this.slotHeaderTitleMax = Number(this.constants.slotHeaderTitleMax || 50);
    }

    ProgressMapper.prototype.normalizeDurationSeconds = function (value) {
        var parsed = Number.parseInt(String(value), 10);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            return 0;
        }
        return parsed;
    };

    ProgressMapper.prototype.formatDurationHhMmSs = function (totalSeconds) {
        var safeSeconds = Math.max(0, this.normalizeDurationSeconds(totalSeconds));
        var hours = Math.floor(safeSeconds / 3600);
        var minutes = Math.floor((safeSeconds % 3600) / 60);
        var seconds = safeSeconds % 60;
        return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
    };

    ProgressMapper.prototype.truncateTitle = function (title, maxLength) {
        var cleaned = String(title || '').trim().replace(/\s+/g, ' ');
        if (cleaned === '') {
            return '';
        }
        var safeLimit = Math.max(4, Number(maxLength) || this.slotHeaderTitleMax);
        if (cleaned.length <= safeLimit) {
            return cleaned;
        }
        return cleaned.slice(0, safeLimit - 3).trimEnd() + '...';
    };

    ProgressMapper.prototype.deriveScanMetaFromOptions = function (options) {
        var list = Array.isArray(options) ? options : [];
        var title = '';
        var durationSeconds = 0;

        for (var i = 0; i < list.length; i += 1) {
            var option = list[i];
            if (!option || typeof option !== 'object') {
                continue;
            }
            if (title === '') {
                var candidate = String(option.titel || '').trim();
                if (candidate !== '') {
                    title = candidate;
                }
            }
            if (durationSeconds <= 0) {
                durationSeconds = this.normalizeDurationSeconds(option.duration_seconds);
            }
        }

        return {
            title: title,
            durationSeconds: durationSeconds
        };
    };

    ProgressMapper.prototype.stripNumericProgressNoise = function (text) {
        var normalized = String(text || '').replace(/\s+/g, ' ').trim();
        normalized = normalized.replace(/(?:\s*-\s*)?Speed\s+[^\s]+/ig, '');
        normalized = normalized.replace(/\b\d+(?:\.\d+)?%\b/g, '');
        normalized = normalized.replace(/\s{2,}/g, ' ').trim();
        return normalized;
    };

    ProgressMapper.prototype.normalizeProgressMessage = function (text, status) {
        var cleaned = this.stripNumericProgressNoise(text);
        var lower = cleaned.toLowerCase();

        if (status === 'done') {
            return 'Download completed.';
        }
        if (status === 'error') {
            return cleaned !== '' ? cleaned : 'Download failed.';
        }

        if (lower === 'ready.' || lower === 'ready') {
            return 'Ready.';
        }
        if (lower.startsWith('url updated.')) {
            return 'URL updated. Press Enter to scan available qualities.';
        }
        if (lower.startsWith('url cleared.')) {
            return 'URL cleared.';
        }
        if (lower.startsWith('download canceled.')) {
            return 'Download canceled.';
        }
        if (lower.includes('ssl certificate could not be verified')) {
            return 'Quality list ready (SSL fallback mode).';
        }
        if (lower.includes('scan completed') || lower.includes('quality list ready')) {
            return 'Quality list ready.';
        }
        if (lower.includes('scanning url') || lower.includes('analyzing url') || lower.includes('analyzing source')) {
            return 'Analyzing source...';
        }
        if (
            lower.includes('preparing download job')
            || lower.includes('validating')
            || lower.includes('starting download job')
            || lower.includes('download will start shortly')
            || lower.includes('waiting for status data')
            || lower.includes('connecting to status endpoint')
        ) {
            return 'Preparing download...';
        }
        if (lower.includes('preparing youtube format') || lower.includes('starting youtube processing') || lower.includes('preparing source')) {
            return 'Preparing media...';
        }
        if (lower.includes('downloading')) {
            return 'Downloading media...';
        }
        if (lower.includes('merging video and audio') || lower.includes('post-processing') || lower.includes('finalizing youtube file')) {
            return 'Merging audio and video...';
        }
        if (lower.includes('decoding audio') || lower.includes('converting to mp3') || lower.includes('mp3 conversion') || lower.includes('audio source downloaded')) {
            return 'Decoding audio...';
        }
        if (lower.includes('sending file to browser') || lower.includes('saving file')) {
            return 'Saving file...';
        }

        return cleaned !== '' ? cleaned : 'Preparing download...';
    };

    ProgressMapper.prototype.resolveVisualProgress = function (text, status, fallbackPercent) {
        if (status === 'done' || status === 'error') {
            return 100;
        }

        var lower = String(text || '').toLowerCase();
        var fallback = Math.max(0, Math.min(100, Number(fallbackPercent) || 0));

        if (lower === 'ready.' || lower.startsWith('url updated.') || lower.startsWith('url cleared.') || lower.startsWith('download canceled.')) {
            return 0;
        }
        if (lower.includes('analyzing source')) {
            return 3;
        }
        if (lower.includes('quality list ready')) {
            return 5;
        }
        if (lower.includes('preparing download')) {
            return 6;
        }
        if (lower.includes('preparing media')) {
            return 8;
        }
        if (lower.includes('downloading media')) {
            if (fallback > 0) {
                return Math.max(8, Math.min(92, fallback));
            }
            return 12;
        }
        if (lower.includes('merging audio and video')) {
            return 82;
        }
        if (lower.includes('decoding audio')) {
            if (fallback > 0) {
                return Math.max(25, Math.min(98, fallback));
            }
            return 35;
        }
        if (lower.includes('saving file')) {
            if (fallback > 0) {
                return Math.max(97, Math.min(99, fallback));
            }
            return 97;
        }

        return Math.max(0, Math.min(95, fallback));
    };

    root.ProgressMapper = ProgressMapper;
})(window);
