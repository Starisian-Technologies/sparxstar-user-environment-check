/**
 * SPARXSTAR User Environment Check
 *
 * Bootstrap entrypoint that exposes required vendor dependencies and loads all
 * first-party runtime modules.
 *
 * @module sparxstar-bootstrap
 * @copyright Copyright (c) 2023-2026, Starisian Technologies
 * @license Proprietary. All Rights Reserved.
 */

// Import vendor libraries from node_modules
import FingerprintJS from '@fingerprintjs/fingerprintjs';
import DeviceDetector from 'device-detector-js';

// Expose fingerprint globally
window.FingerprintJS = FingerprintJS;

// Initialize SPARXSTAR namespace
window.SPARXSTAR = window.SPARXSTAR || {};

// Wrap DeviceDetector with the getDeviceInfo() API expected by collectors
class SparxstarDeviceDetector {
    constructor() {
        this.detector = new DeviceDetector();
    }

    getDeviceInfo() {
        try {
            const ua = navigator.userAgent || '';
            return this.detector.parse(ua);
        } catch (e) {
            if (window.console && console.warn) {
                console.warn('[SPARXSTAR DeviceDetector] parse() failed', e && e.message ? e.message : e);
            }
            return null;
        }
    }
}

// Expose a single shared instance
window.SPARXSTAR.DeviceDetector = new SparxstarDeviceDetector();

// Now import all the IIFE modules
import './sparxstar-state.js';
import './sparxstar-collector.js';
import './sparxstar-profile.js';
import './sparxstar-sync.js';
import './sparxstar-recorder.js';
import './sparxstar-ui.js';
import './sparxstar-integrator.js';
