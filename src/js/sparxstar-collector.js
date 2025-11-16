/**
 * @file sparxstar-collectors.js
 * @version 2.0.0
 * @description Memoized, resilient asynchronous collectors for environment data.
 * Separates a technical (always-on) pipeline from a statistics-gated identifiers pipeline.
 */
(function (window, document) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    const debug = !!(window.sparxstarUserEnvData && window.sparxstarUserEnvData.debug);
    const log = (msg, data) => {
        if (debug && window.console && console.debug) {
            console.debug('[SPARXSTAR Collectors]', msg, data || '');
        }
    };

    // ---------- Helpers ----------

    function getCookie(name) {
        try {
            const value = document.cookie.split('; ').find(row => row.startsWith(name + '='));
            return value ? decodeURIComponent(value.split('=')[1]) : null;
        } catch (e) {
            log('getCookie failed', e.message);
            return null;
        }
    }

    function setCookie(name, value, days) {
        try {
            const expires = new Date(Date.now() + (days * 864e5)).toUTCString();
            document.cookie = name + '=' + encodeURIComponent(value) +
                '; expires=' + expires +
                '; path=/; SameSite=Lax';
        } catch (e) {
            log('setCookie failed', e.message);
        }
    }

    function safeRandomId(prefix) {
        try {
            if (window.crypto && crypto.randomUUID) {
                return prefix + crypto.randomUUID();
            }
            if (window.crypto && crypto.getRandomValues) {
                const arr = new Uint8Array(16);
                crypto.getRandomValues(arr);
                return prefix + Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
            }
        } catch (e) {
            log('secure random ID failed, falling back', e.message);
        }
        return prefix + Date.now() + '_' + Math.random().toString(36).slice(2);
    }

    // ---------- Technical Collectors (non-personal, always-on) ----------

    const getNetwork = async () => {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        const current = {
            isOnline: navigator.onLine,
            type: conn && conn.type || 'unknown',
            effectiveType: conn && conn.effectiveType || 'unknown',
            rtt: conn && conn.rtt || null,
            downlink: conn && conn.downlink || null,
            saveData: conn && !!conn.saveData
        };
        log('Network collected', current);
        return current;
    };

    const getBattery = async () => {
        if (!('getBattery' in navigator)) {
            log('Battery API not available');
            return {};
        }
        try {
            const battery = await navigator.getBattery();
            const result = {
                level: battery.level,
                charging: battery.charging
            };
            log('Battery collected', result);
            return result;
        } catch (e) {
            log('Battery collection failed', e.message);
            return {};
        }
    };

    const getPerformance = async () => {
        const result = {
            hardwareConcurrency: navigator.hardwareConcurrency || 1,
            deviceMemory: ('deviceMemory' in navigator) ? navigator.deviceMemory : 0 // in GB
        };
        log('Performance collected', result);
        return result;
    };

    const getTechnicalDevice = async () => {
        // Only expose a minimal device type here; rich details go to identifiers pipeline.
        let type = 'desktop';
        try {
            const dd = window.SPARXSTAR.DeviceDetector;
            if (dd && typeof dd.getDeviceInfo === 'function') {
                const info = dd.getDeviceInfo();
                if (info && info.device && info.device.type) {
                    type = info.device.type;
                }
            } else {
                // fallback heuristic
                const ua = navigator.userAgent || '';
                if (/mobile|android|iphone|ipad|ipod/i.test(ua)) {
                    type = 'mobile';
                }
            }
        } catch (e) {
            log('Technical device detection failed', e.message);
        }
        const result = { type: type };
        log('Technical device collected', result);
        return result;
    };

    const getBrowserCapabilities = (() => {
        let cache = null;
        return async () => {
            if (cache) {
                return cache;
            }
            const safeIsSupported = (mimeType) => {
                try {
                    return typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(mimeType);
                } catch {
                    return false;
                }
            };

            let sampleRate = null;
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (AC) {
                    const ctx = new AC();
                    sampleRate = ctx.sampleRate || null;
                    if (typeof ctx.close === 'function') {
                        ctx.close();
                    }
                }
            } catch (e) {
                log('AudioContext init failed', e.message);
            }

            const caps = {
                mediaRecorder: typeof MediaRecorder !== 'undefined',
                getUserMedia: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
                sampleRate: sampleRate,
                supportedMimeTypes: {
                    'audio/webm;codecs=opus': safeIsSupported('audio/webm;codecs=opus'),
                    'audio/ogg;codecs=opus': safeIsSupported('audio/ogg;codecs=opus'),
                    'audio/mp4': safeIsSupported('audio/mp4')
                },
                serviceWorker: 'serviceWorker' in navigator,
                indexedDB: 'indexedDB' in window
            };
            cache = Object.freeze(caps);
            log('Browser capabilities collected', caps);
            return cache;
        };
    })();

    const getSessionId = async () => {
        // Functional, ephemeral session ID (no consent needed).
        const KEY = 'sparxstar_session_id';
        try {
            let sid = sessionStorage.getItem(KEY);
            if (!sid) {
                sid = safeRandomId('sid_');
                sessionStorage.setItem(KEY, sid);
            }
            log('Session ID collected', sid);
            return sid;
        } catch (e) {
            const sid = safeRandomId('sid_');
            log('SessionStorage unavailable; generated fallback session ID', e.message);
            return sid;
        }
    };

    async function collectTechnicalData() {
        const results = await Promise.allSettled([
            getNetwork(),
            getBattery(),
            getPerformance(),
            getTechnicalDevice(),
            getBrowserCapabilities(),
            getSessionId()
        ]);

        const technical = {
            network: results[0].status === 'fulfilled' ? results[0].value : {},
            battery: results[1].status === 'fulfilled' ? results[1].value : {},
            performance: results[2].status === 'fulfilled' ? results[2].value : {},
            device: results[3].status === 'fulfilled' ? results[3].value : {},
            browser: results[4].status === 'fulfilled' ? results[4].value : {},
            sessionId: results[5].status === 'fulfilled' ? results[5].value : null
        };
        log('Technical data consolidated', technical);
        return technical;
    }

    // ---------- Identifier Collectors (statistics consent-gated) ----------

    const getVisitorId = async () => {
        const COOKIE_KEY = '_spx_vid';

        // If we already have a visitor ID cookie, reuse it.
        const existing = getCookie(COOKIE_KEY);
        if (existing) {
            log('Existing visitorId from cookie', existing);
            return existing;
        }

        let newId = null;

        try {
            // Prefer a wrapper if you've loaded it.
            if (window.SparxstarFingerprint && typeof window.SparxstarFingerprint.getId === 'function') {
                newId = await window.SparxstarFingerprint.getId();
            } else if (window.FingerprintJS) {
                const fp = await window.FingerprintJS.load();
                const result = await fp.get();
                newId = result.visitorId;
            }
        } catch (e) {
            log('FingerprintJS visitorId collection failed', e.message);
        }

        if (!newId) {
            newId = safeRandomId('spx_vid_');
            log('VisitorId fallback generated', newId);
        }

        // Persist as first-party cookie (statistics consent already granted).
        setCookie(COOKIE_KEY, newId, 365);
        return newId;
    };

    const getDeviceDetails = async () => {
        try {
            const dd = window.SPARXSTAR.DeviceDetector;
            if (!dd || typeof dd.getDeviceInfo !== 'function') {
                log('DeviceDetector not available for deviceDetails');
                return null;
            }
            const info = dd.getDeviceInfo();
            log('Device details collected', info);
            return info || null;
        } catch (e) {
            log('Device details collection failed', e.message);
            return null;
        }
    };

    const getIpAddress = async () => {
        // Provided by server-side localization (never fetched client-side).
        const ip = (window.sparxstarUserEnvData && window.sparxstarUserEnvData.ip_address) || null;
        log('IP address (server-provided) collected', ip ? '[present]' : '[none]');
        return ip;
    };

    async function collectIdentifyingData() {
        const results = await Promise.allSettled([
            getVisitorId(),
            getDeviceDetails(),
            getIpAddress()
        ]);

        const identifiers = {
            visitorId: results[0].status === 'fulfilled' ? results[0].value : null,
            deviceDetails: results[1].status === 'fulfilled' ? results[1].value : null,
            ipAddress: results[2].status === 'fulfilled' ? results[2].value : null
        };
        log('Identifiers consolidated', identifiers);
        return identifiers;
    }

    window.SPARXSTAR.Collectors = {
        collectTechnicalData,
        collectIdentifyingData
    };

})(window, document);
