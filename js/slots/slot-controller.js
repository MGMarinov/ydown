(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function SlotController(options) {
        options = options || {};
        this.spec = options.spec || {};
        this.config = options.config || {};
        this.constants = this.config.constants || {};
        this.initialOptions = Array.isArray(this.config.initialOptions) ? this.config.initialOptions : [];
        this.ffmpegAvailable = this.config.ffmpegAvailable === true;
        this.storage = options.storage;
        this.apiClient = options.apiClient;
        this.modalController = options.modalController;
        this.historyService = options.historyService;
        this.progressMapper = options.progressMapper;
        this.urlUtils = options.urlUtils;
        this.baseUtils = options.baseUtils;
        this.debugMode = options.debugMode === true;

        this.MP3_FIXED_BITRATES = Array.isArray(this.constants.mp3FixedBitrates) ? this.constants.mp3FixedBitrates : [96, 128, 160, 192, 256, 320];
        this.DEFAULT_TARGET_FORMAT = String(this.constants.defaultTargetFormat || 'mp3');
        this.DEFAULT_MP3_BITRATE = Number(this.constants.defaultMp3Bitrate || 320);
        this.DEFAULT_ENCODING_SPEED = String(this.constants.defaultEncodingSpeed || '2');
        this.SLOT_HEADER_TITLE_MAX = Number(this.constants.slotHeaderTitleMax || 50);

        this.key = this.spec.key || '';
        this.label = this.spec.label || '';
        this.defaultHeaderText = this.spec.defaultHeaderText || 'Download Slot';

        this.input = document.getElementById(this.spec.inputId);
        this.clearButton = document.getElementById(this.spec.clearButtonId);
        this.startButton = document.getElementById(this.spec.startButtonId);
        this.formatSelect = document.getElementById(this.spec.formatSelectId);
        this.qualitySelect = document.getElementById(this.spec.qualitySelectId);
        this.qualityLabelElement = document.getElementById(this.spec.qualityLabelId);
        this.downloadForm = document.getElementById(this.spec.formId);
        this.downloadFrame = document.getElementById(this.spec.downloadFrameId);
        this.actionInput = document.getElementById(this.spec.actionInputId);
        this.urlInput = document.getElementById(this.spec.urlInputId);
        this.optionPayloadInput = document.getElementById(this.spec.optionPayloadInputId);
        this.jobIdInput = document.getElementById(this.spec.jobIdInputId);
        this.prefHeightInput = document.getElementById(this.spec.prefHeightInputId);
        this.prefBitrateInput = document.getElementById(this.spec.prefBitrateInputId);
        this.targetFormatInput = document.getElementById(this.spec.targetFormatInputId);
        this.encodingSpeedSelect = document.getElementById(this.spec.encodingSpeedSelectId);
        this.compressionLevelInput = document.getElementById(this.spec.compressionLevelInputId);
        this.titleElement = document.getElementById(this.spec.titleId);
        this.durationElement = document.getElementById(this.spec.durationId);
        this.outputSummaryElement = document.getElementById(this.spec.outputSummaryId);
        this.progressRow = document.getElementById(this.spec.progressRowId);
        this.progressFill = document.getElementById(this.spec.progressFillId);
        this.progressText = document.getElementById(this.spec.progressTextId);
        this.progressPercent = document.getElementById(this.spec.progressPercentId);
        this.slotGridElement = this.qualitySelect ? this.qualitySelect.closest('.slot-grid') : null;

        this.options = this.initialOptions.slice();
        this.scanToken = 0;
        this.scanAbortController = null;
        this.statusTimer = null;
        this.currentJobId = '';
        this.pseudoPercent = 0;
        this.statusCallback = null;
        this.running = false;
        this.scanning = false;
        this.manualInteractionLocked = false;
        this.lastScanUrl = '';
        this.lastScanErrorMessage = '';
        this.lastServerSignature = '';
        this.lastServerUpdateAt = 0;
        this.resetTimer = null;
    }

    SlotController.prototype.isValid = function () {
        return !!(
            this.input
            && this.clearButton
            && this.startButton
            && this.formatSelect
            && this.qualitySelect
            && this.downloadForm
            && this.actionInput
            && this.urlInput
            && this.optionPayloadInput
            && this.jobIdInput
            && this.prefHeightInput
            && this.prefBitrateInput
            && this.targetFormatInput
            && this.encodingSpeedSelect
            && this.compressionLevelInput
            && this.titleElement
            && this.durationElement
            && this.progressRow
            && this.progressFill
            && this.progressText
            && this.progressPercent
        );
    };

    SlotController.prototype.debugLog = function () {
        if (!this.debugMode || !global.console || typeof global.console.debug !== 'function') {
            return;
        }
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[YDOWN][' + this.key + ']');
        global.console.debug.apply(global.console, args);
    };

    SlotController.prototype.slotStorageKey = function (suffix) {
        return 'video_tool_' + this.key + '_' + suffix;
    };

    SlotController.prototype.qualityStorageKey = function (format) {
        return this.slotStorageKey(format === 'mp3' ? 'quality_mp3' : 'quality_mp4');
    };

    SlotController.prototype.getSlotFormat = function () {
        return this.formatSelect.value === 'mp3' ? 'mp3' : 'mp4';
    };

    SlotController.prototype.getFormatDisplayName = function (format) {
        return format === 'mp3' ? 'MP3' : 'MP4';
    };

    SlotController.prototype.getReadyStartLabel = function () {
        return 'Download ' + this.getFormatDisplayName(this.getSlotFormat());
    };

    SlotController.prototype.getProcessingStartLabel = function () {
        return 'Processing ' + this.getFormatDisplayName(this.getSlotFormat()) + '...';
    };

    SlotController.prototype.updateOutputSummary = function () {
        if (!this.outputSummaryElement) {
            return;
        }

        var format = this.getSlotFormat();
        if (format === 'mp3') {
            var bitrate = this.parseMp3OptionBitrate(this.qualitySelect.value);
            if (bitrate <= 0) {
                bitrate = this.normalizeMp3Bitrate(
                    this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp3'), this.DEFAULT_MP3_BITRATE)
                );
            }
            var speedLabel = 'High quality (balanced)';
            if (this.encodingSpeedSelect && this.encodingSpeedSelect.selectedOptions && this.encodingSpeedSelect.selectedOptions.length > 0) {
                speedLabel = String(this.encodingSpeedSelect.selectedOptions[0].textContent || speedLabel).trim();
            }
            this.outputSummaryElement.textContent = 'Final file: MP3 | ' + String(bitrate) + ' kbps | ' + speedLabel;
            return;
        }

        if (!Array.isArray(this.options) || this.options.length === 0) {
            this.outputSummaryElement.textContent = 'Final file: MP4 | Scan URL to load video qualities';
            return;
        }

        var selectedId = String(this.qualitySelect.value || '');
        var option = selectedId !== '' ? this.findOptionById(selectedId) : null;
        if (!option) {
            option = this.filterOptionsByFormat(this.options, 'mp4')[0] || null;
        }

        var resolution = 'Best available';
        var bitrateText = '';
        if (option) {
            var height = Math.max(0, Number.parseInt(String(option.hoehe || 0), 10) || 0);
            var bitrateKbps = Math.max(0, Number.parseInt(String(option.bitrate_kbps || 0), 10) || 0);
            if (height > 0) {
                resolution = String(height) + 'p';
            }
            if (bitrateKbps > 0) {
                bitrateText = String(bitrateKbps) + ' kbps';
            }
        }

        var mp4Summary = 'Final file: MP4 | ' + resolution;
        if (bitrateText !== '') {
            mp4Summary += ' | ' + bitrateText;
        }
        this.outputSummaryElement.textContent = mp4Summary;
    };

    SlotController.prototype.setOutputFormat = function (format, options) {
        var cfg = options && typeof options === 'object' ? options : {};
        var requested = String(format || '').toLowerCase() === 'mp4' ? 'mp4' : 'mp3';
        var target = requested;
        if (target === 'mp3' && this.ffmpegAvailable !== true) {
            target = 'mp4';
        }
        if (cfg.onlyIfIdle === true && !this.isIdle()) {
            return false;
        }
        if (this.formatSelect.value === target) {
            this.updateEncodingSpeedVisibility();
            this.refreshStartButtonState();
            this.updateOutputSummary();
            return true;
        }

        this.formatSelect.value = target;
        this.storage.writeLocal(this.slotStorageKey('format'), target);
        this.updateQualityList();
        this.updateEncodingSpeedVisibility();
        this.refreshStartButtonState();
        this.updateOutputSummary();
        return true;
    };

    SlotController.prototype.updateEncodingSpeedVisibility = function () {
        var isMp3 = this.getSlotFormat() === 'mp3';
        this.encodingSpeedSelect.disabled = this.manualInteractionLocked || !isMp3;
        var col = this.encodingSpeedSelect.closest('.mp3-only-col');
        if (col) {
            col.hidden = !isMp3;
            col.style.opacity = isMp3 ? '' : '0.45';
        }
        if (this.slotGridElement) {
            this.slotGridElement.classList.toggle('is-mp4', !isMp3);
        }
        if (this.qualityLabelElement) {
            this.qualityLabelElement.textContent = isMp3 ? 'Preferred bitrate' : 'Preferred resolution/bitrate';
        }
        this.updateOutputSummary();
    };

    SlotController.prototype.resetHeader = function () {
        this.titleElement.textContent = this.defaultHeaderText;
        this.titleElement.removeAttribute('title');
        if (this.durationElement) {
            this.durationElement.textContent = '';
        }
    };

    SlotController.prototype.updateHeader = function (title, durationSeconds) {
        var cleanTitle = String(title || '').trim();
        if (cleanTitle === '') {
            this.resetHeader();
            return;
        }

        var shortTitle = this.progressMapper.truncateTitle(cleanTitle, this.SLOT_HEADER_TITLE_MAX);
        this.titleElement.textContent = 'Download - ' + shortTitle;
        this.titleElement.setAttribute('title', cleanTitle);
        if (this.durationElement) {
            var safeDuration = this.progressMapper.normalizeDurationSeconds(durationSeconds);
            this.durationElement.textContent = safeDuration > 0 ? this.progressMapper.formatDurationHhMmSs(safeDuration) : '';
        }
    };

    SlotController.prototype.refreshStartButtonState = function () {
        var currentUrl = this.input ? this.input.value.trim() : '';
        var scanReady = currentUrl !== ''
            && this.lastScanUrl === currentUrl
            && Array.isArray(this.options)
            && this.options.length > 0;

        if (this.running || this.scanning) {
            this.startButton.disabled = true;
            this.startButton.classList.add('is-processing');
            this.startButton.textContent = this.getProcessingStartLabel();
            return;
        }

        this.startButton.classList.remove('is-processing');

        if (this.manualInteractionLocked) {
            this.startButton.disabled = true;
            this.startButton.textContent = 'Auto mode';
            return;
        }

        if (currentUrl === '') {
            this.startButton.disabled = true;
            this.startButton.textContent = 'Enter URL';
            return;
        }

        if (!scanReady) {
            this.startButton.disabled = true;
            this.startButton.textContent = 'Scan URL';
            return;
        }

        this.startButton.disabled = false;
        this.startButton.textContent = this.getReadyStartLabel();
    };

    SlotController.prototype.setStartButtonState = function (processing) {
        if (processing) {
            this.startButton.disabled = true;
            this.startButton.classList.add('is-processing');
            this.startButton.textContent = this.getProcessingStartLabel();
            return;
        }
        this.refreshStartButtonState();
    };

    SlotController.prototype.isIdle = function () {
        return !this.running && !this.scanning && !this.currentJobId;
    };

    SlotController.prototype.setManualInteractionLocked = function (locked) {
        this.manualInteractionLocked = locked === true;

        if (this.manualInteractionLocked) {
            this.input.disabled = true;
            this.clearButton.disabled = true;
            this.formatSelect.disabled = true;
            this.qualitySelect.disabled = true;
            this.encodingSpeedSelect.disabled = true;
            this.refreshStartButtonState();
            this.updateOutputSummary();
            return;
        }

        this.input.disabled = false;
        this.clearButton.disabled = false;
        this.formatSelect.disabled = false;
        this.updateQualityList();
        this.updateEncodingSpeedVisibility();

        this.refreshStartButtonState();
        this.updateOutputSummary();
    };

    SlotController.prototype.setPreferenceFields = function (height, bitrate) {
        this.prefHeightInput.value = String(Math.max(0, Number(height) || 0));
        this.prefBitrateInput.value = String(Math.max(0, Number(bitrate) || 0));
    };

    SlotController.prototype.normalizeMp3Bitrate = function (rawBitrate) {
        var value = Math.max(0, Number.parseInt(String(rawBitrate || 0), 10) || 0);
        if (value <= 0) {
            return 192;
        }

        var nearest = 192;
        var diff = Number.MAX_SAFE_INTEGER;
        for (var i = 0; i < this.MP3_FIXED_BITRATES.length; i += 1) {
            var candidate = this.MP3_FIXED_BITRATES[i];
            var currentDiff = Math.abs(candidate - value);
            if (currentDiff < diff) {
                diff = currentDiff;
                nearest = candidate;
            }
        }
        return nearest;
    };

    SlotController.prototype.mp3OptionValue = function (bitrate) {
        return 'mp3_fixed_' + String(this.normalizeMp3Bitrate(bitrate));
    };

    SlotController.prototype.parseMp3OptionBitrate = function (optionValue) {
        var match = String(optionValue || '').match(/^mp3_fixed_(\d{2,3})$/);
        if (!match) {
            return 0;
        }
        return this.normalizeMp3Bitrate(Number.parseInt(match[1], 10));
    };

    SlotController.prototype.getStoredPreference = function (format) {
        if (format === 'mp3') {
            return {
                height: 0,
                bitrate: this.normalizeMp3Bitrate(this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp3'), 192))
            };
        }

        return {
            height: Math.max(0, this.storage.readLocalInt(this.slotStorageKey('pref_height_mp4'), 0)),
            bitrate: Math.max(0, this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp4'), 0))
        };
    };

    SlotController.prototype.findOptionById = function (optionId) {
        for (var i = 0; i < this.options.length; i += 1) {
            if (String(this.options[i].id) === String(optionId)) {
                return this.options[i];
            }
        }
        return null;
    };

    SlotController.prototype.hasParametersForFormat = function (format) {
        if (this.storage.readLocal(this.qualityStorageKey(format), '') !== '') {
            return true;
        }
        if (format === 'mp3') {
            return this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp3'), 0) > 0;
        }
        return this.storage.readLocalInt(this.slotStorageKey('pref_height_mp4'), 0) > 0
            || this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp4'), 0) > 0;
    };

    SlotController.prototype.setStatus = function (percent, text, status) {
        var normalizedStatus = status === 'error' || status === 'done' || status === 'success' ? status : 'running';
        var normalizedText = this.progressMapper.normalizeProgressMessage(text, normalizedStatus);
        var targetPercent = this.progressMapper.resolveVisualProgress(normalizedText, normalizedStatus, percent);

        if (normalizedStatus === 'done' || normalizedStatus === 'error') {
            this.pseudoPercent = 100;
        } else if (targetPercent === 0) {
            this.pseudoPercent = 0;
        } else {
            this.pseudoPercent = Math.max(this.pseudoPercent || 0, targetPercent);
        }

        var normalizedPercent = Math.max(0, Math.min(100, this.pseudoPercent || 0));

        this.progressFill.style.width = normalizedPercent + '%';
        this.progressText.textContent = normalizedText || 'Preparing download...';
        this.progressPercent.textContent = normalizedStatus === 'done' ? 'Done' : (normalizedStatus === 'error' ? 'Error' : '');

        this.progressRow.classList.remove('status-running', 'status-success', 'status-error', 'is-near-complete');
        this.progressRow.classList.add(
            normalizedStatus === 'error'
                ? 'status-error'
                : (normalizedStatus === 'done' || normalizedStatus === 'success' ? 'status-success' : 'status-running')
        );
        if (normalizedStatus === 'running' && normalizedPercent >= 85) {
            this.progressRow.classList.add('is-near-complete');
        }

        this.debugLog('setStatus', {
            inputPercent: percent,
            visualPercent: normalizedPercent,
            status: normalizedStatus,
            text: normalizedText
        });
    };

    SlotController.prototype.savePreferenceFromSelection = function (explicit) {
        var format = this.getSlotFormat();
        var optionId = this.qualitySelect.value;
        if (optionId !== '') {
            this.storage.writeLocal(this.qualityStorageKey(format), optionId);
        }

        if (format === 'mp3') {
            var fixedBitrate = this.parseMp3OptionBitrate(optionId);
            if (fixedBitrate > 0) {
                this.storage.writeLocal(this.slotStorageKey('pref_bitrate_mp3'), fixedBitrate);
                this.setPreferenceFields(0, fixedBitrate);
                if (explicit && !this.running) {
                    this.setStatus(0, 'Settings saved.', 'running');
                }
                return;
            }
        }

        var option = this.findOptionById(optionId);
        if (!option) {
            var fallback = this.getStoredPreference(format);
            this.setPreferenceFields(fallback.height, fallback.bitrate);
            if (explicit && !this.running) {
                if (this.hasParametersForFormat(format)) {
                    this.setStatus(0, 'Settings saved.', 'running');
                } else {
                    this.modalController.showAlert('No settings are available for this slot yet. Paste a URL first so the tool can auto-scan qualities.', 'Settings required');
                }
            }
            return;
        }

        var bitrate = Math.max(0, Number(option.bitrate_kbps || 0));
        if (format === 'mp3') {
            var mp3Bitrate = this.normalizeMp3Bitrate(
                bitrate > 0 ? bitrate : this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp3'), 192)
            );
            this.storage.writeLocal(this.slotStorageKey('pref_bitrate_mp3'), mp3Bitrate);
            this.setPreferenceFields(0, mp3Bitrate);
            if (explicit && !this.running) {
                this.setStatus(0, 'Settings saved.', 'running');
            }
            return;
        }

        var height = Math.max(0, Number(option.hoehe || 0));
        this.storage.writeLocal(this.slotStorageKey('pref_height_mp4'), height);
        this.storage.writeLocal(this.slotStorageKey('pref_bitrate_mp4'), bitrate);
        this.setPreferenceFields(height, bitrate);
        if (explicit && !this.running) {
            this.setStatus(0, 'Settings saved.', 'running');
        }
    };

    SlotController.prototype.isAudioOption = function (option) {
        return option.audio_vorhanden !== false;
    };

    SlotController.prototype.isVideoOption = function (option) {
        return option.video_vorhanden !== false;
    };

    SlotController.prototype.getOptionExt = function (option) {
        return String(option && option.ext ? option.ext : '').trim().toLowerCase();
    };

    SlotController.prototype.filterOptionsByFormat = function (options, format) {
        var self = this;
        return options.filter(function (option) {
            if (format === 'mp3') {
                return self.isAudioOption(option);
            }
            if (!self.isVideoOption(option)) {
                return false;
            }

            var sourceType = String(option.quelle_typ || '').trim().toLowerCase();
            var ext = self.getOptionExt(option);
            var hasAudio = self.isAudioOption(option);

            if (sourceType === 'youtube' && ext !== '' && ext !== 'mp4') {
                return false;
            }

            if (hasAudio) {
                return true;
            }

            return sourceType === 'youtube' && self.ffmpegAvailable;
        });
    };

    SlotController.prototype.selectBestMp3SourceOption = function (options, targetBitrate) {
        var self = this;
        var candidates = options.filter(function (option) {
            return self.isAudioOption(option);
        });
        if (candidates.length === 0) {
            return null;
        }

        var best = null;
        var bestDiff = Number.MAX_SAFE_INTEGER;
        var bestBitrate = -1;
        var target = this.normalizeMp3Bitrate(targetBitrate);

        candidates.forEach(function (option) {
            var bitrate = Math.max(0, Number.parseInt(String(option.bitrate_kbps || 0), 10) || 0);
            var diff = Math.abs(bitrate - target);
            if (best === null || diff < bestDiff || (diff === bestDiff && bitrate > bestBitrate)) {
                best = option;
                bestDiff = diff;
                bestBitrate = bitrate;
            }
        });

        return best;
    };

    SlotController.prototype.buildDownloadOptionPayload = function (option) {
        return {
            typ: String(option && option.typ ? option.typ : ''),
            quelle_typ: String(option && option.quelle_typ ? option.quelle_typ : ''),
            download_url: String(option && option.download_url ? option.download_url : ''),
            format_id: String(option && option.format_id ? option.format_id : ''),
            titel: String(option && option.titel ? option.titel : ''),
            bitrate_kbps: Math.max(0, Number.parseInt(String(option && option.bitrate_kbps ? option.bitrate_kbps : 0), 10) || 0),
            aufloesung: String(option && option.aufloesung ? option.aufloesung : ''),
            hoehe: Math.max(0, Number.parseInt(String(option && option.hoehe ? option.hoehe : 0), 10) || 0),
            ext: String(option && option.ext ? option.ext : ''),
            audio_vorhanden: option && option.audio_vorhanden !== false,
            video_vorhanden: option && option.video_vorhanden !== false
        };
    };

    SlotController.prototype.resolveSelectedOptionForStart = function (format) {
        var filtered = this.filterOptionsByFormat(this.options, format);
        if (filtered.length === 0) {
            return {
                option: null,
                prefHeight: 0,
                prefBitrate: 0,
                error: 'No analyzed options are available for this URL. Paste the URL and press Enter to scan first.'
            };
        }

        if (format === 'mp3') {
            var selectedBitrate = this.parseMp3OptionBitrate(this.qualitySelect.value);
            var targetBitrate = selectedBitrate > 0
                ? selectedBitrate
                : this.normalizeMp3Bitrate(this.storage.readLocalInt(this.slotStorageKey('pref_bitrate_mp3'), 192));
            var mp3Option = this.selectBestMp3SourceOption(filtered, targetBitrate);
            if (!mp3Option) {
                return {
                    option: null,
                    prefHeight: 0,
                    prefBitrate: targetBitrate,
                    error: 'No audio source is available for MP3 conversion in this slot.'
                };
            }

            return {
                option: mp3Option,
                prefHeight: 0,
                prefBitrate: targetBitrate,
                error: ''
            };
        }

        var selectedId = String(this.qualitySelect.value || '');
        var option = null;
        if (selectedId !== '') {
            option = filtered.find(function (item) {
                return String(item.id) === selectedId;
            }) || null;
        }
        if (!option) {
            option = filtered[0] || null;
        }
        if (!option) {
            return {
                option: null,
                prefHeight: 0,
                prefBitrate: 0,
                error: 'No video option is available for MP4 download in this slot.'
            };
        }

        return {
            option: option,
            prefHeight: Math.max(0, Number.parseInt(String(option.hoehe || 0), 10) || 0),
            prefBitrate: Math.max(0, Number.parseInt(String(option.bitrate_kbps || 0), 10) || 0),
            error: ''
        };
    };

    SlotController.prototype.selectDefaultMp4Option = function (options, targetHeight, bitrateMode) {
        var list = Array.isArray(options) ? options.slice() : [];
        if (!list.length) {
            return null;
        }

        var desiredHeight = Math.max(240, Number.parseInt(String(targetHeight || 1080), 10) || 1080);
        var mode = String(bitrateMode || 'average').toLowerCase();

        function readHeight(option) {
            return Math.max(0, Number.parseInt(String(option && option.hoehe ? option.hoehe : 0), 10) || 0);
        }
        function readBitrate(option) {
            return Math.max(0, Number.parseInt(String(option && option.bitrate_kbps ? option.bitrate_kbps : 0), 10) || 0);
        }

        var exact = list.filter(function (item) { return readHeight(item) === desiredHeight; });
        var candidates = exact;

        if (!candidates.length) {
            var below = list.filter(function (item) { return readHeight(item) > 0 && readHeight(item) < desiredHeight; });
            if (below.length) {
                var bestBelowHeight = below.reduce(function (acc, item) { return Math.max(acc, readHeight(item)); }, 0);
                candidates = below.filter(function (item) { return readHeight(item) === bestBelowHeight; });
            }
        }

        if (!candidates.length) {
            var above = list.filter(function (item) { return readHeight(item) > desiredHeight; });
            if (above.length) {
                var minAboveHeight = above.reduce(function (acc, item) {
                    var h = readHeight(item);
                    return acc === 0 ? h : Math.min(acc, h);
                }, 0);
                candidates = above.filter(function (item) { return readHeight(item) === minAboveHeight; });
            }
        }

        if (!candidates.length) {
            candidates = list.slice();
        }

        if (mode === 'highest') {
            return candidates.reduce(function (best, item) {
                if (!best) {
                    return item;
                }
                var bestBitrate = readBitrate(best);
                var currentBitrate = readBitrate(item);
                if (currentBitrate > bestBitrate) {
                    return item;
                }
                if (currentBitrate === bestBitrate && readHeight(item) > readHeight(best)) {
                    return item;
                }
                return best;
            }, null);
        }

        var withBitrate = candidates.filter(function (item) { return readBitrate(item) > 0; });
        if (!withBitrate.length) {
            return candidates[0] || null;
        }

        var avg = withBitrate.reduce(function (sum, item) { return sum + readBitrate(item); }, 0) / withBitrate.length;
        return withBitrate.reduce(function (best, item) {
            if (!best) {
                return item;
            }
            var bestDiff = Math.abs(readBitrate(best) - avg);
            var currentDiff = Math.abs(readBitrate(item) - avg);
            if (currentDiff < bestDiff) {
                return item;
            }
            if (currentDiff === bestDiff && readBitrate(item) > readBitrate(best)) {
                return item;
            }
            return best;
        }, null);
    };

    SlotController.prototype.applyAutoDefaults = function (defaults) {
        var merged = defaults && typeof defaults === 'object' ? defaults : {};
        var requestedFormat = String(merged.format || this.DEFAULT_TARGET_FORMAT).toLowerCase();
        var targetFormat = requestedFormat === 'mp3' && this.ffmpegAvailable ? 'mp3' : 'mp4';

        this.formatSelect.value = targetFormat;
        this.updateEncodingSpeedVisibility();
        this.updateQualityList();

        if (targetFormat === 'mp3') {
            var targetBitrate = this.normalizeMp3Bitrate(merged.mp3Bitrate || this.DEFAULT_MP3_BITRATE);
            var targetSpeed = String(merged.mp3EncodingSpeed || this.DEFAULT_ENCODING_SPEED);
            this.encodingSpeedSelect.value = ['0', '2', '9'].includes(targetSpeed) ? targetSpeed : this.DEFAULT_ENCODING_SPEED;
            this.qualitySelect.value = this.mp3OptionValue(targetBitrate);
            this.savePreferenceFromSelection(false);
            return;
        }

        var targetHeight = Math.max(240, Number.parseInt(String(merged.mp4Height || 1080), 10) || 1080);
        var targetBitrate = Math.max(0, Number.parseInt(String(merged.mp4Bitrate || 0), 10) || 0);
        this.setPreferenceFields(targetHeight, targetBitrate);
        this.storage.writeLocal(this.slotStorageKey('pref_height_mp4'), targetHeight);
        this.storage.writeLocal(this.slotStorageKey('pref_bitrate_mp4'), targetBitrate);
    };

    SlotController.prototype.applyAutoQualityAfterScan = function (defaults) {
        var merged = defaults && typeof defaults === 'object' ? defaults : {};
        var format = this.getSlotFormat();
        if (format === 'mp3') {
            var targetBitrate = this.normalizeMp3Bitrate(merged.mp3Bitrate || this.DEFAULT_MP3_BITRATE);
            this.qualitySelect.value = this.mp3OptionValue(targetBitrate);
            this.savePreferenceFromSelection(false);
            return;
        }

        var filtered = this.filterOptionsByFormat(this.options, 'mp4');
        if (!filtered.length) {
            return;
        }

        var picked = this.selectDefaultMp4Option(filtered, merged.mp4Height || 1080, merged.mp4BitrateMode || 'average');
        if (!picked) {
            return;
        }

        var targetId = String(picked.id || '');
        if (targetId === '') {
            return;
        }

        var values = Array.from(this.qualitySelect.options).map(function (option) {
            return String(option.value || '');
        });
        if (values.includes(targetId)) {
            this.qualitySelect.value = targetId;
            this.savePreferenceFromSelection(false);
        }
    };

    SlotController.prototype.runQueuedJob = function (job, defaults, hooks) {
        var self = this;
        var item = job && typeof job === 'object' ? job : {};
        var hooksSafe = hooks && typeof hooks === 'object' ? hooks : {};
        var url = String(item.url || '').trim();

        if (!this.urlUtils.isHttpUrl(url)) {
            return Promise.reject(new Error('Queued URL is not valid.'));
        }
        if (!this.isIdle()) {
            return Promise.reject(new Error('Slot is not idle.'));
        }
        var duplicateMessage = '';
        if (this.historyService && typeof this.historyService.buildDuplicateBlockedMessage === 'function') {
            duplicateMessage = this.historyService.buildDuplicateBlockedMessage(url);
        } else if (this.historyService && typeof this.historyService.buildDuplicateMessage === 'function') {
            duplicateMessage = this.historyService.buildDuplicateMessage(url);
        }
        if (duplicateMessage !== '') {
            return Promise.reject(new Error(duplicateMessage));
        }

        if (typeof hooksSafe.onStage === 'function') {
            hooksSafe.onStage('assigned', 'Assigned to slot.');
        }

        this.input.value = url;
        this.persistSlotUrl();
        this.lastScanUrl = '';
        this.options = [];
        this.resetHeader();
        this.updateQualityList();
        this.applyAutoDefaults(defaults);

        if (typeof hooksSafe.onStage === 'function') {
            hooksSafe.onStage('scanning', 'Scanning source...');
        }

        return this.runAutoScan(true).then(function () {
            if (self.lastScanUrl !== url || !Array.isArray(self.options) || self.options.length === 0) {
                var scanFailureMessage = String(self.lastScanErrorMessage || '').trim();
                if (scanFailureMessage !== '') {
                    throw new Error(scanFailureMessage);
                }
                throw new Error('No scan results available for queued URL.');
            }

            self.applyAutoDefaults(defaults);
            self.applyAutoQualityAfterScan(defaults);

            if (typeof hooksSafe.onStage === 'function') {
                hooksSafe.onStage('running', 'Starting queued download...');
            }

            return new Promise(function (resolve, reject) {
                void self.startDownload({
                    source: 'queue',
                    skipDuplicatePrompt: true,
                    onFinalized: function (result) {
                        var status = result && result.status ? String(result.status) : 'error';
                        var message = result && result.message ? String(result.message) : '';
                        if (status === 'done') {
                            resolve({ status: status, message: message });
                            return;
                        }
                        reject(new Error(message !== '' ? message : 'Queued download failed.'));
                    }
                });
            });
        });
    };

    SlotController.prototype.buildOptionLabel = function (option, format) {
        return format === 'mp3' ? String(option.label_mp3 || 'MP3') : String(option.label_mp4 || 'MP4');
    };

    SlotController.prototype.updateQualityList = function () {
        var self = this;
        var format = this.getSlotFormat();
        var previousValue = this.qualitySelect.value;
        this.updateEncodingSpeedVisibility();

        this.qualitySelect.innerHTML = '';

        if (format === 'mp3') {
            this.qualitySelect.disabled = false;
            this.MP3_FIXED_BITRATES.forEach(function (bitrate) {
                var item = document.createElement('option');
                item.value = self.mp3OptionValue(bitrate);
                item.textContent = 'MP3 - ' + String(bitrate) + ' kbps';
                self.qualitySelect.appendChild(item);
            });

            var values = Array.from(this.qualitySelect.options).map(function (option) {
                return option.value;
            });
            var preferredDefault = this.mp3OptionValue(this.DEFAULT_MP3_BITRATE);

            if (previousValue && values.includes(previousValue)) {
                this.qualitySelect.value = previousValue;
            } else if (values.includes(preferredDefault)) {
                this.qualitySelect.value = preferredDefault;
            } else {
                this.qualitySelect.value = this.mp3OptionValue(this.DEFAULT_MP3_BITRATE);
            }

            this.savePreferenceFromSelection(false);
            this.updateOutputSummary();
            return;
        }

        var filtered = this.filterOptionsByFormat(this.options, format);
        var storedId = this.storage.readLocal(this.qualityStorageKey(format), '');

        if (filtered.length === 0) {
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'No analyzed options yet (paste URL and press Enter)';
            empty.disabled = true;
            empty.selected = true;
            this.qualitySelect.appendChild(empty);
            this.qualitySelect.disabled = true;
            var fallback = this.getStoredPreference(format);
            this.setPreferenceFields(fallback.height, fallback.bitrate);
            this.updateOutputSummary();
            return;
        }

        this.qualitySelect.disabled = false;
        filtered.forEach(function (option) {
            var item = document.createElement('option');
            item.value = String(option.id);
            item.textContent = self.buildOptionLabel(option, format);
            if (option.download_url) {
                item.title = String(option.download_url);
            }
            self.qualitySelect.appendChild(item);
        });

        var currentValues = Array.from(this.qualitySelect.options).map(function (option) {
            return option.value;
        });
        if (storedId && currentValues.includes(storedId)) {
            this.qualitySelect.value = storedId;
        } else if (previousValue && currentValues.includes(previousValue)) {
            this.qualitySelect.value = previousValue;
        } else if (this.qualitySelect.options.length > 0) {
            this.qualitySelect.selectedIndex = 0;
        }

        this.savePreferenceFromSelection(false);
        this.updateOutputSummary();
    };

    SlotController.prototype.ensureSettingsBeforeStart = function () {
        var format = this.getSlotFormat();
        this.savePreferenceFromSelection(false);
        if (this.hasParametersForFormat(format)) {
            return true;
        }
        this.modalController.showAlert('Please set download format and quality for ' + this.label + ' before starting.', 'Settings required');
        return false;
    };

    SlotController.prototype.persistSlotUrl = function () {
        this.storage.writeLocal(this.slotStorageKey('url'), this.input.value.trim());
    };

    SlotController.prototype.restoreSlotInputFromStorage = function () {
        if (this.input.value.trim() === '') {
            this.input.value = this.storage.readLocal(this.slotStorageKey('url'), '');
        }
    };

    SlotController.prototype.restoreSlotFormatFromStorage = function () {
        this.formatSelect.value = this.ffmpegAvailable ? this.DEFAULT_TARGET_FORMAT : 'mp4';
    };

    SlotController.prototype.applySlotDefaultSettings = function () {
        var defaultFormat = this.ffmpegAvailable ? this.DEFAULT_TARGET_FORMAT : 'mp4';
        this.formatSelect.value = defaultFormat;
        this.encodingSpeedSelect.value = this.DEFAULT_ENCODING_SPEED;
        this.updateEncodingSpeedVisibility();
        this.updateQualityList();

        if (defaultFormat === 'mp3' && !this.qualitySelect.disabled) {
            this.qualitySelect.value = this.mp3OptionValue(this.DEFAULT_MP3_BITRATE);
            this.savePreferenceFromSelection(false);
            return;
        }

        if (this.qualitySelect.options.length > 0 && !this.qualitySelect.disabled) {
            this.qualitySelect.selectedIndex = 0;
            this.savePreferenceFromSelection(false);
        }
    };

    SlotController.prototype.clearPolling = function () {
        if (this.statusTimer !== null) {
            global.clearInterval(this.statusTimer);
            this.statusTimer = null;
        }
        this.statusCallback = null;
        this.currentJobId = '';
        this.lastServerSignature = '';
        this.lastServerUpdateAt = 0;
    };

    SlotController.prototype.pollStatus = function () {
        var self = this;
        if (!this.currentJobId) {
            return Promise.resolve();
        }

        return this.apiClient.pollStatus(this.currentJobId).then(function (data) {
            self.debugLog('pollStatus', data);

            if (!data || data.ok !== true) {
                self.pseudoPercent = Math.min(85, self.pseudoPercent + 3);
                self.setStatus(self.pseudoPercent, 'Waiting for status data...', 'running');
                return;
            }
            if (data.status === 'idle') {
                self.pseudoPercent = Math.min(85, self.pseudoPercent + 3);
                self.setStatus(self.pseudoPercent, 'Download will start shortly...', 'running');
                return;
            }

            var progress = Number(data.prozent !== undefined ? data.prozent : self.pseudoPercent);
            var message = String(data.meldung || 'Processing...');
            var status = String(data.status || 'running');
            var normalizedProgress = Math.max(0, Math.min(100, Number.isFinite(progress) ? progress : self.pseudoPercent));
            var signature = status + '|' + Math.round(normalizedProgress) + '|' + message;
            var now = Date.now();
            var hasServerChange = signature !== self.lastServerSignature;

            if (hasServerChange) {
                self.lastServerSignature = signature;
                self.lastServerUpdateAt = now;
            } else if (self.lastServerUpdateAt <= 0) {
                self.lastServerUpdateAt = now;
            }

            var effectiveProgress = normalizedProgress;
            var normalizedMessage = self.progressMapper.normalizeProgressMessage(message, status).toLowerCase();
            if (!hasServerChange && status === 'running' && normalizedMessage.includes('decoding audio')) {
                var elapsed = now - self.lastServerUpdateAt;
                if (elapsed >= 3200) {
                    effectiveProgress = Math.min(96, Math.max(normalizedProgress, (self.pseudoPercent || normalizedProgress) + 1));
                    self.lastServerUpdateAt = now;
                }
            }

            self.setStatus(effectiveProgress, message, status);

            if (status === 'done' || status === 'error') {
                var callback = self.statusCallback;
                self.clearPolling();
                if (typeof callback === 'function') {
                    callback(status, message);
                }
            }
        }).catch(function () {
            self.pseudoPercent = Math.min(85, self.pseudoPercent + 2);
            self.setStatus(self.pseudoPercent, 'Connecting to status endpoint...', 'running');
        });
    };

    SlotController.prototype.startPolling = function (jobId, callback) {
        var self = this;
        this.clearPolling();
        this.currentJobId = jobId;
        this.statusCallback = callback;
        this.lastServerSignature = '';
        this.lastServerUpdateAt = Date.now();
        this.pseudoPercent = 5;
        this.setStatus(5, 'Starting download job...', 'running');
        void this.pollStatus();
        this.statusTimer = global.setInterval(function () {
            void self.pollStatus();
        }, 800);
    };

    SlotController.prototype.resetPanel = function () {
        if (this.resetTimer !== null) {
            global.clearTimeout(this.resetTimer);
            this.resetTimer = null;
        }

        this.clearPolling();
        this.running = false;
        this.scanning = false;
        this.setStartButtonState(false);
        this.input.value = '';
        this.lastScanUrl = '';
        this.options = [];
        this.persistSlotUrl();
        this.resetHeader();
        this.applySlotDefaultSettings();

        if (this.downloadFrame && 'src' in this.downloadFrame) {
            this.downloadFrame.src = 'about:blank';
        }

        this.setStatus(0, 'Ready.', 'running');
    };

    SlotController.prototype.confirmDuplicateIfNeeded = function (url) {
        var message = this.historyService.buildDuplicateMessage(url);
        if (message === '') {
            return Promise.resolve(true);
        }
        return this.modalController.showDuplicate(message, 'Already downloaded in this session');
    };

    SlotController.prototype.triggerResultDownload = function (jobId) {
        var url = this.apiClient.buildResultUrl(jobId);
        this.debugLog('triggerResultDownload', { jobId: jobId, url: url });

        if (this.downloadFrame && 'src' in this.downloadFrame) {
            this.downloadFrame.src = url;
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = url;
        document.body.appendChild(iframe);
    };

    SlotController.prototype.runAutoScan = function (forced) {
        var self = this;
        var scanUrl = String(this.input.value || '').trim();
        if (!this.urlUtils.isHttpUrl(scanUrl)) {
            this.lastScanErrorMessage = '';
            return Promise.resolve();
        }
        if (!forced && scanUrl === this.lastScanUrl) {
            this.lastScanErrorMessage = '';
            return Promise.resolve();
        }

        this.scanToken += 1;
        var scanToken = this.scanToken;
        if (this.scanAbortController) {
            this.scanAbortController.abort();
        }
        this.scanAbortController = new AbortController();
        this.scanning = true;
        this.lastScanErrorMessage = '';
        this.setStartButtonState(true);

        if (!this.running) {
            this.setStatus(3, 'Scanning URL for available qualities...', 'running');
        }

        return this.apiClient.autoScan(scanUrl, this.scanAbortController.signal).then(function (payload) {
            if (scanToken !== self.scanToken) {
                return;
            }

            if (!payload.response.ok) {
                var httpMessage = payload.data && payload.data.meldung
                    ? String(payload.data.meldung)
                    : ('HTTP ' + payload.response.status);
                throw new Error(httpMessage);
            }

            if (!payload.data || payload.data.ok !== true) {
                var message = String(payload.data && payload.data.meldung ? payload.data.meldung : 'Automatic scan failed.');
                self.lastScanErrorMessage = message;
                if (!self.running) {
                    self.setStatus(100, message, 'error');
                }
                self.scanning = false;
                self.setStartButtonState(false);
                return;
            }

            self.lastScanUrl = scanUrl;
            self.options = Array.isArray(payload.data.optionen) ? payload.data.optionen : [];
            self.updateQualityList();

            var payloadTitle = typeof payload.data.scan_title === 'string' ? payload.data.scan_title.trim() : '';
            var payloadDuration = self.progressMapper.normalizeDurationSeconds(payload.data.scan_duration_seconds);
            var derivedMeta = self.progressMapper.deriveScanMetaFromOptions(self.options);
            self.updateHeader(
                payloadTitle !== '' ? payloadTitle : derivedMeta.title,
                payloadDuration > 0 ? payloadDuration : derivedMeta.durationSeconds
            );

            var warning = String(payload.data.warnung || '').trim();
            var notice = String(payload.data.hinweis || '').trim();
            if (self.options.length === 0 && (warning !== '' || notice !== '')) {
                self.lastScanErrorMessage = warning !== '' ? warning : notice;
            } else {
                self.lastScanErrorMessage = '';
            }
            if (!self.running) {
                self.setStatus(100, warning !== '' ? warning : (notice !== '' ? notice : 'Scan completed.'), 'success');
            }
            self.scanning = false;
            self.setStartButtonState(false);
        }).catch(function (error) {
            if (error && typeof error === 'object' && error.name === 'AbortError') {
                return;
            }
            if (scanToken !== self.scanToken) {
                return;
            }
            var normalizedError = self.baseUtils.getErrorMessage(error, 'Automatic scan failed. Please try again.');
            self.lastScanErrorMessage = normalizedError;
            if (!self.running) {
                self.setStatus(100, normalizedError, 'error');
            }
            self.scanning = false;
            self.setStartButtonState(false);
        });
    };

    SlotController.prototype.startDownload = function (options) {
        var self = this;
        var config = options && typeof options === 'object' ? options : {};
        var source = String(config.source || 'manual').toLowerCase();
        var queueMode = source === 'queue';
        var skipDuplicatePrompt = config.skipDuplicatePrompt === true || queueMode;
        var onFinalized = typeof config.onFinalized === 'function' ? config.onFinalized : null;

        function notifyFinal(status, message) {
            if (typeof onFinalized !== 'function') {
                return;
            }
            try {
                onFinalized({
                    status: String(status || 'error'),
                    message: String(message || ''),
                    slotKey: self.key
                });
            } catch (error) {
            }
        }

        if (this.resetTimer !== null) {
            global.clearTimeout(this.resetTimer);
            this.resetTimer = null;
        }

        if (this.manualInteractionLocked && !queueMode) {
            var lockMessage = 'Manual start is disabled while auto mode is active.';
            this.setStatus(100, lockMessage, 'error');
            notifyFinal('error', lockMessage);
            return Promise.resolve();
        }

        if (this.running) {
            var runningMessage = 'A download is already running for this slot.';
            this.setStatus(100, runningMessage, 'error');
            notifyFinal('error', runningMessage);
            return Promise.resolve();
        }

        var url = this.input.value.trim();
        if (!this.urlUtils.isHttpUrl(url)) {
            var invalidUrlMessage = 'Please enter a valid URL in ' + this.label + '.';
            this.setStatus(100, invalidUrlMessage, 'error');
            notifyFinal('error', invalidUrlMessage);
            return Promise.resolve();
        }

        var format = this.getSlotFormat();
        if (format === 'mp3' && !this.ffmpegAvailable) {
            var ffmpegMessage = 'ffmpeg is required for MP3 downloads.';
            this.setStatus(100, ffmpegMessage, 'error');
            notifyFinal('error', ffmpegMessage);
            return Promise.resolve();
        }

        if (!this.ensureSettingsBeforeStart()) {
            notifyFinal('error', 'Settings are missing.');
            return Promise.resolve();
        }

        if (this.lastScanUrl !== url) {
            var scanMessage = 'URL changed. Paste the URL and press Enter to scan it before starting download.';
            this.setStatus(100, scanMessage, 'error');
            notifyFinal('error', scanMessage);
            return Promise.resolve();
        }

        var resolved = this.resolveSelectedOptionForStart(format);
        if (!resolved.option) {
            var resolvedError = resolved.error || 'No valid option is available for download.';
            this.setStatus(100, resolvedError, 'error');
            notifyFinal('error', resolvedError);
            return Promise.resolve();
        }

        var payload = this.buildDownloadOptionPayload(resolved.option);
        if (!payload.download_url) {
            var payloadMessage = 'Selected quality does not have a valid source URL.';
            this.setStatus(100, payloadMessage, 'error');
            notifyFinal('error', payloadMessage);
            return Promise.resolve();
        }

        var confirmPromise = skipDuplicatePrompt ? Promise.resolve(true) : this.confirmDuplicateIfNeeded(url);
        return confirmPromise.then(function (confirmed) {
            if (!confirmed) {
                var canceled = 'Download canceled.';
                self.setStatus(0, canceled, 'running');
                self.setStartButtonState(false);
                notifyFinal('canceled', canceled);
                return;
            }

            self.persistSlotUrl();
            self.storage.writeLocal(self.slotStorageKey('format'), format);
            self.savePreferenceFromSelection(false);
            self.actionInput.value = 'herunterladen_option';
            self.urlInput.value = url;
            var optionPayload = JSON.stringify(payload);
            self.optionPayloadInput.value = optionPayload;
            self.prefHeightInput.value = String(Math.max(0, Number(resolved.prefHeight) || 0));
            self.prefBitrateInput.value = String(Math.max(0, Number(resolved.prefBitrate) || 0));
            self.targetFormatInput.value = format;
            self.compressionLevelInput.value = self.encodingSpeedSelect.value;

            self.running = true;
            self.setStartButtonState(true);
            self.setStatus(5, 'Starting download job...', 'running');

            return self.apiClient.startWorkerJob({
                url: url,
                optionPayload: optionPayload,
                prefHeight: self.prefHeightInput.value,
                prefBitrate: self.prefBitrateInput.value,
                format: format,
                compressionLevel: self.compressionLevelInput.value
            }).then(function (jobId) {
                self.jobIdInput.value = jobId;

                self.startPolling(jobId, function (status, message) {
                    self.running = false;
                    self.setStartButtonState(false);

                    if (status === 'done') {
                        self.historyService.remember(url);
                        self.setStatus(100, message || 'Download completed.', 'done');
                        self.triggerResultDownload(jobId);
                        if (!queueMode) {
                            self.resetTimer = global.setTimeout(function () {
                                self.resetTimer = null;
                                self.resetPanel();
                            }, 1100);
                        }
                        notifyFinal('done', message || 'Download completed.');
                        return;
                    }

                    var failureMessage = message || 'Download failed.';
                    self.setStatus(100, failureMessage, 'error');
                    notifyFinal('error', failureMessage);
                });
            }).catch(function (error) {
                self.running = false;
                self.setStartButtonState(false);
                self.clearPolling();
                var startError = self.baseUtils.getErrorMessage(error, 'Failed to start worker job.');
                self.setStatus(100, startError, 'error');
                notifyFinal('error', startError);
            });
        });
    };

    SlotController.prototype.bindEvents = function () {
        var self = this;

        this.startButton.addEventListener('click', function () {
            void self.startDownload();
        });

        this.input.addEventListener('input', function () {
            self.persistSlotUrl();
            var currentUrl = String(self.input.value || '').trim();
            if (currentUrl !== self.lastScanUrl) {
                self.options = [];
                self.updateQualityList();
                self.resetHeader();
                self.refreshStartButtonState();
                if (!self.running) {
                    self.setStatus(0, 'URL updated. Press Enter to scan available qualities.', 'running');
                }
            }
        });

        this.input.addEventListener('paste', function () {
            global.setTimeout(function () {
                self.persistSlotUrl();
                void self.runAutoScan(true);
            }, 0);
        });

        this.input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            self.persistSlotUrl();
            void self.runAutoScan(true);
        });

        this.clearButton.addEventListener('click', function () {
            if (self.running) {
                return;
            }
            self.resetPanel();
        });

        this.formatSelect.addEventListener('change', function () {
            self.storage.writeLocal(self.slotStorageKey('format'), self.getSlotFormat());
            self.updateQualityList();
            self.updateEncodingSpeedVisibility();
            self.refreshStartButtonState();
            self.updateOutputSummary();
        });

        this.qualitySelect.addEventListener('change', function () {
            self.savePreferenceFromSelection(true);
        });

        this.encodingSpeedSelect.addEventListener('change', function () {
            self.storage.writeLocal(self.slotStorageKey('encoding_speed'), self.encodingSpeedSelect.value);
            self.updateOutputSummary();
            self.refreshStartButtonState();
        });
    };

    SlotController.prototype.init = function () {
        this.resetHeader();
        this.restoreSlotInputFromStorage();
        this.restoreSlotFormatFromStorage();
        this.applySlotDefaultSettings();
        this.refreshStartButtonState();
        this.updateOutputSummary();
        this.setStatus(0, 'Ready.', 'running');

        if (this.input.value.trim() !== '') {
            this.persistSlotUrl();
            if (this.options.length === 0) {
                this.setStatus(0, 'URL ready. Press Enter to scan available qualities.', 'running');
            }
        }
    };

    root.SlotController = SlotController;
})(window);
