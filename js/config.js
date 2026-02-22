(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});
    var runtimeConfig = global.YDOWN_CONFIG || {};

    root.config = {
        initialOptions: Array.isArray(runtimeConfig.initialOptions) ? runtimeConfig.initialOptions : [],
        ffmpegAvailable: runtimeConfig.ffmpegAvailable === true || runtimeConfig.ffmpegAvailable === 'true',
        storageKeys: {
            successfulDownloadsSession: 'video_tool_successful_downloads_session',
            themeMode: 'video_tool_theme_mode',
            debugMode: 'video_tool_debug_mode',
            jobListJobs: 'video_tool_job_list_jobs',
            jobListDefaults: 'video_tool_job_list_defaults',
            jobListMeta: 'video_tool_job_list_meta'
        },
        constants: {
            mp3FixedBitrates: [96, 128, 160, 192, 256, 320],
            defaultTargetFormat: 'mp3',
            defaultMp3Bitrate: 320,
            defaultEncodingSpeed: '2',
            slotHeaderTitleMax: 50
        },
        slotSpecs: [
            {
                key: 'slot_a',
                label: 'URL 1',
                inputId: 'seiten_url',
                clearButtonId: 'slot1_clear',
                startButtonId: 'start-url-1',
                formatSelectId: 'ziel_format_a',
                qualitySelectId: 'qualitaet_index_a',
                qualityLabelId: 'qualitaet_label_a',
                formId: 'download-form-a',
                downloadFrameId: 'download-frame-a',
                actionInputId: 'download_aktion_a',
                urlInputId: 'download_seiten_url_a',
                optionPayloadInputId: 'download_option_payload_a',
                jobIdInputId: 'job_id_a',
                prefHeightInputId: 'praeferenz_hoehe_a',
                prefBitrateInputId: 'praeferenz_bitrate_a',
                targetFormatInputId: 'download_ziel_format_a',
                encodingSpeedSelectId: 'encoding_speed_a',
                compressionLevelInputId: 'compression_level_a',
                titleId: 'slot-a-title',
                durationId: 'slot-a-duration',
                outputSummaryId: 'slot-output-summary-a',
                defaultHeaderText: 'Download Slot A',
                progressRowId: 'prozess-zeile-a',
                progressFillId: 'prozess-fuellung-a',
                progressTextId: 'prozess-text-a',
                progressPercentId: 'prozess-prozent-a'
            },
            {
                key: 'slot_b',
                label: 'URL 2',
                inputId: 'seiten_url_2',
                clearButtonId: 'slot2_clear',
                startButtonId: 'start-url-2',
                formatSelectId: 'ziel_format_b',
                qualitySelectId: 'qualitaet_index_b',
                qualityLabelId: 'qualitaet_label_b',
                formId: 'download-form-b',
                downloadFrameId: 'download-frame-b',
                actionInputId: 'download_aktion_b',
                urlInputId: 'download_seiten_url_b',
                optionPayloadInputId: 'download_option_payload_b',
                jobIdInputId: 'job_id_b',
                prefHeightInputId: 'praeferenz_hoehe_b',
                prefBitrateInputId: 'praeferenz_bitrate_b',
                targetFormatInputId: 'download_ziel_format_b',
                encodingSpeedSelectId: 'encoding_speed_b',
                compressionLevelInputId: 'compression_level_b',
                titleId: 'slot-b-title',
                durationId: 'slot-b-duration',
                outputSummaryId: 'slot-output-summary-b',
                defaultHeaderText: 'Download Slot B',
                progressRowId: 'prozess-zeile-b',
                progressFillId: 'prozess-fuellung-b',
                progressTextId: 'prozess-text-b',
                progressPercentId: 'prozess-prozent-b'
            }
        ]
    };
})(window);
