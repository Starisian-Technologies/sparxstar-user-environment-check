/**
 * @file Main client-side script for SPARXSTAR User Environment Check.
 * @author Starisian Technologies (Max Barrett)
 * @version 0.5.0
 * @since 0.1.0
 * @license GLP-3.0-or-later
 *
 * @description This script performs two primary functions:
 * 1. Checks for modern browser API compatibility and displays a dismissible banner if required APIs are missing.
 * 2. Collects and sends anonymized, consent-based diagnostic data once per day to a WordPress AJAX endpoint.
 *    Integrates device-detector-js and Network Information API for richer data.
 */

(function() {
    'use strict';

    /**
     * Debug logging utility with different levels
     * @const {object}
     */
    const Logger = {
        levels: { ERROR: 0, WARN: 1, INFO: 2, DEBUG: 3 },
        currentLevel: window.envCheckData?.debug ? 3 : 1, // DEBUG if debug mode, else WARN

        log: function(level, message, data = null) {
            if (level <= this.currentLevel) {
                const prefix = '[EnvCheck]';
                const timestamp = new Date().toISOString();
                switch(level) {
                    case this.levels.ERROR:
                        console.error(`${prefix} ${timestamp} ERROR:`, message, data);
                        break;
                    case this.levels.WARN:
                        console.warn(`${prefix} ${timestamp} WARN:`, message, data);
                        break;
                    case this.levels.INFO:
                        console.info(`${prefix} ${timestamp} INFO:`, message, data);
                        break;
                    case this.levels.DEBUG:
                        console.debug(`${prefix} ${timestamp} DEBUG:`, message, data);
                        break;
                }
            }
        },

        error: function(message, data) { this.log(this.levels.ERROR, message, data); },
        warn: function(message, data) { this.log(this.levels.WARN, message, data); },
        info: function(message, data) { this.log(this.levels.INFO, message, data); },
        debug: function(message, data) { this.log(this(this.levels.DEBUG, message, data); }
    };

    /**
     * A namespaced wrapper for browser localStorage to prevent key collisions with other scripts.
     * @const {object}
     */
    const LS = {
        /**
         * Retrieves an item from localStorage under the plugin's namespace.
         * @param {string} k - The key for the item.
         * @returns {string|null} The value of the item, or null if not found.
         */
        get: (k) => {
            try {
                return localStorage.getItem(`envcheck:${k}`);
            } catch (e) {
                Logger.warn('localStorage access failed', { key: k, error: e.message });
                return null;
            }
        },

        /**
         * Sets an item in localStorage under the plugin's namespace.
         * @param {string} k - The key for the item.
         * @param {string} v - The value to store.
         */
        set: (k, v) => {
            try {
                localStorage.setItem(`envcheck:${k}`, v);
            } catch (e) {
                Logger.warn('localStorage write failed', { key: k, error: e.message });
            }
        },
    };

    /**
     * Data passed from WordPress via `wp_localize_script`.
     * @const {object}
     * @property {string} nonce - The security nonce for the REST request.
     * @property {string} ajax_url - The URL for WordPress's admin-ajax.php (legacy).
     * @property {string} rest_url - The URL for the REST API endpoint.
     * @property {object} i18n - An object containing translated strings.
     * @property {boolean} debug - Debug mode flag.
     */
    const { nonce, ajax_url, rest_url, i18n, debug = false } = window.envCheckData || {};

    // Ensure envCheckData (nonce, ajax_url) is localized in PHP
    if (typeof window.envCheckData === "undefined") {
        Logger.error('envCheckData not found - plugin not properly initialized');
        return;
    }

    if (!nonce || !rest_url) {
        Logger.error('Missing required configuration', { nonce: !!nonce, rest_url: !!rest_url });
        return;
    }

    /**
     * Generate a stable sessionId for this browser session.
     * Enhanced with better uniqueness and error handling
     */
    let sessionId;
    try {
        sessionId = sessionStorage.getItem("envcheck_session_id");
        if (!sessionId) {
            // Fallback for older browsers without crypto.randomUUID
            if (crypto.randomUUID) {
                sessionId = crypto.randomUUID();
            } else {
                const randStr = generateSecureRandomString(12);
                if (!randStr) {
                    // Could not securely generate session ID
                    Logger.error('Session ID cannot be generated securely. Aborting.');
                    return;
                }
                sessionId = 'ses_' + Date.now() + '_' + randStr;
            }
            sessionStorage.setItem("envcheck_session_id", sessionId);
            Logger.debug('Generated new session ID', { sessionId });
        } else {
            Logger.debug('Using existing session ID', { sessionId });
        }
    } catch (e) {
        // Fallback if sessionStorage is not available
        const randStr = generateSecureRandomString(12);
        if (!randStr) {
            Logger.error('Session ID cannot be generated securely. Aborting.');
            return;
        }
        sessionId = 'ses_' + Date.now() + '_' + randStr;
        Logger.warn('SessionStorage unavailable, using temporary session ID', { sessionId, error: e.message });
    }

    // Utility function to generate a cryptographically secure random string
    function generateSecureRandomString(length) {
        // Returns a random string using base36 encoding, securely generated.
        const array = new Uint8Array(length);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(array);
            return Array.from(array).map((b) => b.toString(36)).join('');
        } else {
            // Secure random generation unavailable; fail safely.
            Logger.error('Secure random number generation unavailable. Aborting session ID generation.');
            return null;
        }
    }

    /**
     * Checks for the presence of essential browser APIs.
     *
     * @returns {boolean} Returns `true` if all required APIs are present, otherwise `false`.
     */
    function isBrowserCompatible() {
        return (
            'Promise' in window &&
            'fetch' in window &&
            'MediaRecorder' in window &&
            (navigator.mediaDevices && 'getUserMedia' in navigator.mediaDevices)
        );
    }

    /**
     * Creates and displays the browser upgrade banner if the browser is incompatible.
     */
    function displayUpgradeBanner() {
        Logger.debug('Checking browser compatibility for banner display');

        // Banner guard: Do not show the banner if the user has previously dismissed it.
        if (LS.get('bannerDismissed') === 'true') {
            Logger.debug('Banner previously dismissed, skipping display');
            return;
        }

        if (isBrowserCompatible()) {
            Logger.debug('Browser is compatible, no banner needed');
            return;
        }

        Logger.info('Displaying browser compatibility banner');
        const banner = document.createElement('div');
        banner.className = 'envcheck-banner';
        banner.innerHTML = `
            <div class="envcheck-banner-content">
                <strong>${i18n.notice}</strong> ${i18n.update_message}
                <a href="https://browsehappy.com/" target="_blank" rel="noopener noreferrer">${i18n.update_link}</a>.
            </div>
            <button class="envcheck-dismiss" aria-label="${i18n.dismiss}">&times;</button>
        `;

        document.body.appendChild(banner);

        // Add event listener to the dismiss button.
        banner.querySelector('.envcheck-dismiss').addEventListener('click', () => {
            Logger.debug('Banner dismissed by user');
            banner.remove();
            LS.set('bannerDismissed', 'true');
        });
    }

    /**
     * Collect browser features (simplified Modernizr-style).
     */
    function collectFeatures() {
        const features = {
            webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
            webgl: (() => {
                try {
                    const canvas = document.createElement("canvas");
                    return !!(
                        window.WebGLRenderingContext &&
                        (canvas.getContext("webgl") || canvas.getContext("experimental-webgl"))
                    );
                } catch (e) {
                    Logger.debug('WebGL detection failed', { error: e.message });
                    return false;
                }
            })(),
            serviceWorker: "serviceWorker" in navigator,
            localStorage: "localStorage" in window,
            sessionStorage: "sessionStorage" in window,
            mediaRecorder: 'MediaRecorder' in window,
            getUserMedia: (navigator.mediaDevices && 'getUserMedia' in navigator.mediaDevices),
            promise: 'Promise' in window,
            fetch: 'fetch' in window,
            // Additional features for better compatibility detection
            indexedDB: 'indexedDB' in window,
            webWorkers: 'Worker' in window,
            pushManager: 'PushManager' in window,
            notification: 'Notification' in window,
            geolocation: 'geolocation' in navigator,
            clipboard: 'clipboard' in navigator,
            wakeLock: 'wakeLock' in navigator,
            bluetooth: 'bluetooth' in navigator,
            usb: 'usb' in navigator,
            webAssembly: 'WebAssembly' in window,
            intersectionObserver: 'IntersectionObserver' in window,
            mutationObserver: 'MutationObserver' in window,
            resizeObserver: 'ResizeObserver' in window
        };

        Logger.debug('Collected browser features', { featureCount: Object.keys(features).length });
        return features;
    }

    /**
     * Collect privacy signals.
     */
    function collectPrivacy() {
        return {
            doNotTrack:
                navigator.doNotTrack === "1" ||
                window.doNotTrack === "1" ||
                navigator.msDoNotTrack === "1",
            gpc: navigator.globalPrivacyControl || false
        };
    }

    /**
     * Collect environment snapshot.
     * Now includes device and network information from their respective modules.
     */
    function collectEnvData() {
        const deviceData = window.SPARXSTAR?.DeviceDetector?.getDeviceInfo() || {};
        const networkData = window.SPARXSTAR?.NetworkMonitor?.getNetworkInfo() || {};

        return {
            sessionId: sessionId,
            userAgent: navigator.userAgent,
            os: navigator.platform,
            language: navigator.language || navigator.userLanguage,
            screen: {
                width: screen.width,
                height: screen.height,
                availWidth: screen.availWidth,
                availHeight: screen.availHeight,
                pixelRatio: window.devicePixelRatio || 1
            },
            device: deviceData, // Add device-detector-js data
            network: networkData, // Add network info
            features: collectFeatures(),
            privacy: collectPrivacy(),
            compatible: isBrowserCompatible()
        };
    }

    /**
     * Asynchronously collects a wide range of browser and platform diagnostics.
     *
     * @returns {Promise<object>} A promise that resolves with the diagnostic data object.
     */
    async function collectDiagnostics() {
        let data = collectEnvData(); // Start with the basic env data

        // Parallelize expensive or slow API queries for performance.
        const [storage, mic, battery] = await Promise.allSettled([
            navigator.storage?.estimate?.(),
            navigator.permissions?.query?.({ name: 'microphone' }),
            navigator.getBattery?.(),
        ]);

        // Safely process the results of the parallel queries.
        if (storage.status === 'fulfilled' && storage.value) {
            data.storage = { quota: storage.value.quota, usage: storage.value.usage };
        }
        if (mic.status === 'fulfilled' && mic.value) {
            data.micPermission = mic.value.state;
        }
        if (battery.status === 'fulfilled' && battery.value) {
            data.battery = { level: battery.value.level, charging: battery.value.charging };
        }

        // Client-side data minimization: If DNT or GPC signals are present,
        // strip the payload down to the bare essentials before sending.
        if (data.privacy.doNotTrack || data.privacy.gpc) {
            Logger.info('Privacy signals detected, minimizing data collection');
            data = {
                sessionId: data.sessionId, // Keep session ID for proper tracking
                privacy: data.privacy,
                userAgent: data.userAgent,
                os: data.os,
                compatible: data.compatible,
                features: data.features, // Keep features as they are compatibility-related
                device: data.device, // Keep device info
                network: data.network // Keep network info
            };
        }

        Logger.debug('Diagnostics collected', {
            dataKeys: Object.keys(data),
            privacyMinimized: data.privacy?.doNotTrack || data.privacy?.gpc
        });
        return data;
    }

    /**
     * Sends the collected diagnostic data to the server.
     *
     * @param {object} diagnosticData - The object containing the data to log.
     */
    async function sendDiagnostics(diagnosticData) {
        // Rate limiting check
        const lastSend = LS.get('lastSendTime');
        const minInterval = 5000; // 5 seconds minimum between sends
        if (lastSend && (Date.now() - parseInt(lastSend, 10) < minInterval)) {
            Logger.debug('Rate limited, skipping send');
            return;
        }

        // Strip sensitive data before sending
        const sanitizedData = sanitizeData(diagnosticData);

        try {
            Logger.debug('Sending diagnostics to server', { dataSize: JSON.stringify(sanitizedData).length });

            const response = await fetch(rest_url, {
                method: 'POST',
                body: JSON.stringify(sanitizedData),
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-WP-Nonce": nonce,
                    "Accept-CH":
                        "Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, Sec-CH-UA-Full-Version"
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const json = await response.json();
            Logger.info('Diagnostics sent successfully', { snapshot_id: json.snapshot_id });
            LS.set('lastSendTime', Date.now().toString());
        } catch (error) {
            Logger.error('Failed to send diagnostics', { error: error.message, stack: error.stack });
        }
    }

    /**
     * Sanitizes diagnostic data by removing sensitive information
     * @param {object} data - The data to sanitize
     * @returns {object} Sanitized data
     */
    function sanitizeData(data) {
        const sanitized = { ...data };

        // Remove potentially sensitive fields
        const sensitiveFields = ['inputs', 'formData', 'cookies', 'localStorage'];
        sensitiveFields.forEach(field => {
            if (sanitized[field]) {
                delete sanitized[field];
            }
        });

        // Sanitize user agent to remove potentially identifying information
        if (sanitized.userAgent) {
            // Keep only essential browser info, remove build numbers, etc.
            sanitized.userAgent = sanitized.userAgent.replace(/\b\d{2,}\.\d+\.\d+\.\d+\b/g, 'x.x.x.x');
        }

        // Sanitize geolocation if present (round to reduce precision)
        if (sanitized.latitude && sanitized.longitude) {
            sanitized.latitude = Math.round(sanitized.latitude * 100) / 100;
            sanitized.longitude = Math.round(sanitized.longitude * 100) / 100;
        }

        // Ensure network properties are handled if they are not primitives (e.g., objects from connection.addEventListener)
        if (sanitized.network && typeof sanitized.network === 'object') {
            const allowedNetworkProps = ['online', 'type', 'effectiveType', 'rtt', 'downlink', 'downlinkMax', 'saveData'];
            sanitized.network = Object.fromEntries(
                Object.entries(sanitized.network).filter(([key]) => allowedNetworkProps.includes(key))
            );
        }

        Logger.debug('Data sanitized', {
            originalFields: Object.keys(data).length,
            sanitizedFields: Object.keys(sanitized).length
        });

        return sanitized;
    }

    /**
     * The main execution function for logging.
     * Checks if a log has been sent today and proceeds if not.
     */
    async function runDiagnosticsOncePerDay() {
        const oneDay = 24 * 60 * 60 * 1000;
        const lastCheck = LS.get('lastCheck');

        // Check if we have already logged in the last 24 hours.
        if (lastCheck && (Date.now() - parseInt(lastCheck, 10) < oneDay)) {
            Logger.debug('Daily diagnostics already sent', {
                lastCheck: new Date(parseInt(lastCheck, 10)).toISOString(),
                nextCheck: new Date(parseInt(lastCheck, 10) + oneDay).toISOString()
            });
            return;
        }

        Logger.info('Running daily diagnostics collection');

        // Collect and send the data, then update the timestamp.
        const data = await collectDiagnostics();
        await sendDiagnostics(data);
        LS.set('lastCheck', Date.now().toString());
    }

    /**
     * Sets up a listener for the WordPress Consent API.
     * If consent for 'statistics' is granted after the page loads, it triggers the diagnostic check.
     */
    function initializeConsentListener() {
        // Feature detection: Only run if a WP Consent API provider is active.
        if (window.wp_consent_api) {
            Logger.debug('WP Consent API detected, setting up listener');
            document.addEventListener('wp_listen_for_consent_change', (event) => {
                const { consent_changed, new_consent } = event.detail;
                Logger.debug('Consent change detected', { consent_changed, new_consent });
                // If consent was just granted for the 'statistics' category, run the check.
                if (consent_changed && new_consent && new_consent.includes('statistics')) {
                    Logger.info('Statistics consent granted, running diagnostics');
                    runDiagnosticsOncePerDay();
                }
            });
        } else {
            Logger.debug('WP Consent API not available');
        }
    }

    /**
     * Initialize the script once the DOM is fully loaded.
     */
    document.addEventListener('DOMContentLoaded', () => {
        // Stop if essential data from WordPress is missing.
        if (!nonce || !rest_url || !i18n) {
            Logger.error('Missing localization data', { nonce: !!nonce, rest_url: !!rest_url, i18n: !!i18n });
            return;
        }

        Logger.info('Environment check script initialized', { sessionId });

        displayUpgradeBanner();

        // Initial snapshot send, not daily, as per the second script's logic
        // This will run once per page load and immediately send a snapshot of the environment.
        // The `runDiagnosticsOncePerDay` will still manage the daily, more detailed diagnostics.
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", () => {
                Logger.debug('DOM loaded, sending basic snapshot');
                sendDiagnostics(collectEnvData());
            });
        } else {
                Logger.debug('DOM already loaded, sending basic snapshot immediately');
            sendDiagnostics(collectEnvData()); // Send a basic snapshot immediately
        }

        runDiagnosticsOncePerDay(); // This handles the detailed daily log
        initializeConsentListener();
    });

    // Expose Logger globally for other SPARXSTAR modules
    window.SPARXSTAR = window.SPARXSTAR || {};
    window.SPARXSTAR.Logger = Logger;
    window.SPARXSTAR.EnvCheck = {
        collectEnvData: collectEnvData,
        collectDiagnostics: collectDiagnostics,
        sendDiagnostics: sendDiagnostics,
        isBrowserCompatible: isBrowserCompatible,
        getSessionId: () => sessionId
    };

})();
