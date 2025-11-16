/**
 * @file sparxstar-profile.js
 * @version 2.0.0
 * @description Pure, stateless profiling logic that translates raw technical data
 * into actionable device/network/overall profiles.
 */
(function (window) {
    'use strict';

    window.SPARXSTAR = window.SPARXSTAR || {};

    function classifyDevice(performance) {
        const cores  = performance && typeof performance.hardwareConcurrency === 'number'
            ? performance.hardwareConcurrency
            : 1;
        const memory = performance && typeof performance.deviceMemory === 'number'
            ? performance.deviceMemory
            : 0; // GB

        if (memory > 0 && memory <= 2) {
            return 'low_end';
        }
        if (cores <= 2) {
            return 'low_end';
        }
        if (cores <= 4 && memory > 0 && memory <= 4) {
            return 'midrange';
        }
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
        if (networkProfile === 'offline') {
            return 'offline_first';
        }
        if (deviceClass === 'low_end' || networkProfile === 'degraded') {
            return 'limited_capability';
        }
        return 'high_capability';
    }

    /**
     * Derives actionable profiles from the raw technical data.
     *
     * @param {object} rawTechnicalData - The `State.technical.raw` object.
     * @returns {object} The derived profile object.
     */
    function deriveProfile(rawTechnicalData) {
        const performance    = rawTechnicalData && rawTechnicalData.performance || {};
        const network        = rawTechnicalData && rawTechnicalData.network || {};
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
