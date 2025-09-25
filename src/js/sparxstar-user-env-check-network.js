/**
 * @file Client-side script for SPARXSTAR Network Monitoring.
 * @author Starisian Technologies (Max Barrett)
 * @version 1.0
 * @since 1.0.0
 * @license GLP-3.0-or-later
 *
 * @description This script utilizes the Network Information API and navigator.onLine
 * to provide detailed network status and expose methods globally under
 * window.SPARXSTAR.NetworkMonitor.
 */

(function() {
    'use strict';

    const Logger = window.SPARXSTAR?.Logger || console; // Fallback to console if Logger isn't ready

    let networkInfo = {};
    let connectionListenerInitialized = false;

    /**
     * Updates the internal networkInfo object with current network status.
     * @returns {object} The current network information.
     */
    function updateNetworkInfo() {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

        networkInfo = {
            online: navigator.onLine,
            type: conn?.type || 'unknown', // e.g., "wifi", "cellular", "ethernet"
            effectiveType: conn?.effectiveType || 'unknown', // e.g., "slow-2g", "2g", "3g", "4g"
            rtt: conn?.rtt, // Round-trip time in milliseconds
            downlink: conn?.downlink, // Estimated downlink speed in Mbps
            downlinkMax: conn?.downlinkMax, // Max downlink speed in Mbps
            saveData: conn?.saveData, // Boolean if user has data saver enabled
            supported: !!conn // Indicates if Network Information API is supported
        };
        Logger.debug('Network info updated:', networkInfo);
        return networkInfo;
    }

    /**
     * Initializes network listeners for online/offline events and connection changes.
     */
    function initializeNetworkListeners() {
        if (connectionListenerInitialized) {
            return;
        }

        window.addEventListener('online', () => {
            Logger.info('Browser is now online.');
            updateNetworkInfo();
            // Trigger a custom event for other modules to react to if needed
            document.dispatchEvent(new CustomEvent('sparxstar_network_online', { detail: networkInfo }));
        });

        window.addEventListener('offline', () => {
            Logger.warn('Browser is now offline.');
            updateNetworkInfo();
            document.dispatchEvent(new CustomEvent('sparxstar_network_offline', { detail: networkInfo }));
        });

        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn) {
            conn.addEventListener('change', () => {
                Logger.info('Network connection properties changed.');
                updateNetworkInfo();
                document.dispatchEvent(new CustomEvent('sparxstar_network_change', { detail: networkInfo }));
            });
            Logger.debug('Network Information API change listener initialized.');
        } else {
            Logger.warn('Network Information API (navigator.connection) not fully supported.');
        }

        connectionListenerInitialized = true;
        Logger.info('Network listeners initialized.');
    }

    /**
     * Returns the latest collected network information.
     * @returns {object} Current network information.
     */
    function getNetworkInfo() {
        // Ensure network info is updated before returning
        return updateNetworkInfo();
    }

    // Initialize on script load
    updateNetworkInfo();
    initializeNetworkListeners();

    // Expose methods globally under the SPARXSTAR namespace
    window.SPARXSTAR = window.SPARXSTAR || {};
    window.SPARXSTAR.NetworkMonitor = {
        getNetworkInfo: getNetworkInfo,
        isOnline: () => navigator.onLine,
        // You can add methods to listen to changes externally if needed
        // e.g., onNetworkChange: (callback) => { /* add event listener */ }
    };

    Logger.info('SPARXSTAR NetworkMonitor module loaded.');

})();
