
# **SparxStar User Environment Check (UEC)**

![SPARXSTAR-User-Environment-Check-1536x864](https://github.com/user-attachments/assets/e1a42278-ba41-4c6f-8233-859de5267d1d)


The **SparxStar User Environment Check (UEC)** plugin collects and analyzes client-side environment data to enhance onboarding, diagnostics, and tier-based experience management across the SparxStar multisite ecosystem. It provides a reliable snapshot of the user’s runtime environment and stores structured metadata server-side, enabling workflow decisions without relying on manual reporting or user comprehension.

UEC operates silently during sessions and supports both authenticated and anonymous contexts.

---

## **Status**

**Development Status:** Stable / Maintenance Mode  
 **Branch Policy:** No new features. Only bug fixes, security updates, and performance improvements will be accepted.  
 **Future Work:** Any enhancements or architectural changes must be developed in a **fork**.

This repository represents the **finalized and production-hardened** implementation.

---

## **Core Features**

* **Environment Profiling**  
   Detects browser, OS, device class, network conditions, memory availability, battery state, and related indicators.

* **Privacy-Aware Data Collection**  
   Uses WP Consent API categories and anonymized metrics. No personally identifiable information is captured.

* **Server-Side Logging Layer**  
   Writes structured environment snapshots to the WordPress backend over authenticated REST endpoints.

* **Tier Classification**  
   Provides configurable logic to gate UI flows, offline modes, and device-dependent features.

* **Extensible Architecture**  
   Other SparxStar modules consume cached data rather than issuing repeated probes.

---

## **Architecture Overview**

### **Frontend**

* Device and capability detection

* Offline-first safe execution

* Consent-aware sampling and throttling

* JSON payload transport to REST endpoints

### **Backend**

* Namespaced PHP codebase (PSR-4)

* Autoloaded service classes for:

  * REST endpoint registration

  * Request validation

  * Metadata persistence and cleanup

### **Data Model**

Captures **non-personal, capability-based** data:

* Device memory tier

* Network `effectiveType`

* Browser engine

* Platform fingerprint (non-unique)

* Potential performance constraints

This informs automated decisions in other plugins without user intervention.

---

## **Use Cases**

* **Artist Onboarding**  
   Determines whether a user’s device can handle audio recording, multi-step forms, or media uploads.

* **Service Eligibility**  
   Routes clients to flows appropriate for their network and device constraints.

* **Support Diagnostics**  
   Removes guesswork by exposing actual client environment.

* **Platform Intelligence**  
   Adapts to West African connectivity realities while scaling globally.

---

## **Installation**

1. Place the plugin in `/wp-content/plugins/`

2. Requires **WordPress 6.0+** and **PHP 8.2+**

3. Activate via **Network Admin → Plugins**

4. No configuration required — initializes automatically

---

## **Compatibility**

* Fully **WordPress Multisite** compatible

* Optimized for **PHP-FPM**

* Safe behind **Cloudflare**

* Tested with **SparxStar**, **AiWA**, and related modules

---

## **Philosophy**

Most platforms assume every user has a fast device and modern network. SparxStar does not.  
 UEC measures the **real** environment and adapts the experience accordingly, converting unknown conditions into predictable signals for automated decision-making.

---

## **Usage (Developer API)**

To access user environment data from another plugin or theme, use the static methods in:

`\Starisian\SparxstarUEC\StarUserEnv`

These values are served from a cache and are extremely fast.

### **PHP Examples**

`if ( class_exists('\Starisian\SparxstarUEC\StarUserEnv') ) {`

    `$browser_name = \Starisian\SparxstarUEC\StarUserEnv::get_browser_name();`  
    `$os_info = \Starisian\SparxstarUEC\StarUserEnv::get_os();`  
    `$device_type = \Starisian\SparxstarUEC\StarUserEnv::get_device_type();`  
    `$network_bandwidth = \Starisian\SparxstarUEC\StarUserEnv::get_network_effective_type();`

    `if ( '4g' !== $network_bandwidth ) {`  
        `// Consider loading lighter assets`  
    `}`

    `$ip_address = \Starisian\SparxstarUEC\StarUserEnv::get_ip_address();`

    `// Will return NULL unless geolocation is configured`  
    `$location = \Starisian\SparxstarUEC\StarUserEnv::get_location();`  
    `$country = $location['country'] ?? 'Unknown';`  
`}`

---

## **Geolocation Support (Optional)**

UEC **does not** provide geolocation by itself.

To enable geolocation, you must use:

* A **MaxMind GeoIP2** license key  
   *(recommended — commercial and GDPR-friendly)*  
   **or**

* Another IP-to-location service that hooks the `sparxstar_env_geolocation_lookup` filter

Example:

`add_filter( 'sparxstar_env_geolocation_lookup', function( $location, $ip ) {`  
    `return my_geo_service_lookup($ip); // Must return ['country' => 'US', ...] or null`  
`}, 10, 2 );`

If no provider implements this filter, `get_location()` returns `null`.

---

## **Client-Side JavaScript API**

After `DOMContentLoaded`, a global `SPARXSTAR` object is available:

`const deviceType = SPARXSTAR.State.device.type;`  
`const networkBandwidth = SPARXSTAR.Utils.getNetworkBandwidth();`

`console.log(`  
    `` `User is on a ${deviceType} with a ${networkBandwidth} connection.` ``  
`);`

---

## **Advanced Configuration**

### **Persistent Object Cache**

`add_filter( 'sparxstar_env_cache_handler', function( $handler ) {`  
    `return wp_using_ext_object_cache() ? 'object_cache' : $handler;`  
`});`

### **TTL Tuning**

`add_filter( 'sparxstar_env_cache_ttl', fn() => 5 * MINUTE_IN_SECONDS );`  
`add_filter( 'sparxstar_env_geolocation_ttl', fn() => 24 * HOUR_IN_SECONDS );`

---

## **Plugin Architecture**

* `sparxstar-user-environment-check.php`  
   **Loader** — Defines constants and initializes orchestrator

* `src/SparxstarUserEnvironmentCheck.php`  
   **Orchestrator** — Central bootstrap

* `src/api/SparxstarUECAPI.php`  
   **Writer** — REST API \+ snapshot cleanup

* `src/StarUserEnv.php`  
   **Reader** — Public cached API

* `src/AssetManager.php`  
   **Asset Loader** — JS/CSS orchestration

---

## **Filters Reference**

* `sparxstar_env_cache_handler`

* `sparxstar_env_cache_ttl`

* `sparxstar_env_geolocation_ttl`

* `sparxstar_env_geolocation_lookup`

* `sparxstar_env_retention_days`

---

## **FAQ**

**What if I don't use WP Consent?**  
 Scripts will not run unless consent is detected, unless bypassed intentionally.

**Performance impact?**  
 Minimal. Logging is rate-limited, async, and cached.

---

## **Roadmap**

None.  

This repository only accepts:

* Security fixes
* Critical bug patches
* PHP compatibility updates

All enhancements require a **fork**.

---

## **License**

Proprietary. All rights reserved.  
Commercial usage requires written consent from **Starisian Technologies / MaximillianGroup**.

---

## **Credits**

Developed by **Max Barrett** and **Starisian Technologies**.  
 Built to power scalable digital marketing tools and creative ecosystems across West Africa and beyond.

