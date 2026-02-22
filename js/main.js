(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function boot() {
        if (!root.config) {
            return;
        }

        var app = new root.AppController({ config: root.config });
        app.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
        return;
    }

    boot();
})(window);
