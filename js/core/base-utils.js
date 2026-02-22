(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function BaseUtils() {}

    BaseUtils.prototype.isHtmlElement = function (value) {
        return typeof HTMLElement === 'function' && value instanceof HTMLElement;
    };

    BaseUtils.prototype.getErrorMessage = function (error, fallbackMessage) {
        if (error && typeof error === 'object' && typeof error.message === 'string' && error.message.trim() !== '') {
            return error.message.trim();
        }
        return fallbackMessage;
    };

    BaseUtils.prototype.formatTimestamp = function (isoText) {
        var iso = String(isoText || '').trim();
        if (iso === '') {
            return 'unknown time';
        }
        var time = new Date(iso);
        if (Number.isNaN(time.getTime())) {
            return 'unknown time';
        }
        return time.toLocaleString();
    };

    root.BaseUtils = BaseUtils;
})(window);
