/**
 * @file Main client-side script for SPARXSTAR User Environment Check.
 *
 * Collects detailed browser, device, and network information, surfaces helper
 * methods for other scripts, and forwards diagnostics to the WordPress REST API.
 *
 * @version 3.0.0
 */

(function () {
        'use strict';

        const envData = window.envCheckData || {};
        const {
                nonce,
                rest_url: restUrl,
                ajax_url: ajaxUrl,
                i18n = {},
                server = {},
                debug = false,
        } = envData;

        /**
         * Structured logger used across modules.
         * @type {object}
         */
        const Logger = {
                levels: { error: 0, warn: 1, info: 2, debug: 3 },
                currentLevel: debug ? 3 : 1,

                log(level, message, data) {
                        if (level > this.currentLevel) {
                                return;
                        }

                        const prefix = '[EnvCheck]';
                        const timestamp = new Date().toISOString();
                        const payload = data === undefined ? '' : data;

                        switch (level) {
                                case this.levels.error:
                                        console.error(`${prefix} ${timestamp} ERROR:`, message, payload);
                                        break;
                                case this.levels.warn:
                                        console.warn(`${prefix} ${timestamp} WARN:`, message, payload);
                                        break;
                                case this.levels.info:
                                        console.info(`${prefix} ${timestamp} INFO:`, message, payload);
                                        break;
                                default:
                                        console.debug(`${prefix} ${timestamp} DEBUG:`, message, payload);
                                        break;
                        }
                },

                error(message, data) {
                        this.log(this.levels.error, message, data);
                },

                warn(message, data) {
                        this.log(this.levels.warn, message, data);
                },

                info(message, data) {
                        this.log(this.levels.info, message, data);
                },

                debug(message, data) {
                        this.log(this.levels.debug, message, data);
                },
        };

        /**
         * Namespaced localStorage helper.
         * @type {object}
         */
        const LS = {
                get(key) {
                        try {
                                return localStorage.getItem(`envcheck:${key}`);
                        } catch (error) {
                                Logger.warn('localStorage read failed', { key, error: error.message });
                                return null;
                        }
                },
                set(key, value) {
                        try {
                                localStorage.setItem(`envcheck:${key}`, value);
                        } catch (error) {
                                Logger.warn('localStorage write failed', { key, error: error.message });
                        }
                },
        };

        if (!nonce || !restUrl) {
                Logger.error('Missing required localization data', { hasNonce: !!nonce, hasRestUrl: !!restUrl });
                return;
        }

        /**
         * Ensure we have a stable session identifier.
         * @type {string}
         */
        /**
         * Helper to generate a secure random hex string of the specified length.
         * @param {number} length - Number of hex characters desired in the output string.
         * @returns {string} Hex string of exactly the requested length.
         */
        function generateSecureHex(length = 16) {
                if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
                        // Each byte gives 2 hex characters, so need ceil(length/2) bytes
                        const byteLength = Math.ceil(length / 2);
                        const array = new Uint8Array(byteLength);
                        window.crypto.getRandomValues(array);
                        const hex = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
                        return hex.slice(0, length);
                } else {
                        // Worst-case fallback if crypto is not available (should be exceedingly rare)
                        let hex = '';
                        while (hex.length < length) {
                                hex += Math.random().toString(16).slice(2);
                        }
                        return hex.slice(0, length);
                }
        }
        let sessionId = '';
        try {
                sessionId = sessionStorage.getItem('envcheck_session_id') || '';
                if (!sessionId) {
                        sessionId = typeof crypto.randomUUID === 'function'
                                ? crypto.randomUUID()
                                : `ses_${Date.now()}_${generateSecureHex(16)}`;
                        sessionStorage.setItem('envcheck_session_id', sessionId);
                }
        } catch (error) {
                Logger.warn('SessionStorage unavailable; generating ephemeral session id', { error: error.message });
                sessionId = `ses_${Date.now()}_${generateSecureHex(16)}`;
        }

        /**
         * Device detector facade.
         * @type {object}
         */
        const DeviceDetectorFacade = (() => {
                let detector = null;

                /**
                 * Lazy-load the vendor DeviceDetector instance.
                 * @return {DeviceDetector|null}
                 */
                function ensureDetector() {
                        if (detector || typeof window.DeviceDetector === 'undefined') {
                                return detector;
                        }

                        try {
                                detector = new window.DeviceDetector({ skipBotDetection: true, versionTruncation: 2 });
                                Logger.debug('DeviceDetector initialised');
                        } catch (error) {
                                Logger.error('Failed to initialise DeviceDetector', { error: error.message });
                                detector = null;
                        }
                        return detector;
                }

                return {
                        /**
                         * Parse a user agent string into structured device information.
                         * @param {string} userAgent
                         * @return {object}
                         */
                        getDeviceInfo(userAgent = navigator.userAgent) {
                                const instance = ensureDetector();
                                if (!instance) {
                                        return {};
                                }

                                try {
                                        return instance.parse(userAgent) || {};
                                } catch (error) {
                                        Logger.error('Device parsing failed', { error: error.message });
                                        return {};
                                }
                        },
                };
        })();

        /**
         * Network monitor module that captures and caches Network Information API fields.
         * @type {object}
         */
        const NetworkMonitor = (() => {
                let cached = {};

                /**
                 * Normalize Network Information API data into a plain object.
                 * @param {NetworkInformation|null} conn
                 * @return {object}
                 */
                function normalizeConnection(conn) {
                        return {
                                online: navigator.onLine,
                                type: conn && conn.type ? conn.type : 'unknown',
                                effectiveType: conn && conn.effectiveType ? conn.effectiveType : 'unknown',
                                downlink: conn && typeof conn.downlink === 'number' ? conn.downlink : null,
                                downlinkMax: conn && typeof conn.downlinkMax === 'number' ? conn.downlinkMax : null,
                                rtt: conn && typeof conn.rtt === 'number' ? conn.rtt : null,
                                saveData: !!(conn && conn.saveData),
                                bandwidth: conn && typeof conn.bandwidth === 'number' ? conn.bandwidth : null,
                                metered: conn && typeof conn.metered === 'boolean' ? conn.metered : null,
                                effectiveBandwidthEstimate: conn && typeof conn.effectiveBandwidthEstimate === 'number' ? conn.effectiveBandwidthEstimate : null,
                                signalStrength: conn && typeof conn.signalStrength === 'number' ? conn.signalStrength : null,
                                connectionId: conn && typeof conn.id !== 'undefined' ? conn.id : null,
                        };
                }

                /**
                 * Refresh cached network information and return a copy.
                 * @return {object}
                 */
                function update() {
                                const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
                                cached = normalizeConnection(connection);
                                return { ...cached };
                }

                /**
                 * Register listeners for online/offline and connection change events.
                 * @return {void}
                 */
                function initListeners() {
                        window.addEventListener('online', update);
                        window.addEventListener('offline', update);

                        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
                        if (connection && typeof connection.addEventListener === 'function') {
                                connection.addEventListener('change', () => {
                                        update();
                                        document.dispatchEvent(new CustomEvent('sparxstar_network_change', { detail: { ...cached } }));
                                });
                        }
                }

                cached = update();
                initListeners();

                return {
                        getNetworkInfo() {
                                return update();
                        },
                        isOnline() {
                                return !!navigator.onLine;
                        },
                };
        })();

        /**
         * Check support for critical APIs.
         * @return {boolean}
         */
        function isBrowserCompatible() {
                return (
                        'Promise' in window &&
                        'fetch' in window &&
                        'MediaRecorder' in window &&
                        navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function'
                );
        }

        /**
         * Display upgrade banner when compatibility requirements are not met.
         * @return {void}
         */
        function displayUpgradeBanner() {
                if (LS.get('bannerDismissed') === 'true' || isBrowserCompatible()) {
                        return;
                }

                const banner = document.createElement('div');
                banner.className = 'envcheck-banner';
                banner.innerHTML = `
                        <div class="envcheck-banner-content">
                                <strong>${i18n.notice || 'Notice:'}</strong> ${i18n.update_message || ''}
                                <a href="https://browsehappy.com/" target="_blank" rel="noopener noreferrer">${i18n.update_link || 'update your browser'}</a>.
                        </div>
                        <button class="envcheck-dismiss" aria-label="${i18n.dismiss || 'Dismiss'}">&times;</button>
                `;

                banner.querySelector('.envcheck-dismiss').addEventListener('click', () => {
                        LS.set('bannerDismissed', 'true');
                        banner.remove();
                });

                document.body.appendChild(banner);
        }

        /**
         * Gather privacy signals.
         * @return {object}
         */
        function collectPrivacy() {
                return {
                        doNotTrack:
                                navigator.doNotTrack === '1' ||
                                window.doNotTrack === '1' ||
                                navigator.msDoNotTrack === '1',
                        gpc: !!navigator.globalPrivacyControl,
                };
        }

        /**
         * Basic feature detection map.
         * @return {object}
         */
        function collectFeatures() {
                        return {
                                webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
                                webgl: (() => {
                                        try {
                                                const canvas = document.createElement('canvas');
                                                return !!(
                                                        window.WebGLRenderingContext &&
                                                        (canvas.getContext('webgl') || canvas.getContext('experimental-webgl'))
                                                );
                                        } catch (error) {
                                                Logger.debug('WebGL detection failed', { error: error.message });
                                                return false;
                                        }
                                })(),
                                serviceWorker: 'serviceWorker' in navigator,
                                localStorage: 'localStorage' in window,
                                sessionStorage: 'sessionStorage' in window,
                                mediaRecorder: 'MediaRecorder' in window,
                                getUserMedia: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
                                promise: 'Promise' in window,
                                fetch: 'fetch' in window,
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
                                resizeObserver: 'ResizeObserver' in window,
                        };
        }

        /**
         * Collect baseline environment data shared by diagnostics and immediate snapshots.
         * @return {object}
         */
        function collectEnvData() {
                const resolved = typeof Intl !== 'undefined' && typeof Intl.DateTimeFormat === 'function'
                        ? Intl.DateTimeFormat().resolvedOptions()
                        : {};
                const deviceInfo = DeviceDetectorFacade.getDeviceInfo();
                const networkInfo = NetworkMonitor.getNetworkInfo();

                const languages = Array.isArray(navigator.languages) ? navigator.languages.slice(0, 10) : [];

                return {
                        sessionId,
                        userAgent: navigator.userAgent,
                        acceptLanguage: navigator.language || server.acceptLanguage || '',
                        languages,
                        locale: resolved.locale || navigator.language || server.languageCode || '',
                        timeZone: resolved.timeZone || server.timezone || '',
                        server,
                        ipAddress: server.ipAddress || '',
                        screen: {
                                width: window.screen && typeof window.screen.width !== "undefined" ? window.screen.width : null,
                                height: window.screen && typeof window.screen.height !== "undefined" ? window.screen.height : null,
                                availWidth: window.screen && typeof window.screen.availWidth !== "undefined" ? window.screen.availWidth : null,
                                availHeight: window.screen && typeof window.screen.availHeight !== "undefined" ? window.screen.availHeight : null,
                                pixelRatio: window.devicePixelRatio || 1,
                        },
                        device: deviceInfo.device || {},
                        deviceType: (deviceInfo.device && deviceInfo.device.type) ? deviceInfo.device.type : 'unknown',
                        os: deviceInfo.os || {},
                        browser: deviceInfo.client || {},
                        network: networkInfo,
                        features: collectFeatures(),
                        privacy: collectPrivacy(),
                        compatible: isBrowserCompatible(),
                        clientTimestamp: new Date().toISOString(),
                };
        }

        /**
         * Collect extended diagnostics asynchronously.
         * @return {Promise<object>}
         */
        async function collectDiagnostics() {
                let data = collectEnvData();

                const [storage, microphone, battery] = await Promise.allSettled([
                        navigator.storage && typeof navigator.storage.estimate === 'function' ? navigator.storage.estimate() : undefined,
                        navigator.permissions && typeof navigator.permissions.query === 'function' ? navigator.permissions.query({ name: 'microphone' }) : undefined,
                        navigator.getBattery ? navigator.getBattery() : undefined,
                ]);

                if (storage.status === 'fulfilled' && storage.value) {
                        data.storage = {
                                quota: storage.value.quota,
                                usage: storage.value.usage,
                        };
                }

                if (microphone.status === 'fulfilled' && microphone.value) {
                        data.micPermission = microphone.value.state;
                }

                if (battery.status === 'fulfilled' && battery.value) {
                        data.battery = {
                                level: battery.value.level,
                                charging: battery.value.charging,
                        };
                }

                if (data.privacy.doNotTrack || data.privacy.gpc) {
                        Logger.info('Respecting privacy signals; minimising payload');
                        data = {
                                sessionId: data.sessionId,
                                privacy: data.privacy,
                                userAgent: data.userAgent,
                                device: data.device,
                                deviceType: data.deviceType,
                                os: data.os,
                                browser: data.browser,
                                network: data.network,
                                compatible: data.compatible,
                                locale: data.locale,
                                timeZone: data.timeZone,
                        };
                }

                return data;
        }

        /**
         * Remove potentially sensitive fields prior to transmission.
         * @param {object} payload Data object to sanitise.
         * @return {object}
         */
        function sanitizeData(payload) {
                const sanitised = { ...payload };
                const sensitive = ['inputs', 'formData', 'cookies', 'localStorage'];
                sensitive.forEach((key) => {
                        if (key in sanitised) {
                                delete sanitised[key];
                        }
                });

                if (sanitised.userAgent) {
                        sanitised.userAgent = sanitised.userAgent.replace(/\b\d{2,}\.\d+\.\d+\.\d+\b/g, 'x.x.x.x');
                }

                if (sanitised.network && typeof sanitised.network === 'object') {
                        const allowed = [
                                'online',
                                'type',
                                'effectiveType',
                                'downlink',
                                'downlinkMax',
                                'rtt',
                                'saveData',
                                'bandwidth',
                                'metered',
                                'effectiveBandwidthEstimate',
                                'signalStrength',
                                'connectionId',
                        ];
                        sanitised.network = Object.fromEntries(
                                Object.entries(sanitised.network).filter(([key]) => allowed.includes(key))
                        );
                }

                return sanitised;
        }

        /**
         * Send diagnostics to the REST endpoint.
         * @param {object} diagnosticData Data payload.
         * @return {Promise<void>}
         */
        async function sendDiagnostics(diagnosticData) {
                const lastSend = LS.get('lastSendTime');
                const now = Date.now();
                if (lastSend && now - parseInt(lastSend, 10) < 5000) {
                        Logger.debug('Skipping send due to rate limit');
                        return;
                }

                const payload = sanitizeData(diagnosticData);

                try {
                        const response = await fetch(restUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                        'Content-Type': 'application/json',
                                        'X-WP-Nonce': nonce,
                                },
                                body: JSON.stringify(payload),
                        });

                        if (!response.ok) {
                                throw new Error(`HTTP ${response.status}`);
                        }

                        const json = await response.json();
                        Logger.info('Diagnostics sent', json);
                        LS.set('lastSendTime', now.toString());
                } catch (error) {
                        Logger.error('Failed to send diagnostics', { error: error.message });
                }
        }

        /**
         * Perform diagnostic send at most once per 24 hours.
         * @return {Promise<void>}
         */
        async function runDiagnosticsOncePerDay() {
                const oneDay = 24 * 60 * 60 * 1000;
                const lastCheck = LS.get('lastCheck');

                if (lastCheck && Date.now() - parseInt(lastCheck, 10) < oneDay) {
                        return;
                }

                const data = await collectDiagnostics();
                await sendDiagnostics(data);
                LS.set('lastCheck', Date.now().toString());
        }

        /**
         * Listen for consent changes from the WP Consent API.
         * @return {void}
         */
        function initializeConsentListener() {
                if (!window.wp_consent_api) {
                        return;
                }

                document.addEventListener('wp_listen_for_consent_change', (event) => {
                        const details = event.detail || {};
                        if (details.consent_changed && Array.isArray(details.new_consent) && details.new_consent.includes('statistics')) {
                                runDiagnosticsOncePerDay().catch((error) => Logger.error('Consent-triggered diagnostics failed', { error: error.message }));
                        }
                });
        }

        document.addEventListener('DOMContentLoaded', () => {
                displayUpgradeBanner();
                sendDiagnostics(collectEnvData());
                runDiagnosticsOncePerDay();
                initializeConsentListener();
        });

        window.SPARXSTAR = window.SPARXSTAR || {};
        window.SPARXSTAR.Logger = Logger;
        window.SPARXSTAR.NetworkMonitor = NetworkMonitor;
        window.SPARXSTAR.DeviceDetector = DeviceDetectorFacade;
        window.SPARXSTAR.EnvCheck = {
                collectEnvData,
                collectDiagnostics,
                sendDiagnostics,
                isBrowserCompatible,
                getSessionId: () => sessionId,
        };
        window.SPARXSTAR.Utils = {
                getAcceptLanguage: () => navigator.language || server.acceptLanguage || '',
                getLocales: () => (Array.isArray(navigator.languages) ? navigator.languages.slice() : []),
                getTimeZone: () => (Intl.DateTimeFormat().resolvedOptions().timeZone || server.timezone || ''),
                getDeviceType: () => { const info = DeviceDetectorFacade.getDeviceInfo(); return info.device && info.device.type ? info.device.type : 'unknown'; },
                getBrowserName: () => { const info = DeviceDetectorFacade.getDeviceInfo(); return info.client && info.client.name ? info.client.name : 'unknown'; },
                getNetworkType: () => NetworkMonitor.getNetworkInfo().type,
                getNetworkStatus: () => NetworkMonitor.isOnline(),
                getUserAgent: () => navigator.userAgent,
                getServerIp: () => server.ipAddress || '',
                getSessionId: () => sessionId,
                async getSnapshot() {
                        const diagnostics = await collectDiagnostics();
                        return diagnostics;
                },
        };
})();
