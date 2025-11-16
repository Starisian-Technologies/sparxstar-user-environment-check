/**
 * @file sparxstar-state.js
 * @version 2.0.0
 * @description Defines the canonical SPARXSTAR global state object. This is the single
 * source of truth for all environment data. The integrator will freeze key branches
 * after initialization to enforce immutability.
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    /**
     * Global state container.
     *
     * - technical: Always-on, non-personal environment data required for functionality
     *   and adaptive behavior. Includes a functional sessionId scoped to the browser session.
     * - identifiers: Consent-gated, potentially identifying data used for analytics,
     *   diagnostics, and fraud prevention.
     * - privacy: Current privacy and consent status.
     */
    window.SPARXSTAR.State = {
        technical: {
            raw: {
                device: {},
                network: {},
                browser: {},
                battery: {},
                performance: {},
                sessionId: null
            },
            profile: {
                deviceClass: 'unknown',    // 'low_end', 'midrange', 'modern'
                networkProfile: 'unknown', // 'offline', 'intermittent', 'stable', 'degraded'
                overallProfile: 'unknown'  // 'offline_first', 'limited_capability', 'high_capability'
            }
        },
        identifiers: {
            // NOTE: sessionId is also mirrored here for internal organization,
            // but its collection is treated as functional (not consent-gated).
            sessionId: null,
            ipAddress: null,
            visitorId: null,
            deviceDetails: null // { brand, model, os, client, ... }
        },
        privacy: {
            consentGiven: false,
            consentCategories: [] // e.g. ['statistics']
        }
    };

})(window);
