/**
 * @file sparxstar-integrator.js
 * @version 3.2.0
 * @description Hardened master orchestrator.
 * UPDATED: Merged Technical + Identity data into a single request to prevent
 * partial data saves and 400 errors.
 */
(function (window, document) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    const debug = !!(window.sparxstarUserEnvData && window.sparxstarUserEnvData.debug);
    const log = (msg, data) => {
        if (debug && window.console && console.info) {
            console.info('[SPARXSTAR Integrator]', msg, data || '');
        }
    };

    // ---------- Compatibility check ----------

    function isCompatibleBrowser() {
        try {
            return (
                'Promise' in window &&
                'fetch' in window &&
                'MediaRecorder' in window &&
                navigator.mediaDevices &&
                typeof navigator.mediaDevices.getUserMedia === 'function'
            );
        } catch (_e) {
            return false;
        }
    }

    // ---------- Core integrator ----------

    async function initialize() {
        const SPX        = window.SPARXSTAR || {};
        const State      = SPX.State || null;
        const Collectors = SPX.Collectors || null;
        const Profile    = SPX.Profile || null;
        const Sync       = SPX.Sync || null;
        const UI         = SPX.UI || null;

        if (!State || !Collectors || !Profile || !Sync) {
            log('Missing core modules, aborting initialization.', {
                hasState: !!State,
                hasCollectors: !!Collectors,
                hasProfile: !!Profile,
                hasSync: !!Sync
            });
            return;
        }

        log('Initialization started.');

        // 0. Optional UI bootstrap
        try {
            if (UI && typeof UI.init === 'function') {
                UI.init(State);
                log('UI initialized.');
            }
        } catch (e) {
            log('UI.init failed', e && e.message ? e.message : e);
        }

        // 1. Compatibility notification
        if (!isCompatibleBrowser()) {
            log('Compatibility check failed; dispatching upgrade event.');
            try {
                document.dispatchEvent(new CustomEvent('sparxstar:compatibility-failed', {
                    bubbles: true,
                    composed: true
                }));
            } catch (e) {
                log('Failed to dispatch compatibility event', e);
            }
        }

        // 2. TECHNICAL PIPELINE (always runs)
        log('Collecting technical data...');
        let rawTechnicalData;
        try {
            rawTechnicalData = await Collectors.collectTechnicalData();
        } catch (e) {
            log('collectTechnicalData failed, aborting', e);
            return;
        }

        // Normalize and freeze
        State.technical = State.technical || {};
        State.technical.raw = Object.freeze({
            network:   rawTechnicalData.network   || {},
            battery:   rawTechnicalData.battery   || {},
            performance: rawTechnicalData.performance || {},
            device:    rawTechnicalData.device    || {},
            browser:   rawTechnicalData.browser   || {},
            sessionId: rawTechnicalData.sessionId || null
        });

        // Extract Session ID for Sync calls
        const currentSessionId = State.technical.raw.sessionId;

        // Mirror sessionId into identifiers
        State.identifiers = State.identifiers || {};
        if (!State.identifiers.sessionId) {
            State.identifiers.sessionId = currentSessionId;
        }

        log('Technical raw state frozen.', State.technical.raw);

        // Derive Profile
        let profile = {};
        try {
            profile = Profile.deriveProfile(State.technical.raw) || {};
        } catch (e) {
            log('Profile derivation failed', e);
        }

        State.technical.profile = Object.freeze(profile);
        log('Technical profile derived and frozen.', State.technical.profile);

        // NOTE: We do NOT send the technical snapshot here anymore.
        // We wait for the identifiers pipeline to merge everything into one request.

        // 3. IDENTIFIERS PIPELINE (Consent Gated)

        const runIdentifiersPipeline = async () => {
            log('Identifiers pipeline starting.');

            let ids;
            try {
                ids = await Collectors.collectIdentifyingData();
            } catch (e) {
                log('collectIdentifyingData failed', e);
                return;
            }

            const nextIdentifiers = {
                sessionId: currentSessionId,
                visitorId: ids.visitorId || null,
                deviceDetails: ids.deviceDetails || null,
                ipAddress: ids.ipAddress || null
            };

            State.identifiers = Object.freeze(nextIdentifiers);
            log('Identifiers state frozen.', State.identifiers);

            if (Sync && typeof Sync.sendIdentifyingSnapshot === 'function') {
                log('Sending unified snapshot (Technical + Identifiers).');
                try {
                    // MERGE: Combine Identifiers + Technical Data into one payload
                    // This ensures the server gets everything at once.
                    const fullPayload = {
                        ...State.identifiers,
                        technical: State.technical 
                    };

                    await Sync.sendIdentifyingSnapshot(
                        State.identifiers.visitorId, // Fingerprint
                        null,                        // Device Hash (Calculated Server-Side)
                        currentSessionId,            // Session ID
                        fullPayload                  // The Merged Data
                    );
                } catch (e) {
                    log('sendIdentifyingSnapshot failed', e);
                }
            }
        };

        // Consent discovery
        State.privacy = State.privacy || {};
        State.privacy.consentGiven = !!State.privacy.consentGiven;
        State.privacy.consentCategories = State.privacy.consentCategories || [];

        let statsConsent = false;

        if (typeof window.wp_has_consent === 'function') {
            try { statsConsent = !!window.wp_has_consent('statistics'); } catch (e) {}
        } else if (window.wp_consent_api && typeof window.wp_consent_api.get_consent === 'function') {
            try { statsConsent = !!window.wp_consent_api.get_consent('statistics'); } catch (e) {}
        }

        if (statsConsent) {
            State.privacy.consentGiven = true;
            if (State.privacy.consentCategories.indexOf('statistics') === -1) {
                State.privacy.consentCategories.push('statistics');
            }
            await runIdentifiersPipeline();
        } else {
            log('Statistics consent not present; identifiers pipeline will wait for change event.');
        }

        // Listen for consent changes
        document.addEventListener('wp_listen_for_consent_change', function (event) {
            const detail = event && event.detail;
            if (!detail || typeof detail !== 'object') return;

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

        // 4. FINAL READY EVENT
        log('Dispatching sparxstar:environment-ready event.');
        try {
            document.dispatchEvent(new CustomEvent('sparxstar:environment-ready', {
                bubbles: true,
                composed: true,
                detail: {
                    technical: State.technical,
                    privacy: State.privacy
                }
            }));
        } catch (e) {
            log('Failed to dispatch environment-ready event', e);
        }

        log('Initialization complete.');
    }

    // DOM readiness hook
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

})(window, document);