/**
 * @file sparxstar-ui.js
 * @version 2.0.0
 * @description Event-driven UI: offline banner and upgrade banner. Listens for
 * namespaced events so it stays decoupled from business logic.
 */
(function (window, document) {
    'use strict';

    const localized = window.sparxstarUserEnvData || {};
    const i18n      = localized.i18n || {};

    function ensureBodyReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function createBanner(options) {
        const existing = document.getElementById(options.id);
        if (existing) {
            return existing;
        }
        const banner = document.createElement('div');
        banner.id = options.id;
        banner.className = 'sparxstar-banner ' + (options.extraClass || '');
        banner.innerHTML = options.html;
        document.body.appendChild(banner);
        return banner;
    }

    function displayOfflineBanner() {
        ensureBodyReady(function () {
            createBanner({
                id: 'sparxstar-offline-banner',
                extraClass: 'sparxstar-banner--offline',
                html: '<div class="sparxstar-banner__content">' +
                    '<strong>Connection offline:</strong> ' +
                    'You appear to be offline. Some features may be limited.' +
                    '</div>'
            });
        });
    }

    function hideOfflineBanner() {
        const el = document.getElementById('sparxstar-offline-banner');
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    function displayUpgradeBanner() {
        ensureBodyReady(function () {
            createBanner({
                id: 'sparxstar-upgrade-banner',
                extraClass: 'sparxstar-banner--upgrade',
                html: '<div class="sparxstar-banner__content">' +
                    '<strong>' + (i18n.notice || 'Your browser may be out of date.') + '</strong> ' +
                    (i18n.update_message || 'For the best experience, please update your browser.') +
                    ' <a href="https://browsehappy.com/" target="_blank" rel="noopener noreferrer">' +
                    (i18n.update_link || 'Learn how to update') +
                    '</a>.' +
                    '</div>'
            });
        });
    }

    // Online/offline listeners (for UX only; technical logic lives elsewhere).
    window.addEventListener('online', hideOfflineBanner);
    window.addEventListener('offline', displayOfflineBanner);

    // Show offline banner immediately if we're already offline.
    if (!navigator.onLine) {
        displayOfflineBanner();
    }

    // Listen for compatibility failure dispatched by integrator.
    document.addEventListener('sparxstar:compatibility-failed', function () {
        displayUpgradeBanner();
    });

})(window, document);
