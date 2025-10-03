![untitled-10-1536x864](https://github.com/user-attachments/assets/e1a42278-ba41-4c6f-8233-859de5267d1d)

# SPARXSTAR User Environment Check

A foundational, network-wide WordPress utility that captures detailed, client-side browser and device diagnostics. It uses a database-first architecture, a high-performance caching layer, and a clean developer API to make rich user environment data available across your entire platform.

[![Copilot](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/copilot-swe-agent/copilot/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/copilot-swe-agent/copilot) [![CodeQL](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/github-code-scanning/codeql/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/github-code-scanning/codeql) [![Dependabot Updates](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/dependabot/dependabot-updates/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/dependabot/dependabot-updates)

[![Proof HTML, Lint JS & CSS](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/proof-html-js-css.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/proof-html-js-css.yml) [![Security Checks](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/security.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/security.yml) [![Tests](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/test.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/test.yml)

[![Release Code Quality Final Review](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/release.yml/badge.svg)](https://github.com/Starisian-Technologies/sparxstar-user-environment-check/actions/workflows/release.yml)

---

## Overview

This plugin is designed to be an "always-on" service, ideally installed as a Must-Use (MU) plugin. On each user's first visit of the day, it collects a detailed "snapshot" of their technical environment using powerful client-side libraries. This data is stored efficiently in a custom database table and made available to other plugins and themes through a hyper-efficient, cached utility class.

It solves the problem of unreliable, server-side user-agent guessing by trusting accurate, client-side detection.

## Key Features

-   **Accurate Client-Side Detection:** Utilizes `device-detector-js` for precise device, OS, and browser identification, and the Network Information API for real-time bandwidth insights.
-   **Database-First Architecture:** All snapshots are stored in a custom, optimized database table (`wp_sparxstar_env_snapshots`), not flat files.
-   **Efficient Storage:** A smart hashing system prevents duplicate snapshots from being stored, saving significant database space.
-   **Secure REST API Endpoint:** A dedicated endpoint handles the secure ingestion of diagnostic data, complete with nonce validation and rate-limiting.
-   **High-Performance Caching Layer:** A production-ready utility class (`StarUserEnv`) serves snapshot data from a cache, hitting the database at most once per user session.
-   **Scalable by Design:** The caching layer defaults to PHP sessions but can be switched to a persistent object cache (Redis, Memcached) with a single line of code, making it ready for high-traffic, multi-server environments.
-   **Automated Cleanup:** Uses Action Scheduler to reliably run a daily cron job that prunes old data, keeping your database lean.
-   **Clean Developer API:** Provides simple, globally-accessible static methods (e.g., `StarUserEnv::get_browser_name()`) for any other plugin or theme to use.
-   **Browser Compatibility Banner:** Includes an optional, user-dismissible banner to notify users of outdated browsers.

## Installation

### Recommended: As a Must-Use (MU) Plugin

This method ensures the plugin is always active and cannot be accidentally deactivated.

1.  Place the entire `sparxstar-user-environment-check` plugin folder into your `/wp-content/mu-plugins/` directory.
2.  That's it! The plugin is now active across your entire WordPress installation. The database table will be created automatically on the next page load.

### Alternative: As a Standard Plugin

This method is ideal for testing on a single site or if you prefer to manage it from the standard Plugins screen.

1.  Place the entire `sparxstar-user-environment-check` plugin folder into your `/wp-content/plugins/` directory.
2.  Navigate to your WordPress Admin dashboard.
3.  Go to the "Plugins" page.
4.  Find "SPARXSTAR User Environment Check" and click **Activate**.
5.  _(For Multisite)_ Go to `Network Admin > Plugins` and click **Network Activate**.

## Usage (Developer API)

To access user environment data from another plugin or your theme's `functions.php`, use the static methods provided by the `\Starisian\SparxstarUEC\StarUserEnv` class. The data is served from a cache, so these calls are extremely fast.

### PHP Examples

```php
// Always check if the class exists to avoid errors if the plugin is disabled.
if ( class_exists('\Starisian\SparxstarUEC\StarUserEnv') ) {

    // Get the browser name (e.g., "Chrome", "Firefox")
    $browser_name = \Starisian\SparxstarUEC\StarUserEnv::get_browser_name();

    // Get the full OS details
    $os_info = \Starisian\SparxstarUEC\StarUserEnv::get_os();
    // $os_info is an array like ['name' => 'Windows', 'version' => '10', 'platform' => 'x64']

    // Get the device type (e.g., "desktop", "smartphone", "tablet")
    $device_type = \Starisian\SparxstarUEC\StarUserEnv::get_device_type();

    // Get the network bandwidth (e.g., "4g", "3g", "slow-2g")
    // This is perfect for deciding whether to load high-res assets.
    $network_bandwidth = \Starisian\SparxstarUEC\StarUserEnv::get_network_effective_type();

    if ( '4g' !== $network_bandwidth ) {
        // User has a slower connection, maybe load a smaller image.
    }

    // Get the user's public IP address
    $ip_address = \Starisian\SparxstarUEC\StarUserEnv::get_ip_address();

    // Get geolocation data (requires another plugin to hook into the geolocation filter)
    $location = \Starisian\SparxstarUEC\StarUserEnv::get_location();
    $country = $location['country'] ?? 'Unknown';

}

```

## Client-Side JavaScript API

After the DOMContentLoaded event, a global SPARXSTAR object is available with a central state and simple utility functions.

```javascript
// Get the device type directly from the pre-populated state object.  const deviceType = SPARXSTAR.State.device.type;
// Or use the simple utility function.
const networkBandwidth = SPARXSTAR.Utils.getNetworkBandwidth();
// "4g"
console.log(
    `User is on a ${deviceType} with a ${networkBandwidth} connection.`
);
```

## Advanced Configuration

You can tune the caching behavior by adding filters to your wp-config.php file or a custom functionality plugin.

### Switching to a Persistent Object Cache (Redis/Memcached)

For high-traffic, multi-server sites, switching to the WordPress Object Cache is highly recommended.

```php

// In wp-config.php or a custom MU-plugin
add_filter( 'sparxstar_env_cache_handler', function( $handler ) {
    // Check if a persistent object cache is actually in use.
    if ( wp_using_ext_object_cache() ) {
          return 'object_cache';
    }

    // Fall back to the default ('session') if no persistent cache is active.
    return $handler;
} );   `

```

### Tuning Cache Durations (TTLs)

```php
// In wp-config.php or a custom MU-plugin
// Tune the snapshot cache TTL to 5 minutes for more frequent updates.
add_filter( 'sparxstar_env_cache_ttl', function( $ttl_in_seconds ) {      return 5 * MINUTE_IN_SECONDS;  } );

// Tune the geolocation cache TTL to 24 hours, as it rarely changes.
add_filter( 'sparxstar_env_geolocation_ttl', function( $ttl_in_seconds ) {      return 24 * HOUR_IN_SECONDS;  } );   `

```

## Plugin Architecture

The plugin is built on a clean, decoupled architecture where each class has a single responsibility.

-   sparxstar-user-environment-check.php: **The Loader** - The main plugin file WordPress sees. It defines constants, registers hooks, and initializes the orchestrator.
-   src/SparxstarUserEnvironmentCheck.php: **The Orchestrator** - The central "brain" that loads and initializes all other components.
-   src/api/SparxstarUECAPI.php: **The Writer** - Handles the REST API endpoint, database interactions, and the data cleanup cron job.
-   src/StarUserEnv.php: **The Reader** - Provides the public, cached API (get_browser_name(), etc.) for other plugins to use.
-   src/AssetManager.php: **The Asset Loader** - Manages the enqueuing of all CSS and JavaScript files with correct dependencies.

## Filters Reference

-   sparxstar_env_cache_handler (string): Change the caching backend. Accepts 'session' (default) or 'object_cache'.
-   sparxstar_env_cache_ttl (int): Sets the cache duration in seconds for the main snapshot. Default is 900 (15 minutes).
-   sparxstar_env_geolocation_ttl (int): Sets the cache duration in seconds for geolocation data. Default is 21600 (6 hours).
-   sparxstar_env_geolocation_lookup (array|null, string $ip): Allows another plugin to provide geolocation data for a given IP address.
-   sparxstar_env_retention_days (int): Sets the number of days to keep snapshots in the database. Default is 30.

## FAQ

**Q: What happens if I don't have a WP Consent API plugin installed?**  
A: The plugin is designed to be "privacy-first." If the `wp_has_consent()` function does not exist, the plugin will **not** enqueue its scripts or log any data. To enable logging without a consent plugin (e.g., for an internal tool where all users have implicitly consented), you can use the `sparxstar_userenv_consent_category` filter to bypass the check. _This is not recommended for public sites._

**Q: Why a Must-Use (MU) plugin?**  
A: As an environment and diagnostics tool, it should run consistently across an entire network without the risk of being deactivated on a site-by-site basis. The MU-plugin approach ensures it is always active.

**Q: How much of a performance impact does this have?**  
A: Minimal. The client-side script is small and uses modern, efficient APIs like `Promise.allSettled`. The server-side logging is rate-limited to one request per user per day and writes to a simple file, avoiding database queries.
