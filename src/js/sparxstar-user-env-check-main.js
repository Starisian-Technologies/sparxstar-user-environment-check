/**
 * @file Main client-side script for SPARXSTAR User Environment Check.
 * @description Compatibility check, banner, session ID, daily diagnostics, and snapshot send.
 */
(function() {
    'use strict';

    // ---------- Logger ----------
    const Logger = {
        levels: { ERROR: 0, WARN: 1, INFO: 2, DEBUG: 3 },
        currentLevel: window.sparxstarUserEnvData?.debug ? 3 : 1,
        log(level, message, data = null) {
            if (level <= this.currentLevel) {
                const prefix = '[SparxstarUserEnv]';
                const t = new Date().toISOString();
                if (level === 0) return console.error(`${prefix} ${t} ERROR:`, message, data);
                if (level === 1) return console.warn(`${prefix} ${t} WARN:`,  message, data);
                if (level === 2) return console.info(`${prefix} ${t} INFO:`,  message, data);
                return console.debug(`${prefix} ${t} DEBUG:`, message, data);
            }
        },
        error(m,d){this.log(0,m,d);}, warn(m,d){this.log(1,m,d);}, info(m,d){this.log(2,m,d);}, debug(m,d){this.log(3,m,d);}
    };

    // ---------- Local Storage helper ----------
    const LS = {
        get: (k) => { try { return localStorage.getItem(`sparxstaruserenv:${k}`);} catch(e){ Logger.warn('localStorage get failed',{k,e:e.message}); return null;}},
        set: (k,v) => { try { localStorage.setItem(`sparxstaruserenv:${k}`, v);} catch(e){ Logger.warn('localStorage set failed',{k,e:e.message});}}
    };

    // ---------- Env data from PHP ----------
    const { nonce, rest_url, i18n } = window.sparxstarUserEnvData || {};
    if (!window.sparxstarUserEnvData) { Logger.error('sparxstarUserEnvData not found'); return; }
    if (!nonce || !rest_url)  { Logger.error('Missing REST config'); return; }

    // ---------- Session ID (stable in sessionStorage, robust fallback) ----------
    let sessionId;
    try {
        sessionId = sessionStorage.getItem('sparxstaruserenv_session_id');
        if (!sessionId) {
            sessionId = (crypto?.randomUUID?.() ?? ('ses_' + Date.now() + '_' + secureRand(12)));
            sessionStorage.setItem('sparxstaruserenv_session_id', sessionId);
            Logger.debug('New session ID', { sessionId });
        } else {
            Logger.debug('Existing session ID', { sessionId });
        }
    } catch(e){
        sessionId = 'ses_' + Date.now() + '_' + secureRand(12);
        Logger.warn('sessionStorage unavailable; temp session ID used', { sessionId, err: e.message });
    }
    function secureRand(n) {
        const arr = new Uint8Array(n);
        if (crypto?.getRandomValues) { crypto.getRandomValues(arr); return Array.from(arr).map(b=>b.toString(36)).join(''); }
        return Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    // ---------- Compatibility + Banner ----------
    function isBrowserCompatible() {
        return (
            'Promise' in window &&
            'fetch' in window &&
            'MediaRecorder' in window &&
            (navigator.mediaDevices && 'getUserMedia' in navigator.mediaDevices)
        );
    }

    function displayUpgradeBanner() {
        if (LS.get('bannerDismissed') === 'true') return;
        if (isBrowserCompatible()) return;
        const banner = document.createElement('div');
        banner.className = 'sparxstaruserenv-banner';
        banner.innerHTML = `
            <div class="sparxstaruserenv-banner-content">
                <strong>${i18n.notice}</strong> ${i18n.update_message}
                <a href="https://browsehappy.com/" target="_blank" rel="noopener noreferrer">${i18n.update_link}</a>.
            </div>
            <button class="sparxstaruserenv-dismiss" aria-label="${i18n.dismiss}">&times;</button>`;
        document.body.appendChild(banner);
        banner.querySelector('.sparxstaruserenv-dismiss').addEventListener('click', () => {
            banner.remove(); LS.set('bannerDismissed','true');
        });
    }

    // --- NEW: Offline Notification Banner ---
    function displayOfflineBanner() {
        // First, check if a banner is already there to avoid duplicates
        if (document.getElementById('sparxstaruserenv-offline-banner')) return;

        Logger.warn('Displaying offline notification banner.');
        const banner = document.createElement('div');
        banner.id = 'sparxstaruserenv-offline-banner'; // Use an ID for easy removal
        banner.className = 'sparxstaruserenv-banner sparxstaruserenv-banner-offline'; // Add a specific class for styling
        banner.innerHTML = `
            <div class="sparxstaruserenv-banner-content">
                <strong>Connection Offline:</strong> You are currently not connected to the internet.
            </div>`;
        document.body.appendChild(banner);
    }

    function hideOfflineBanner() {
        const banner = document.getElementById('sparxstaruserenv-offline-banner');
        if (banner) {
            Logger.info('Hiding offline notification banner.');
            banner.remove();
        }
    }


    // ---------- Feature + Privacy ----------
    function collectFeatures() {
        const safe = (fn, fallback=false) => { try { return fn(); } catch { return fallback; } };
        return {
            webrtc: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
            webgl: safe(() => {
                const c = document.createElement('canvas');
                return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
            }),
            serviceWorker: 'serviceWorker' in navigator,
            localStorage: 'localStorage' in window, sessionStorage: 'sessionStorage' in window,
            mediaRecorder: 'MediaRecorder' in window, getUserMedia: !!(navigator.mediaDevices?.getUserMedia),
            promise: 'Promise' in window, fetch: 'fetch' in window,
            indexedDB: 'indexedDB' in window, webWorkers: 'Worker' in window,
            pushManager: 'PushManager' in window, notification: 'Notification' in window,
            geolocation: 'geolocation' in navigator, clipboard: 'clipboard' in navigator,
            wakeLock: 'wakeLock' in navigator, bluetooth: 'bluetooth' in navigator, usb: 'usb' in navigator,
            webAssembly: 'WebAssembly' in window,
            intersectionObserver: 'IntersectionObserver' in window, mutationObserver: 'MutationObserver' in window,
            resizeObserver: 'ResizeObserver' in window
        };
    }
    function collectPrivacy() {
        return {
            doNotTrack: (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.msDoNotTrack === '1'),
            gpc: navigator.globalPrivacyControl || false
        };
    }

    // ---------- Env snapshot (now reads from SPARXSTAR.State) ----------
    function collectEnvData() {
        const State = window.SPARXSTAR.State;
        return {
            sessionId: State.sessionId,
            userAgent: State.userAgent,
            language: navigator.language || navigator.userLanguage,
            screen: {
                width: window.screen.width, height: window.screen.height,
                availWidth: window.screen.availWidth, availHeight: window.screen.availHeight,
                pixelDepth: window.screen.pixelDepth, devicePixelRatio: window.devicePixelRatio || 1
            },
            device: State.device.full,
            network: State.network.full,
            features: collectFeatures(),
            privacy: collectPrivacy(),
            compatible: isBrowserCompatible()
        };
    }

    // ---------- Diagnostics (kept from your flow; parallelized queries) ----------
    async function collectDiagnostics() {
        let data = collectEnvData();
        const [storage, mic, battery] = await Promise.allSettled([
            navigator.storage?.estimate?.(),
            navigator.permissions?.query?.({ name: 'microphone' }),
            navigator.getBattery?.(),
        ]);
        if (storage.status === 'fulfilled' && storage.value) data.storage = { quota: storage.value.quota, usage: storage.value.usage };
        if (mic.status === 'fulfilled' && mic.value) data.micPermission = mic.value.state;
        if (battery.status === 'fulfilled' && battery.value) data.battery = { level: battery.value.level, charging: battery.value.charging };

        if (data.privacy.doNotTrack || data.privacy.gpc) {
            data = {
                sessionId: data.sessionId,
                privacy: data.privacy,
                userAgent: data.userAgent,
                compatible: data.compatible,
                features: data.features,
                device: data.device,
                network: data.network
            };
        }
        return data;
    }

    // ---------- Sanitize + Send ----------
    function sanitizeData(data) {
        const sanitized = { ...data };
        ['inputs','formData','cookies','localStorage'].forEach(f => { if (sanitized[f]) delete sanitized[f]; });
        if (sanitized.userAgent) sanitized.userAgent = sanitized.userAgent.replace(/\b\d{2,}\.\d+\.\d+\.\d+\b/g, 'x.x.x.x');
        if (sanitized.latitude && sanitized.longitude) {
            sanitized.latitude  = Math.round(sanitized.latitude  * 100) / 100;
            sanitized.longitude = Math.round(sanitized.longitude * 100) / 100;
        }
        if (sanitized.network && typeof sanitized.network === 'object') {
            const keep = ['online','type','effectiveType','rtt','downlink','downlinkMax','saveData'];
            sanitized.network = Object.fromEntries(Object.entries(sanitized.network).filter(([k]) => keep.includes(k)));
        }
        return sanitized;
    }

    async function sendDiagnostics(payload) {
        const lastSend = LS.get('lastSendTime');
        if (lastSend && (Date.now() - parseInt(lastSend, 10) < 5000)) return; // 5s rate limit
        const body = sanitizeData(payload);
        try {
            const rsp = await fetch(rest_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    'Accept-CH': 'Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Model, Sec-CH-UA-Full-Version'
                },
                body: JSON.stringify(body)
            });
            if (!rsp.ok) throw new Error(`HTTP ${rsp.status}`);
            const json = await rsp.json();
            Logger.info('Diagnostics sent', { snapshot_id: json.snapshot_id });
            LS.set('lastSendTime', Date.now().toString());
        } catch (e) {
            Logger.error('Diagnostics send failed', { error: e.message });
        }
    }

    async function runDiagnosticsOncePerDay() {
        const oneDay = 24 * 60 * 60 * 1000;
        const last = LS.get('lastCheck');
        if (last && (Date.now() - parseInt(last, 10) < oneDay)) return;
        const data = await collectDiagnostics();
        await sendDiagnostics(data);
        LS.set('lastCheck', Date.now().toString());
    }

    function initializeConsentListener() {
        if (!window.wp_consent_api) return;
        document.addEventListener('wp_listen_for_consent_change', (event) => {
            const { consent_changed, new_consent } = event.detail || {};
            if (consent_changed && new_consent && new_consent.includes('statistics')) {
                runDiagnosticsOncePerDay();
            }
        });
    }

    // ---------- Boot ----------
    document.addEventListener('DOMContentLoaded', () => {
        if (!nonce || !rest_url || !i18n) { Logger.error('Missing localization data'); return; }

        // Hydrate central state (requires DeviceDetector & NetworkMonitor loaded)
        if (window.SPARXSTAR?.initializeState) {
            window.SPARXSTAR.initializeState();
        } else {
            Logger.error('Global state initializer missing. Load global.js earlier.');
            return;
        }

        Logger.info('SparxstarUserEnv initialized', { sessionId: window.SPARXSTAR.State.sessionId });
        displayUpgradeBanner();
        window.addEventListener('offline', displayOfflineBanner);
        window.addEventListener('online', hideOfflineBanner);

        // Immediate lightweight snapshot each page load
        sendDiagnostics(collectEnvData());

        // Detailed daily set
        runDiagnosticsOncePerDay();
        initializeConsentListener();
    });

    // Expose for other modules
    window.SPARXSTAR = window.SPARXSTAR || {};
    window.SPARXSTAR.Logger = Logger;
    window.SPARXSTAR.SparxstarUserEnv = {
        collectEnvData,
        collectDiagnostics,
        sendDiagnostics,
        isBrowserCompatible,
        getSessionId: () => sessionId
    };
}
)();
