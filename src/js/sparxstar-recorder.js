/**
 * SPARXSTAR User Environment Check
 *
 * Recorder telemetry bridge for cross-plugin diagnostic event capture.
 *
 * @module sparxstar-recorder
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    /**
     * Log a recorder event with environment context.
     * 
     * @param {Object} eventData - Event data from external plugins
     */
    window.SPARXSTAR.logRecorderEvent = function (eventData) {
        try {
            if (!window.sparxstarUECRecorderLog || !window.sparxstarUECRecorderLog.endpoint) {
                console.warn('[SparxstarUEC] Recorder log endpoint not configured.');
                return;
            }

            // Get minimal environment snapshot (not full State - too large)
            const baseEnv = window.SPARXSTAR && window.SPARXSTAR.State 
                ? window.SPARXSTAR.State 
                : null;

            // Safe environment extraction - handles incomplete/partial State objects
            const env = (() => {
                if (!baseEnv || typeof baseEnv !== 'object') {
                    return { deviceClass: null, networkProfile: null, browser: null };
                }

                const tech = baseEnv.technical || {};
                const profile = tech.profile || {};
                const raw = tech.raw || {};
                const browserData = raw.browser || {};

                return {
                    deviceClass: profile.deviceClass || null,
                    networkProfile: profile.networkProfile || null,
                    browser: browserData.name || null
                };
            })();

            const payload = {
                type: 'starmus_event',
                ts: new Date().toISOString(),
                env,
                network_realtime: {
                    onLine: navigator.onLine,
                    effectiveType: navigator.connection?.effectiveType || 'unknown',
                    rtt: navigator.connection?.rtt || null,
                    saveData: navigator.connection?.saveData || false
                },
                event: eventData || {}
            };

            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });

            // Prefer sendBeacon for reliability during page unload
            // Note: sendBeacon cannot send custom headers, so nonce is not included
            // The recorder-log endpoint has open permissions for telemetry
            if (navigator.sendBeacon) {
                navigator.sendBeacon(window.sparxstarUECRecorderLog.endpoint, blob);
            } else {
                fetch(window.sparxstarUECRecorderLog.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload),
                    keepalive: true
                }).catch(() => {
                    // Silent fail - don't break the page
                });
            }
        } catch (e) {
            console.warn('[SparxstarUEC] logRecorderEvent failed:', e);
        }
    };

    /**
     * Attach listener to StarmusHooks when available.
     * Bulletproof: UEC runs completely standalone if Starmus is missing.
     * No infinite retries, no errors, no wasted CPU.
     */
    (function attachStarmusListener() {
        // If Starmus is missing entirely — STOP. Do not retry.
        if (typeof window.StarmusHooks === 'undefined') {
            // Still expose SPARXSTAR.Recorder for manual logs.
            if (!window.SPARXSTAR.__warnedNoStarmus) {
                if (window.sparxstarUserEnvData && window.sparxstarUserEnvData.debug) {
                    console.info('[SparxstarUEC] Starmus not detected. Running in standalone mode.');
                }
                window.SPARXSTAR.__warnedNoStarmus = true;
            }
            return;
        }

        // If Starmus exists but Hooks not ready — retry until ready
        if (typeof window.StarmusHooks.addAction !== 'function') {
            setTimeout(attachStarmusListener, 250);
            return;
        }

        // Prevent double listener
        if (window.SPARXSTAR.__listenerAttached) {
            return;
        }

        window.StarmusHooks.addAction(
            'starmus_event',
            'UECRecorderMonitor',
            (data) => window.SPARXSTAR.logRecorderEvent(data)
        );

        window.SPARXSTAR.__listenerAttached = true;
        
        if (window.sparxstarUserEnvData && window.sparxstarUserEnvData.debug) {
            console.info('[SparxstarUEC] Starmus event listener attached.');
        }
    })();

    // Export to SPARXSTAR namespace
    window.SPARXSTAR.Recorder = {
        logEvent: window.SPARXSTAR.logRecorderEvent
    };

})(window);
