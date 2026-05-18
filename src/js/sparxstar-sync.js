/**
 * SPARXSTAR User Environment Check
 *
 * Transport module responsible for authenticated snapshot submission to the
 * plugin REST API.
 *
 * @module sparxstar-sync
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    const localized = window.sparxstarUserEnvData || {};
    const nonce = localized.nonce || '';
    const restUrls = localized.rest_urls || {};
    const debug = !!localized.debug;

    const log = (msg, data) => {
        if (debug && window.console && console.debug) {
            console.debug('[SPARXSTAR Sync]', msg, data || '');
        }
    };

    function send(endpointUrl, payload) {
        if (!endpointUrl) return log('Missing endpoint URL.');
        if (!nonce) return log('Missing nonce.');

        const json = JSON.stringify(payload || {});

        fetch(endpointUrl, {
            method: 'POST',
            // -------------------------------------------------------
            // 1. AUTHENTICATION FIX (Admin View)
            // -------------------------------------------------------
            credentials: 'same-origin',

            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: json,
            keepalive: true,
        })
            .then((response) => {
                if (!response.ok) {
                    console.error(
                        '[SPARXSTAR Sync] Server Error:',
                        response.status,
                        response.statusText
                    );
                }
                return response.json();
            })
            .then((data) => {
                log('Sent successfully.', { endpointUrl, serverResponse: data });
            })
            .catch((err) => {
                console.error('[SPARXSTAR Sync] Fetch failed:', err);
                log('Fetch failed.', err.message);
            });
    }

    /**
     * Formats the payload to match the PHP Controller v2.1 structure.
     * PHP expects: $payload['client_side_data']['identifiers']...
     */
    function wrapForController(fingerprint, deviceHash, sessionId, customData) {
        return {
            client_side_data: {
                identifiers: {
                    fingerprint: fingerprint || '',
                    session_id: sessionId || '',
                    // Note: Device Hash is recalculated by PHP v2.1 using Client Hints,
                    // but we send it here for completeness/legacy support.
                    device_hash: deviceHash || '',
                },
                // Merge the specific technical/identity data into the main object
                ...customData,
            },
        };
    }

    function sendTechnicalSnapshot(fingerprint, deviceHash, sessionId, technicalData) {
        if (!restUrls.technical) {
            return log('Technical endpoint missing.');
        }

        // -------------------------------------------------------
        // 2. STRUCTURE FIX (PHP Compatibility)
        // Wrap data in 'client_side_data' so PHP finds it.
        // -------------------------------------------------------
        const payload = wrapForController(fingerprint, deviceHash, sessionId, {
            technical: technicalData || {},
        });

        send(restUrls.technical, payload);
    }

    function sendIdentifyingSnapshot(fingerprint, deviceHash, sessionId, identifiersData) {
        if (!restUrls.identifiers) {
            return log('Identifiers endpoint missing.');
        }

        const payload = wrapForController(fingerprint, deviceHash, sessionId, {
            identifiers_extra: identifiersData || {},
        });

        send(restUrls.identifiers, payload);
    }

    window.SPARXSTAR.Sync = {
        sendTechnicalSnapshot,
        sendIdentifyingSnapshot,
    };
})(window);
