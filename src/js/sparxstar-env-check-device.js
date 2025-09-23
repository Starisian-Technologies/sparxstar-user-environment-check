/**
 * @file Client-side script for SPARXSTAR Device Detection.
 * @author Starisian Technologies (Max Barrett)
 * @version 1.0
 *
 * @description This script integrates device-detector-js to parse the user agent
 * and provide detailed device, OS, and browser information.
 * It exposes methods globally under window.SPARXSTAR.DeviceDetector.
 */

(function() {
    'use strict';

    const Logger = window.SPARXSTAR?.Logger || console; // Fallback to console if Logger isn't ready

    // Ensure DeviceDetector is available. If you're using npm and a bundler,
    // you'd typically import it here:
    // import DeviceDetector from "device-detector-js";
    // For a standalone script without a bundler, assume it's global.
    if (typeof DeviceDetector === 'undefined') {
        Logger.error('device-detector-js library not found. Please ensure it is loaded.');
        return;
    }

    let deviceDetectorInstance;

    /**
     * Initializes the DeviceDetector instance.
     * @returns {DeviceDetector} The initialized DeviceDetector instance.
     */
    function getDeviceDetectorInstance() {
        if (!deviceDetectorInstance) {
            try {
                // You can add options here, e.g., { skipBotDetection: true }
                deviceDetectorInstance = new DeviceDetector({
                    skipBotDetection: true, // Typically, we don't need client-side bot detection for user environment
                    versionTruncation: 2 // Show major and minor versions (e.g., X.Y)
                });
                Logger.debug('device-detector-js initialized.');
            } catch (e) {
                Logger.error('Failed to initialize device-detector-js', { error: e.message });
                deviceDetectorInstance = null; // Ensure it's null on failure
            }
        }
        return deviceDetectorInstance;
    }

    /**
     * Parses the current user agent and returns detailed device information.
     * @param {string} userAgent - The user agent string to parse. Defaults to navigator.userAgent.
     * @returns {object|null} An object containing client, os, and device information, or null if parsing fails.
     */
    function getDeviceInfo(userAgent = navigator.userAgent) {
        const detector = getDeviceDetectorInstance();
        if (!detector) {
            return null;
        }

        try {
            const device = detector.parse(userAgent);
            Logger.debug('Device information parsed', { device });
            return device;
        } catch (e) {
            Logger.error('Failed to parse user agent with device-detector-js', { userAgent, error: e.message });
            return null;
        }
    }

    // Expose methods globally under the SPARXSTAR namespace
    window.SPARXSTAR = window.SPARXSTAR || {};
    window.SPARXSTAR.DeviceDetector = {
        getDeviceInfo: getDeviceInfo,
        // Potentially expose the raw instance if needed for advanced usage
        // getInstance: getDeviceDetectorInstance
    };

    Logger.info('SPARXSTAR DeviceDetector module loaded.');

    // Optionally, parse and log immediately for debugging
    if (window.envCheckData?.debug) {
        Logger.debug('Initial device info:', getDeviceInfo());
    }

})();
