/**
 * SPARXSTAR User Environment Check | Device Detector (device-detector-js wrapper)
 *
 * @file      sparxstar-user-env-check-devicedetector
 * @since     0.1.1
 * @version   0.1.1
 * @license   Proprietary. Starisian Technologies
 */
(function (window, document) {
	'use strict';

	const Logger = window.SPARXSTAR?.Logger || console;
	const DeviceDetectorLib = window.DeviceDetector;

	if (typeof DeviceDetectorLib === 'undefined') {
		Logger.error('[SparxstarDeviceDetector] device-detector-js not found. Ensure it is loaded before this file.');
		return;
	}

	let detectorInstance = null;
	let parsedDeviceInfo = null;

	function getInstance() {
		if (!detectorInstance) {
			try {
				detectorInstance = new DeviceDetectorLib({
					skipBotDetection: true,
					versionTruncation: 2
				});
				if (sparxstarUserEnvData?.debug) {
					Logger.debug('[SparxstarDeviceDetector] Initialized.');
				}
			} catch (e) {
				Logger.error('[SparxstarDeviceDetector] Init failed', e);
				detectorInstance = null;
			}
		}
		return detectorInstance;
	}

	function getDeviceInfo(userAgent = navigator.userAgent) {
		if (parsedDeviceInfo) {
			return parsedDeviceInfo;
		}
		const detector = getInstance();
		if (!detector) {
			return null;
		}
		try {
			parsedDeviceInfo = detector.parse(userAgent);
			if (sparxstarUserEnvData?.debug) {
				Logger.debug('[SparxstarDeviceDetector] Device info parsed', parsedDeviceInfo);
			}
			return parsedDeviceInfo;
		} catch (e) {
			Logger.error('[SparxstarDeviceDetector] UA parse failed', e);
			return null;
		}
	}

	// Expose API globally
	window.SPARXSTAR = window.SPARXSTAR || {};
	window.SPARXSTAR.DeviceDetector = {
		getDeviceInfo,
		getParsed: () => parsedDeviceInfo
	};

	// Auto-parse and dispatch event once
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () => dispatchDeviceEvent());
	} else {
		dispatchDeviceEvent();
	}

	function dispatchDeviceEvent() {
		const info = getDeviceInfo();
		if (info) {
			document.dispatchEvent(new CustomEvent('sparxstar-device-ready', { detail: info }));
		}
	}

})(window, document);
