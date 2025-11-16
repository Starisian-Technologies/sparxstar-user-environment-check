/**
 * @file sparxstar-integrator.js
 * @version 2.0.0
 * @description Hardened master orchestrator for the Sparxstar User Environment Check plugin.
 * Runs technical pipeline (always), statistics-gated identifiers pipeline, enforces
 * immutability, rate-limits sync, and dispatches the final environment-ready event.
 */
(function (window, document) {
    'use strict';

    const debug = !!(window.sparxstarUserEnvData && window.sparxstarUserEnvData.debug);
    const log = (msg, data) => {
        if (debug && window.console && console.info) {
            console.info('[SPARXSTAR Integrator]', msg, data || '');
        }
    };

    function isCompatibleBrowser() {
        try {
            return (
                'Promise' in window &&
                'fetch' in window &&
                'MediaRecorder' in window &&
                navigator.mediaDevices &&
                typeof navigator.mediaDevices.getUserMedia === 'function'
            );
        } catch {
            return false;
        }
    }

    // LocalStorage helpers for rate limiting.
    function lsGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    }

    function lsSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // ignore
        }
    }

    const ONE_DAY_MS = 24 * 60 * 60 * 1000;

    function shouldSend(key) {
        const last = lsGet(key);
        if (!last) return true;
        const diff = Date.now() - parseInt(last, 10);
        return isNaN(diff) || diff >= ONE_DAY_MS;
    }

    function markSent(key) {
        lsSet(key, String(Date.now()));
    }

    async function initialize() {
        const SPX       = window.SPARXSTAR || {};
        const State     = SPX.State;
        const Collectors= SPX.Collectors;
        const Profile   = SPX.Profile;
        const Sync      = SPX.Sync;

        if (!State || !Collectors || !Profile || !Sync) {
            log('Missing core modules. Aborting initialization.', {
                hasState: !!State,
                hasCollectors: !!Collectors,
                hasProfile: !!Profile,
                hasSync: !!Sync
            });
            return;
        }

        log('Initialization started.');

        // 1. Compatibility notification (non-blocking, UX only).
        if (!isCompatibleBrowser()) {
            log('Compatibility check failed; dispatching upgrade event.');
            document.dispatchEvent(new CustomEvent('sparxstar:compatibility-failed', {
                bubbles: true,
                composed: true
            }));
        }

        // 2. TECHNICAL PIPELINE (always)
        log('Collecting technical data...');
        const rawTechnicalData = await Collectors.collectTechnicalData();

        // Populate and freeze technical branch (functional + non-personal).
        State.technical.raw = Object.freeze({
            device: rawTechnicalData.device || {},
            network: rawTechnicalData.network || {},
            browser: rawTechnicalData.browser || {},
            battery: rawTechnicalData.battery || {},
            performance: rawTechnicalData.performance || {},
            sessionId: rawTechnicalData.sessionId || null
        });

        // Mirror sessionId into identifiers (organizationally), but treat as functional.
        State.identifiers.sessionId = rawTechnicalData.sessionId || null;

        log('Technical raw state frozen.', State.technical.raw);

        const profile = Profile.deriveProfile(State.technical.raw);
        State.technical.profile = Object.freeze(profile);

        log('Technical profile derived and frozen.', State.technical.profile);

        // Rate-limited technical snapshot (safe for Cloudflare / ops).
        if (shouldSend('spx_env_last_tech')) {
            log('Sending technical snapshot.');
            await Sync.sendTechnicalSnapshot(State.technical);
            markSent('spx_env_last_tech');
        } else {
            log('Technical snapshot skipped due to rate limit.');
        }

        // 3. IDENTIFIERS PIPELINE (statistics consent-gated)
        const runIdentifiersPipeline = async () => {
            log('Identifiers pipeline starting.');

            const ids = await Collectors.collectIdentifyingData();

            State.identifiers = Object.freeze({
                sessionId: State.identifiers.sessionId || null, // already set
                visitorId: ids.visitorId || null,
                deviceDetails: ids.deviceDetails || null,
                ipAddress: ids.ipAddress || null
            });

            log('Identifiers state frozen.', State.identifiers);

            if (shouldSend('spx_env_last_ident')) {
                log('Sending identifiers snapshot (statistics consent).');
                await Sync.sendIdentifyingSnapshot(State.identifiers);
                markSent('spx_env_last_ident');
            } else {
                log('Identifiers snapshot skipped due to rate limit.');
            }
        };

        // Check consent via JS API: wp_has_consent('statistics')
        let statsConsent = false;
        if (typeof window.wp_has_consent === 'function') {
            try {
                statsConsent = !!window.wp_has_consent('statistics');
            } catch (e) {
                log('wp_has_consent call failed', e.message);
            }
        } else if (window.wp_consent_api && typeof window.wp_consent_api.get_consent === 'function') {
            // Fallback for older/alternate API shapes.
            try {
                statsConsent = !!window.wp_consent_api.get_consent('statistics');
            } catch (e) {
                log('wp_consent_api.get_consent call failed', e.message);
            }
        }

        State.privacy.consentGiven = statsConsent;
        if (statsConsent) {
            await runIdentifiersPipeline();
        } else {
            log('Statistics consent not present; identifiers pipeline will wait for change event.');
        }

        // Listen for consent changes (WP Consent API JS event).
        document.addEventListener('wp_listen_for_consent_change', function (event) {
            const detail = event && event.detail;
            if (!detail || typeof detail !== 'object') {
                return;
            }

            // Example detail: { statistics: 'allow', marketing: 'deny' }
            Object.keys(detail).forEach(function (category) {
                const value = detail[category];
                if (category === 'statistics' && value === 'allow') {
                    if (!State.privacy.consentGiven) {
                        State.privacy.consentGiven = true;
                        State.privacy.consentCategories = Array.from(
                            new Set([].concat(State.privacy.consentCategories || [], ['statistics']))
                        );
                        log('Statistics consent granted via event; running identifiers pipeline.');
                        runIdentifiersPipeline();
                    }
                }
            });
        });

        // 4. FINAL READY EVENT (namespaced, for Starmus & PWA consumers)
        log('Dispatching sparxstar:environment-ready event.');
        document.dispatchEvent(new CustomEvent('sparxstar:environment-ready', {
            bubbles: true,
            composed: true,
            detail: {
                technical: State.technical // contains raw + profile + functional sessionId
            }
        }));

        log('Initialization complete.');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

})(window, document);
