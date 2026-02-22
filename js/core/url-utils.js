(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function UrlUtils() {}

    UrlUtils.prototype.isHttpUrl = function (url) {
        return /^https?:\/\/\S+$/i.test(String(url || '').trim());
    };

    UrlUtils.prototype.normalizeUrl = function (url) {
        var raw = String(url || '').trim();
        if (raw === '') {
            return '';
        }

        try {
            var parsed = new URL(raw);
            parsed.hash = '';
            if ((parsed.protocol === 'http:' && parsed.port === '80') || (parsed.protocol === 'https:' && parsed.port === '443')) {
                parsed.port = '';
            }
            if (parsed.pathname === '/') {
                parsed.pathname = '';
            }
            return parsed.toString();
        } catch (error) {
            return raw;
        }
    };

    root.UrlUtils = UrlUtils;
})(window);
