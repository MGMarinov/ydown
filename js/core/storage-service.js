(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function StorageService() {}

    StorageService.prototype.readLocal = function (key, fallback) {
        try {
            var value = global.localStorage.getItem(key);
            return value === null ? fallback : value;
        } catch (error) {
            return fallback;
        }
    };

    StorageService.prototype.readLocalInt = function (key, fallback) {
        var value = Number.parseInt(this.readLocal(key, String(fallback)), 10);
        return Number.isFinite(value) ? value : fallback;
    };

    StorageService.prototype.writeLocal = function (key, value) {
        try {
            global.localStorage.setItem(key, String(value));
        } catch (error) {
        }
    };

    StorageService.prototype.removeLocal = function (key) {
        try {
            global.localStorage.removeItem(key);
        } catch (error) {
        }
    };

    StorageService.prototype.readSessionJson = function (key, fallback) {
        try {
            var raw = global.sessionStorage.getItem(key);
            if (!raw) {
                return fallback;
            }
            var data = JSON.parse(raw);
            if (data && typeof data === 'object') {
                return data;
            }
            return fallback;
        } catch (error) {
            return fallback;
        }
    };

    StorageService.prototype.writeSessionJson = function (key, value) {
        try {
            global.sessionStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
        }
    };

    root.StorageService = StorageService;
})(window);
