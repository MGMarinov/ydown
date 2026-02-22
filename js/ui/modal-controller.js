(function (global) {
    'use strict';

    var root = global.YDown || (global.YDown = {});

    function ModalController(options) {
        options = options || {};
        this.utils = options.utils;
        this.getPrimaryFocus = typeof options.getPrimaryFocus === 'function' ? options.getPrimaryFocus : function () { return null; };

        this.alertModal = document.getElementById('alert-modal');
        this.alertModalMessage = document.getElementById('alert-modal-message');
        this.alertModalClose = document.getElementById('alert-modal-close');
        this.alertModalOk = document.getElementById('alert-modal-ok');

        this.duplicateModal = document.getElementById('duplicate-modal');
        this.duplicateModalMessage = document.getElementById('duplicate-modal-message');
        this.duplicateModalClose = document.getElementById('duplicate-modal-close');
        this.duplicateModalCancel = document.getElementById('duplicate-modal-cancel');
        this.duplicateModalConfirm = document.getElementById('duplicate-modal-confirm');

        this.previousAlertFocus = null;
        this.previousDuplicateFocus = null;
        this.duplicateResolver = null;
    }

    ModalController.prototype.init = function () {
        var self = this;

        if (this.alertModal) {
            this.alertModal.addEventListener('click', function (event) {
                if (event.target === self.alertModal) {
                    self.closeAlert();
                }
            });
        }

        if (this.duplicateModal) {
            this.duplicateModal.addEventListener('click', function (event) {
                if (event.target === self.duplicateModal) {
                    self.closeDuplicate(false);
                }
            });
        }

        if (this.alertModalClose) {
            this.alertModalClose.addEventListener('click', function () {
                self.closeAlert();
            });
        }

        if (this.alertModalOk) {
            this.alertModalOk.addEventListener('click', function () {
                self.closeAlert();
            });
        }

        if (this.duplicateModalClose) {
            this.duplicateModalClose.addEventListener('click', function () {
                self.closeDuplicate(false);
            });
        }

        if (this.duplicateModalCancel) {
            this.duplicateModalCancel.addEventListener('click', function () {
                self.closeDuplicate(false);
            });
        }

        if (this.duplicateModalConfirm) {
            this.duplicateModalConfirm.addEventListener('click', function () {
                self.closeDuplicate(true);
            });
        }
    };

    ModalController.prototype.updateBodyScrollLock = function () {
        var alertVisible = !!this.alertModal && !this.alertModal.hidden;
        var duplicateVisible = !!this.duplicateModal && !this.duplicateModal.hidden;
        document.body.style.overflow = (alertVisible || duplicateVisible) ? 'hidden' : '';
    };

    ModalController.prototype.showAlert = function (text, title) {
        if (!this.alertModal || !this.alertModalMessage) {
            global.alert(text);
            return;
        }

        this.previousAlertFocus = this.utils.isHtmlElement(document.activeElement) ? document.activeElement : null;
        var titleElement = document.getElementById('alert-modal-title');
        if (titleElement) {
            titleElement.textContent = title || 'Missing settings';
        }

        this.alertModalMessage.textContent = text;
        this.alertModal.hidden = false;
        this.alertModal.classList.add('sichtbar');
        this.updateBodyScrollLock();

        var self = this;
        global.setTimeout(function () {
            if (self.alertModalOk) {
                self.alertModalOk.focus();
            }
        }, 0);
    };

    ModalController.prototype.closeAlert = function () {
        if (!this.alertModal) {
            return;
        }

        var activeElement = document.activeElement;
        if (this.utils.isHtmlElement(activeElement) && this.alertModal.contains(activeElement)) {
            activeElement.blur();
        }

        this.alertModal.classList.remove('sichtbar');
        this.alertModal.hidden = true;
        this.updateBodyScrollLock();

        if (this.previousAlertFocus && document.contains(this.previousAlertFocus)) {
            this.previousAlertFocus.focus();
            return;
        }

        var fallback = this.getPrimaryFocus();
        if (fallback && typeof fallback.focus === 'function') {
            fallback.focus();
        }
    };

    ModalController.prototype.showDuplicate = function (text, title) {
        if (!this.duplicateModal || !this.duplicateModalMessage || !this.duplicateModalConfirm) {
            return Promise.resolve(global.confirm(text));
        }

        if (typeof this.duplicateResolver === 'function') {
            this.duplicateResolver(false);
            this.duplicateResolver = null;
        }

        this.previousDuplicateFocus = this.utils.isHtmlElement(document.activeElement) ? document.activeElement : null;

        var titleElement = document.getElementById('duplicate-modal-title');
        if (titleElement) {
            titleElement.textContent = title || 'Duplicate download detected';
        }

        this.duplicateModalMessage.textContent = text;
        this.duplicateModal.hidden = false;
        this.duplicateModal.classList.add('sichtbar');
        this.updateBodyScrollLock();

        var self = this;
        global.setTimeout(function () {
            self.duplicateModalConfirm.focus();
        }, 0);

        return new Promise(function (resolve) {
            self.duplicateResolver = resolve;
        });
    };

    ModalController.prototype.closeDuplicate = function (confirmed) {
        if (!this.duplicateModal) {
            return;
        }

        var activeElement = document.activeElement;
        if (this.utils.isHtmlElement(activeElement) && this.duplicateModal.contains(activeElement)) {
            activeElement.blur();
        }

        this.duplicateModal.classList.remove('sichtbar');
        this.duplicateModal.hidden = true;
        this.updateBodyScrollLock();

        var resolver = this.duplicateResolver;
        this.duplicateResolver = null;
        if (typeof resolver === 'function') {
            resolver(Boolean(confirmed));
        }

        if (this.previousDuplicateFocus && document.contains(this.previousDuplicateFocus)) {
            this.previousDuplicateFocus.focus();
        }
    };

    ModalController.prototype.handleEscape = function () {
        if (this.duplicateModal && !this.duplicateModal.hidden) {
            this.closeDuplicate(false);
            return true;
        }

        if (this.alertModal && !this.alertModal.hidden) {
            this.closeAlert();
            return true;
        }

        return false;
    };

    root.ModalController = ModalController;
})(window);
