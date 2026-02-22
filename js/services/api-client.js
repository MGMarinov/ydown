(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function ApiClient(options) {
        options = options || {};
        this.basePath = String(options.basePath || global.location.pathname);
    }

    ApiClient.prototype.parseJsonResponse = function (response, invalidJsonMessage) {
        return response.text().then(function (rawText) {
            var data = null;
            try {
                data = JSON.parse(rawText);
            } catch (jsonError) {
                throw new Error(invalidJsonMessage);
            }
            return {
                response: response,
                data: data
            };
        });
    };

    ApiClient.prototype.startWorkerJob = function (payload) {
        var formData = new FormData();
        formData.append('aktion', 'start_worker_job');
        formData.append('seiten_url', String(payload.url || '').trim());
        formData.append('option_payload', String(payload.optionPayload || ''));
        formData.append('praeferenz_hoehe', String(payload.prefHeight || 0));
        formData.append('praeferenz_bitrate', String(payload.prefBitrate || 0));
        formData.append('ziel_format', String(payload.format || 'mp4'));
        formData.append('compression_level', String(payload.compressionLevel || '2'));

        return fetch(this.basePath, {
            method: 'POST',
            body: formData,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.text().then(function (rawText) {
                var data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (jsonError) {
                    throw new Error('Job start response was not valid JSON.');
                }
                return {
                    response: response,
                    data: data
                };
            });
        }).then(function (result) {
            if (!result.response.ok) {
                var httpMessage = result.data && result.data.meldung
                    ? String(result.data.meldung)
                    : ('HTTP ' + result.response.status);
                throw new Error(httpMessage);
            }

            if (!result.data || result.data.ok !== true) {
                var message = String(result.data && result.data.meldung ? result.data.meldung : 'Failed to start worker job.');
                throw new Error(message);
            }

            var jobId = String(result.data.job_id || '').trim();
            if (jobId === '') {
                throw new Error('Worker did not return a valid job ID.');
            }

            return jobId;
        });
    };

    ApiClient.prototype.pollStatus = function (jobId) {
        return fetch('api/status.php?job=' + encodeURIComponent(jobId) + '&_=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        });
    };

    ApiClient.prototype.autoScan = function (url, signal) {
        var formData = new FormData();
        formData.append('aktion', 'analysieren_ajax');
        formData.append('seiten_url', url);

        return fetch(this.basePath, {
            method: 'POST',
            body: formData,
            cache: 'no-store',
            credentials: 'same-origin',
            signal: signal,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.text().then(function (rawText) {
                var data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (jsonError) {
                    throw new Error('Scan response was not valid JSON.');
                }
                return {
                    response: response,
                    data: data
                };
            });
        });
    };

    ApiClient.prototype.buildResultUrl = function (jobId) {
        return 'api/result.php?job=' + encodeURIComponent(jobId) + '&_=' + Date.now();
    };

    root.ApiClient = ApiClient;
})(window);
