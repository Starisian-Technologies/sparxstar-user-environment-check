/**
 * SPARXSTAR User Environment Check
 *
 * Stateless profile derivation logic that translates raw telemetry into
 * actionable capability tiers.
 *
 * @module sparxstar-profile
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    /**
     * Classifies the device hardware tier.
     * 
     * LOGIC CHANGE v3.0: 
     * We no longer default to 'low_end' solely based on core count.
     * We first check RAM. If RAM is > 2GB, the device is at least 'midrange',
     * even if it only has 2 cores (common in office desktops).
     */
    function classifyDevice(performance) {
        const cores  = performance && typeof performance.hardwareConcurrency === 'number'
            ? performance.hardwareConcurrency
            : 1;
        const memory = performance && typeof performance.deviceMemory === 'number'
            ? performance.deviceMemory
            : 0; // GB

        // 1. CRITICAL: True Low End (Africa/Budget Market)
        // If RAM is 2GB or less, the device struggles with modern JS heaps.
        // This is the dominant factor.
        if (memory > 0 && memory <= 2) {
            return 'low_end';
        }

        // 2. Midrange (The "Desktop Fix" Tier)
        // Catches:
        // - Desktops: 2 Cores + 8GB RAM (Previously misclassified)
        // - Phones: 4-6GB RAM
        if (cores <= 2 || (memory > 2 && memory <= 6)) {
            return 'midrange';
        }

        // 3. Modern / High Performance
        // > 2 Cores AND > 6GB RAM
        return 'modern';
    }

    function classifyNetwork(network) {
        if (!network || network.isOnline === false) {
            return 'offline';
        }
        switch (network.effectiveType) {
            case 'slow-2g':
            case '2g':
                return 'degraded';
            case '3g':
                return 'intermittent';
            case '4g':
            case '5g':
                return 'stable';
            default:
                return 'stable';
        }
    }

    function synthesizeProfile(deviceClass, networkProfile) {
        // 1. Offline always takes precedence
        if (networkProfile === 'offline') {
            return 'offline_first';
        }

        // 2. Limited Capability trigger
        // Only triggered by ACTUAL low end hardware (<=2GB RAM) 
        // OR unusable network (2G).
        if (deviceClass === 'low_end' || networkProfile === 'degraded') {
            return 'limited_capability';
        }

        // 3. High Capability
        // Includes 'midrange' and 'modern' devices on 3G/4G/5G.
        // This ensures your desktop (midrange) gets the full experience.
        return 'high_capability';
    }

    /**
     * Derives actionable profiles from the raw technical data.
     *
     * @param {object} rawTechnicalData - The `State.technical.raw` object.
     * @returns {object} The derived profile object.
     */
    function deriveProfile(rawTechnicalData) {
        const performance    = (rawTechnicalData && rawTechnicalData.performance) || {};
        const network        = (rawTechnicalData && rawTechnicalData.network) || {};
        
        const deviceClass    = classifyDevice(performance);
        const networkProfile = classifyNetwork(network);
        const overallProfile = synthesizeProfile(deviceClass, networkProfile);

        return {
            deviceClass: deviceClass,
            networkProfile: networkProfile,
            overallProfile: overallProfile
        };
    }

    window.SPARXSTAR.Profile = {
        deriveProfile
    };

})(window);
