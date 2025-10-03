/**
 * SPARXSTAR User Environment Check | FingerprintJS Wrapper
 *
 * @file      sparxstar-user-env-check-fingerprintjs
 * @since     0.1.1
 * @version   0.1.1
 * @license   Proprietary. Starisian Technologies
 *
 * This wrapper integrates FingerprintJS with the Environment Check plugin.
 * It ensures a consistent visitorId is available for diagnostics and form state.
 */
(function(window, document) {
    'use strict';

    if (window.SparxstarFingerprint) return;

    const SparxstarFingerprint = {
        fp: null,
        visitorId: null,
        ready: false,

        async init() {
            try {
                // Load FingerprintJS if not already bundled
                if (typeof FingerprintJS === 'undefined') {
                    await new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = 'https://cdn.jsdelivr.net/npm/@fingerprintjs/fingerprintjs@4/dist/fp.min.js';
                        script.async = true;
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.appendChild(script);
                    });
                }

                this.fp = await FingerprintJS.load();
                const result = await this.fp.get();

                this.visitorId = result.visitorId;
                this.ready = true;

                console.log('[SparxstarFingerprint] Fingerprint captured:', this.visitorId);

                // Broadcast event for other modules (Sky, forms, logger)
                document.dispatchEvent(new CustomEvent('sparxstar-fingerprint-ready', {
                    detail: { visitorId: this.visitorId, components: result.components }
                }));

                this.pushToServer(result);

            } catch (e) {
                console.error('[SparxstarFingerprint] Init failed', e);
            }
        },

        async pushToServer(result) {
            const url = sparxstarUserEnvData?.rest_urls?.fingerprint;
            if (!url) {
                console.warn('[SparxstarFingerprint] Missing localized fingerprint URL');
                return;
            }

            const payload = {
                visitorId: this.visitorId,
                components: result.components
            };

            if (typeof wp !== 'undefined' && wp.apiFetch) {
                wp.apiFetch({
                    path: url.replace(/.*\/wp-json\//, ''), // strip wp-json/ prefix for apiFetch
                    method: 'POST',
                    data: payload
                }).catch(err => console.warn('[SparxstarFingerprint] REST push failed', err));
            } else {
                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': sparxstarUserEnvData?.nonce || ''
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                }).catch(err => console.warn('[SparxstarFingerprint] REST push failed', err));
            }
        },

        getId() {
            return this.visitorId;
        }
    };

    window.SparxstarFingerprint = SparxstarFingerprint;

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => SparxstarFingerprint.init());
    } else {
        SparxstarFingerprint.init();
    }

})(window, document);
