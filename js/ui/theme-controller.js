(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function ThemeController(options) {
        options = options || {};
        this.storage = options.storage;
        this.storageKey = String(options.storageKey || 'video_tool_theme_mode');

        this.themeToggleButton = document.getElementById('theme-toggle');
        this.themeModeSwitch = document.getElementById('theme-mode-switch');
        this.themeMediaQuery = global.matchMedia ? global.matchMedia('(prefers-color-scheme: dark)') : null;

        this.boundSystemThemeChanged = this.onSystemThemeChanged.bind(this);
    }

    ThemeController.prototype.init = function () {
        var self = this;

        if (this.themeToggleButton) {
            this.themeToggleButton.addEventListener('click', function () {
                self.toggleManualTheme();
            });
        }

        if (this.themeModeSwitch) {
            this.themeModeSwitch.addEventListener('click', function () {
                self.toggleManualTheme();
            });
        }

        if (this.themeMediaQuery) {
            if (typeof this.themeMediaQuery.addEventListener === 'function') {
                this.themeMediaQuery.addEventListener('change', this.boundSystemThemeChanged);
            } else if (typeof this.themeMediaQuery.addListener === 'function') {
                this.themeMediaQuery.addListener(this.boundSystemThemeChanged);
            }
        }

        this.applyPreferredTheme();
    };

    ThemeController.prototype.getSystemTheme = function () {
        if (!this.themeMediaQuery) {
            return 'dark';
        }
        return this.themeMediaQuery && this.themeMediaQuery.matches ? 'dark' : 'light';
    };

    ThemeController.prototype.getStoredTheme = function () {
        var stored = this.storage.readLocal(this.storageKey, '');
        if (stored === 'light' || stored === 'dark') {
            return stored;
        }
        return '';
    };

    ThemeController.prototype.updateThemeControlState = function (activeTheme, source) {
        if (this.themeToggleButton) {
            var nextTheme = activeTheme === 'dark' ? 'light' : 'dark';
            this.themeToggleButton.textContent = nextTheme === 'dark' ? 'Switch to Dark' : 'Switch to Light';
            this.themeToggleButton.setAttribute('aria-label', 'Switch to ' + nextTheme + ' theme');
            this.themeToggleButton.title = source === 'system'
                ? 'Following your system appearance setting'
                : 'Using your saved appearance setting';
        }

        if (this.themeModeSwitch) {
            var nextMode = activeTheme === 'dark' ? 'light' : 'dark';
            this.themeModeSwitch.classList.toggle('is-light', activeTheme === 'light');
            this.themeModeSwitch.classList.toggle('is-dark', activeTheme === 'dark');
            this.themeModeSwitch.setAttribute('aria-pressed', activeTheme === 'light' ? 'true' : 'false');
            this.themeModeSwitch.setAttribute(
                'aria-label',
                'Current theme: ' + (activeTheme === 'dark' ? 'Dark' : 'Light') + '. Switch to ' + (nextMode === 'dark' ? 'Dark' : 'Light') + '.'
            );
            this.themeModeSwitch.title = source === 'system'
                ? 'Following your system appearance setting'
                : 'Using your saved appearance setting';
        }
    };

    ThemeController.prototype.applyTheme = function (theme, source) {
        var effectiveTheme = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', effectiveTheme);
        document.documentElement.setAttribute('data-theme-source', source);
        this.updateThemeControlState(effectiveTheme, source);
    };

    ThemeController.prototype.applyPreferredTheme = function () {
        var storedTheme = this.getStoredTheme();
        if (storedTheme !== '') {
            this.applyTheme(storedTheme, 'manual');
            return;
        }
        this.applyTheme(this.getSystemTheme(), 'system');
    };

    ThemeController.prototype.setManualTheme = function (theme) {
        this.storage.writeLocal(this.storageKey, theme);
        this.applyTheme(theme, 'manual');
    };

    ThemeController.prototype.toggleManualTheme = function () {
        var currentTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        this.setManualTheme(nextTheme);
    };

    ThemeController.prototype.onSystemThemeChanged = function () {
        if (this.getStoredTheme() !== '') {
            return;
        }
        this.applyTheme(this.getSystemTheme(), 'system');
    };

    ThemeController.prototype.handleEscape = function () {
        return false;
    };

    root.ThemeController = ThemeController;
})(window);
