/**
 * SPARXSTAR User Environment Check
 *
 * Bridge between a page's Identity Node session and the shared Device Trust
 * client. Loaded only when the Device Trust adapter is enabled.
 *
 * This file holds no identity logic. It waits for the page's Identity
 * integration to announce a session, then lets the shared client enrol the
 * device, prove it, and fetch this product's capability view. It never reads
 * a WordPress user ID, a nonce, a cookie, or an IP address.
 *
 * Integration contract for the page (Identity login code):
 *   document.dispatchEvent(new CustomEvent('sparxstar:identity-session', {
 *     detail: { accountId, getAccessToken: () => Promise<string> }
 *   }));
 *   document.dispatchEvent(new CustomEvent('sparxstar:identity-signed-out', {
 *     detail: { accountId }
 *   }));
 *
 * Emits:
 *   'sparxstar:device-capabilities' { detail: { view | null, offline: bool, profile } }
 *   'sparxstar:device-assertion'    { detail: { assertion, status } } — for the
 *     Identity integration to forward to Identity Node. Never inspect it here.
 *
 * @module sparxstar-device-trust-bridge
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */
(function (window, document) {
    'use strict';

    const cfg = window.sparxstarDeviceTrustConfig;
    const lib = window.SparxstarDeviceTrust;
    if (!cfg || !lib || !cfg.baseUrl || !cfg.issuer) {
        return;
    }

    const keyStore =
        'indexedDB' in window
            ? new lib.IndexedDbKeyStore(window.indexedDB)
            : new lib.MemoryKeyStore();
    let busy = false;

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail }));
    }

    function localProfile() {
        const env = lib.collectEnvironment();
        return lib.deriveProfile({
            online: env.network.online,
            effectiveType: env.network.effectiveType,
            hardwareConcurrency: env.performance.hardwareConcurrency,
            deviceMemory: env.performance.deviceMemory,
        });
    }

    async function onSession(event) {
        const detail = event && event.detail;
        if (
            !detail ||
            typeof detail.accountId !== 'string' ||
            typeof detail.getAccessToken !== 'function'
        ) {
            return;
        }
        if (busy) {
            return;
        }
        busy = true;
        try {
            // Constructed inside the try so a throwing constructor still
            // reaches `finally` and never leaves the bridge locked.
            const client = new lib.DeviceTrustClient({
                baseUrl: cfg.baseUrl,
                issuer: cfg.issuer,
                getAccessToken: detail.getAccessToken,
                keyStore: keyStore,
            });
            await client.ensureEnrolled(
                detail.accountId,
                document.title ? 'Browser: ' + document.title.slice(0, 80) : 'Browser'
            );
            const proof = await client.assertion(detail.accountId);
            if (proof) {
                emit('sparxstar:device-assertion', proof);
            }
            const view = await client.capabilities(detail.accountId);
            emit('sparxstar:device-capabilities', {
                view: view,
                offline: false,
                profile: view ? view.profile : localProfile(),
            });
        } catch (err) {
            // Offline or refused: degrade to the local profile; never block the page.
            emit('sparxstar:device-capabilities', {
                view: null,
                offline: err instanceof lib.DeviceTrustOffline,
                profile: localProfile(),
            });
        } finally {
            busy = false;
        }
    }

    async function onSignedOut(event) {
        const detail = event && event.detail;
        if (detail && typeof detail.accountId === 'string' && cfg.mode !== 'off') {
            // Local forget only; server-side revocation is an explicit user act.
            try {
                await new lib.DeviceTrustClient({
                    baseUrl: cfg.baseUrl,
                    issuer: cfg.issuer,
                    getAccessToken: () => Promise.reject(new Error('signed out')),
                    keyStore: keyStore,
                }).forget(detail.accountId);
            } catch {
                // Nothing to forget.
            }
        }
    }

    document.addEventListener('sparxstar:identity-session', onSession);
    document.addEventListener('sparxstar:identity-signed-out', onSignedOut);
})(window, document);
