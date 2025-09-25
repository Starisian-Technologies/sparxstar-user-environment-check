/**
 * @file Client-side script for SPARXSTAR Global Helper Methods.
 * @author Starisian Technologies (Max Barrett)
 * @version 1.0.0
 * @since 1.0.0
 * @license GLP-3.0-or-later
 *
 * @description This script exposes a few common utility methods globally for easy access
 * from other scripts or the console, under the window.SPARXSTAR.Utils namespace.
 */

(function() {
    'use strict';

    const Logger = window.SPARXSTAR?.Logger || console; // Fallback to console if Logger isn't ready

    // Ensure core modules are loaded
    if (!window.SPARXSTAR?.DeviceDetector || !window.SPARXSTAR?.NetworkMonitor || !window.SPARXSTAR?.EnvCheck) {
        Logger.warn('Core SPARXSTAR modules not fully loaded, some global utilities might be unavailable.');
    }

    /**
     * Retrieves the client's current IP address (as seen by the browser).
     * Note: This is typically the local network IP or an IP from a WebRTC connection.
     * For the *actual* public IP, server-side detection is required.
     * @returns {string} The client IP string, or 'unknown'.
     */
    function getUserIP() {
        // Client-side JS cannot reliably get the *public* IP directly.
        // It can only see the local IP (e.g., 192.168.x.x) or IP from a WebRTC peer.
        // The most common practical way to get the *public* IP is to have the server
        // include it in the localized script data or query a public IP API.
        // For demonstration, we'll return a placeholder or the session ID.
        Logger.warn('Client-side getUserIP is limited. For public IP, check server-side reports.');
        return window.SPARXSTAR?.EnvCheck?.getSessionId() || 'unknown'; // Return session ID as a unique client identifier
    }

    /**
     * Retrieves the current device type.
     * @returns {string} The device type (e.g., "desktop", "mobile", "tablet"), or "unknown".
     */
    function getDeviceType() {
        const deviceInfo = window.SPARXSTAR?.DeviceDetector?.getDeviceInfo();
        return deviceInfo?.device?.type || 'unknown';
    }

    /**
     * Retrieves the current browser name.
     * @returns {string} The browser name (e.g., "Chrome", "Firefox"), or "unknown".
     */
    function getBrowserName() {
        const deviceInfo = window.SPARXSTAR?.DeviceDetector?.getDeviceInfo();
        return deviceInfo?.client?.name || 'unknown';
    }

    /**
     * Checks if the browser is currently online.
     * @returns {boolean} True if online, false if offline.
     */
    function isOnline() {
        return window.SPARXSTAR?.NetworkMonitor?.isOnline() || false;
    }

    /**
     * Gets the effective connection type.
     * @returns {string} Effective connection type (e.g., "4g", "3g", "slow-2g"), or "unknown".
     */
    function getEffectiveConnectionType() {
        const networkInfo = window.SPARXSTAR?.NetworkMonitor?.getNetworkInfo();
        return networkInfo?.effectiveType || 'unknown';
    }

    // Expose utility methods globally under the SPARXSTAR.Utils namespace
    window.SPARXSTAR = window.SPARXSTAR || {};
    window.SPARXSTAR.Utils = {
        getUserIP: getUserIP,
        getDeviceType: getDeviceType,
        getBrowserName: getBrowserName
