/**
 * @file SPARXSTAR Global State and Utility Methods.
 * @description Establishes a central data store (SPARXSTAR.State), exposes simple getters,
 * and (optionally) syncs state deltas to the server REST endpoint.
 */
(function () {
	'use strict';

	// Namespace
	window.SPARXSTAR = window.SPARXSTAR || {};
	const Logger     = window.SPARXSTAR?.Logger || console;

	/**
	 * Single source of truth for client-side environment data (session-scoped).
	 */
	window.SPARXSTAR.State = {
		ipAddress: 'unknown',
		sessionId: 'unknown',
		userAgent: 'unknown',
		device: { type: 'unknown', brand: 'unknown', model: 'unknown', full: null },
		network: { isOnline: false, effectiveType: 'unknown', type: 'unknown', full: null }
	};

	/**
	 * Initialize global state once (called by main.js after DOM is ready).
	 * DeviceDetector/NetworkMonitor must be loaded before this runs.
	 */
	function initializeState() {
		const State          = window.SPARXSTAR.State;
		const DeviceDetector = window.SPARXSTAR.DeviceDetector;
		const NetworkMonitor = window.SPARXSTAR.NetworkMonitor;
		const SparxstarUserEnv       = window.SPARXSTAR.SparxstarUserEnv;

		// Server-provided IP; session ID from SparxstarUserEnv; UA from navigator
	State.ipAddress = window.sparxstarUserEnvData?.ip_address || 'unknown';
	State.sessionId = SparxstarUserEnv?.getSessionId() || 'unknown';
		State.userAgent = navigator.userAgent;

		// One-time device parse (expensive → do once)
		if (DeviceDetector) {
			const info = DeviceDetector.getDeviceInfo();
			if (info) {
				State.device.type  = info.device?.type || 'desktop';
				State.device.brand = info.device?.brand || 'unknown';
				State.device.model = info.device?.model || 'unknown';
				State.device.full  = info;
			}
		} else {
			Logger.warn( '[SPARXSTAR] DeviceDetector not found during initialization.' );
		}

		// Seed network info
		if (NetworkMonitor) {
			const net                   = NetworkMonitor.getNetworkInfo();
			State.network.isOnline      = net.online;
			State.network.effectiveType = net.effectiveType;
			State.network.type          = net.type;
			State.network.full          = net;
		} else {
			Logger.warn( '[SPARXSTAR] NetworkMonitor not found during initialization.' );
		}

		Logger.info( '[SPARXSTAR] Global state initialized.', { state: State } );
	}

	/**
	 * Optional: sync any state delta to server.
	 * Callers pass the *minimal* delta object (e.g., { network: {...} }).
	 */
	async function syncStateToServer(delta) {
	const rest  = window.sparxstarUserEnvData?.rest_url;
	const nonce = window.sparxstarUserEnvData?.nonce;
		if ( ! rest || ! nonce) {
			Logger.debug( '[SPARXSTAR] REST not configured; delta not synced.', { delta } );
			return;
		}
		try {
			const body = {
				sessionId: window.SPARXSTAR.State.sessionId,
				delta
			};
			const rsp  = await fetch(
				rest,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					credentials: 'same-origin',
					body: JSON.stringify( body )
				}
			);
			if ( ! rsp.ok) {
				throw new Error( `HTTP ${rsp.status}` );
			}
			Logger.debug( '[SPARXSTAR] Delta synced.', delta );
		} catch (err) {
			Logger.warn( '[SPARXSTAR] Delta sync failed.', { err: err.message } );
		}
	}

	// Simple, zero-cost global getters
	window.SPARXSTAR.Utils = {
		getIpAddress:        () => window.SPARXSTAR.State.ipAddress,
		getSessionId:        () => window.SPARXSTAR.State.sessionId,
		getUserAgent:        () => window.SPARXSTAR.State.userAgent,
		getDeviceType:       () => window.SPARXSTAR.State.device.type,
		getNetworkStatus:    () => (window.SPARXSTAR.State.network.isOnline ? 'online' : 'offline'),
		getNetworkBandwidth: () => window.SPARXSTAR.State.network.effectiveType
	};

	// Expose init + sync so main.js/network.js can call them
	window.SPARXSTAR.initializeState   = initializeState;
	window.SPARXSTAR.syncStateToServer = syncStateToServer;
})();
