/**
 * @file sparxstar-sync.js
 * @version 2.0.0
 * @description Resilient server communication for snapshots, preferring `sendBeacon`
 * with a `fetch` fallback (keepalive) for maximum reliability (Cloudflare-friendly).
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    const localized = window.sparxstarUserEnvData || {};
    const nonce     = localized.nonce || '';
    const restUrls  = localized.rest_urls || {};
    const debug     = !!localized.debug;

    const log = (msg, data) => {
        if (debug && window.console && console.debug) {
            console.debug('[SPARXSTAR Sync]', msg, data || '');
        }
    };

    function sendData(endpointUrl, payload) {
        if (!endpointUrl || !nonce) {
            log('Missing endpoint URL or nonce; snapshot not sent.', { endpointUrl, hasNonce: !!nonce });
            return;
        }

        const json = JSON.stringify(payload || {});
        const blob = new Blob([json], { type: 'application/json' });

        // Prefer sendBeacon for "fire-and-forget" diagnostics.
        if (navigator.sendBeacon) {
            const ok = navigator.sendBeacon(endpointUrl, blob);
            if (ok) {
                log('Snapshot sent via sendBeacon.', { endpointUrl });
                return;
            }
            log('sendBeacon returned false, falling back to fetch.', { endpointUrl });
        }

        // Fallback: fetch with keepalive.
        fetch(endpointUrl, {
            method: 'POST',
            body: blob,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce
            },
            keepalive: true
        }).then(() => {
            log('Snapshot sent via fetch.', { endpointUrl });
        }).catch((e) => {
            log('Fetch fallback failed.', e.message);
        });
    }

    async function sendTechnicalSnapshot(technicalData) {
        if (!restUrls.technical) {
            log('No technical endpoint configured.');
            return;
        }
        sendData(restUrls.technical, { technical: technicalData });
    }

    async function sendIdentifyingSnapshot(identifiersData) {
        if (!restUrls.identifiers) {
            log('No identifiers endpoint configured.');
            return;
        }
        sendData(restUrls.identifiers, { identifiers: identifiersData });
    }

    window.SPARXSTAR.Sync = {
        sendTechnicalSnapshot,
        sendIdentifyingSnapshot
    };

})(window);
