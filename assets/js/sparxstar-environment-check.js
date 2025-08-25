/* global envCheckData */
// sparxstar-environment-check.js — consent via WP Consent API; standalone “envcheck” prefixes.

(function () {
  'use strict';

  // -------- Compatibility (runs for everyone; no storage) --------
  const ENVCHECK_MINIMUMS = { Chrome: 49, Firefox: 47, Edge: 79, Safari: 14.1, iOS: 14.3 };
  function hasRequired() {
    return ('MediaRecorder' in window) &&
           (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function') &&
           ('Promise' in window) && ('fetch' in window);
  }
  function browserInfo() {
    const ua = navigator.userAgent; let m;
    if ((m = ua.match(/(Edg|Edge)\/([\d.]+)/))) return { name: 'Edge', version: parseFloat(m[2]) };
    if ((m = ua.match(/Firefox\/([\d.]+)/)))    return { name: 'Firefox', version: parseFloat(m[1]) };
    if ((m = ua.match(/Chrome\/([\d.]+)/)) && !ua.includes('Edg')) return { name: 'Chrome', version: parseFloat(m[1]) };
    if ((m = ua.match(/Version\/([\d.]+).*Safari/))) {
      if (/(iPhone|iPod|iPad)/.test(ua)) return { name: 'iOS', version: parseFloat(m[1]) };
      return { name: 'Safari', version: parseFloat(m[1]) };
    }
    return null;
  }
  function isCompatible() {
    if (!hasRequired()) return false;
    const b = browserInfo(); if (!b) return false;
    const need = ENVCHECK_MINIMUMS[b.name];
    return need ? b.version >= need : false;
  }
  function showBanner() {
    if (localStorage.getItem('envcheckBannerDismissed') === 'true') return;
    const el = document.createElement('div');
    el.id = 'envcheck-banner';
    el.innerHTML = `
      <p><strong>Notice:</strong> Your browser may be outdated. For the best experience, please
      <a href="https://browsehappy.com/" target="_blank" rel="noopener">update your browser</a>.</p>
      <button id="envcheck-dismiss" aria-label="Dismiss">&times;</button>`;
    document.body.appendChild(el);
    document.getElementById('envcheck-dismiss').addEventListener('click', () => {
      el.style.display = 'none';
      localStorage.setItem('envcheckBannerDismissed', 'true');
    });
  }

  // -------- Consent via WP Consent API --------
  const CONSENT_CAT = envCheckData.consent_cat || 'statistics';
  function hasConsent() {
    try {
      return !!(window.wp_consent_api && typeof window.wp_consent_api.hasConsent === 'function'
                && window.wp_consent_api.hasConsent(CONSENT_CAT));
    } catch { return false; }
  }
  function onConsent(fn) {
    if (hasConsent()) return void fn();
    document.addEventListener('wp_listen_for_consent_change', (e) => {
      const d = (e && e.detail) || {};
      const allowed = d[CONSENT_CAT] === true || d[CONSENT_CAT] === 'allow' || hasConsent();
      if (allowed) fn();
    });
  }

  // -------- Data collection (privacy-aware) --------
  async function collect() {
    const o = {};
    o.userAgent = navigator.userAgent || 'N/A';
    o.os = navigator.platform || 'N/A';
    o.browser = browserInfo();
    o.compatible = isCompatible();
    o.deviceType = /mobi|android|iphone/i.test(o.userAgent) ? 'mobile'
                 : /tablet|ipad/i.test(o.userAgent) ? 'tablet' : 'desktop';
    o.lang = navigator.language || null;
    o.languages = navigator.languages || null;
    o.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || null;
    o.cores = navigator.hardwareConcurrency ?? null;
    o.memoryGB = navigator.deviceMemory ?? null;
    o.cookies = navigator.cookieEnabled === true;
    o.screen = { w: screen.width, h: screen.height, dpr: window.devicePixelRatio || 1, depth: screen.colorDepth };
    o.prefers = {
      reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
      darkMode: matchMedia('(prefers-color-scheme: dark)').matches,
      gamutP3: matchMedia('(color-gamut: p3)').matches,
      hdr: matchMedia('(dynamic-range: high)').matches
    };
    if (navigator.connection) {
      o.network = {
        type: navigator.connection.effectiveType || null,
        downlink: navigator.connection.downlink || null,
        rtt: navigator.connection.rtt || null,
        saveData: !!navigator.connection.saveData
      };
    }
    if (navigator.storage?.estimate) {
      try {
        const e = await navigator.storage.estimate();
        o.storage = {
          quotaMB: e.quota ? Math.round(e.quota / 1048576) : null,
          usageMB: e.usage ? Math.round(e.usage / 1048576) : null
        };
      } catch {}
    }
    if (navigator.permissions?.query) {
      try {
        const mic = await navigator.permissions.query({ name: 'microphone' });
        o.permissions = { microphone: mic.state };
      } catch {}
    }
    const canType = typeof MediaRecorder !== 'undefined' && typeof MediaRecorder.isTypeSupported === 'function';
    o.media = {
      opusRecording: canType ? MediaRecorder.isTypeSupported('audio/webm;codecs=opus') : null,
      mp4Recording:  canType ? MediaRecorder.isTypeSupported('audio/mp4') : null
    };
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) { const ac = new AC(); o.audio = { sampleRate: ac.sampleRate, state: ac.state }; await ac.close(); }
    } catch {}
    o.privacy = {
      doNotTrack: (navigator.doNotTrack == '1' || window.doNotTrack == '1') || false,
      gpc: !!navigator.globalPrivacyControl
    };
    if (navigator.userAgentData?.getHighEntropyValues) {
      try {
        const he = await navigator.userAgentData.getHighEntropyValues(['platformVersion','architecture','model','uaFullVersion']);
        o.clientHints = { brands: navigator.userAgentData.brands, mobile: navigator.userAgentData.mobile, platform: navigator.userAgentData.platform, ...he };
      } catch {}
    }
    if (navigator.getBattery) {
      try {
        const b = await navigator.getBattery();
        o.battery = `Level: ${Math.round(b.level * 100)}%, Charging: ${b.charging}`;
      } catch {}
    }
    return o;
  }

  function send(payload) {
    const fd = new FormData();
    fd.append('action', 'envcheck_log');
    fd.append('nonce', envCheckData.nonce);
    fd.append('data', JSON.stringify(payload));
    return fetch(envCheckData.ajax_url, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(j => {
        if (j && j.success) {
          localStorage.setItem('envcheckLastLog', String(Date.now()));
        }
      })
      .catch(() => {});
  }

  // -------- Main --------
  document.addEventListener('DOMContentLoaded', () => {
    if (!isCompatible()) showBanner();

    const day = 24 * 60 * 60 * 1000;
    const last = Number(localStorage.getItem('envcheckLastLog') || 0);
    if (Date.now() - last < day) return;

    onConsent(async () => {
      const data = await collect();
      await send(data);
    });
  });
})();
