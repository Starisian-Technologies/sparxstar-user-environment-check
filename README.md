![untitled-10-1536x864](https://github.com/user-attachments/assets/e1a42278-ba41-4c6f-8233-859de5267d1d)

# SPARXSTAR<sup>&trade;</sup> User Environment Check (MU Plugin)

A consent-aware, network-wide environment checker for WordPress that (1) shows a lightweight upgrade banner to users on incompatible browsers and (2) captures a **once-per-day** anonymized diagnostics snapshot for R&D. Designed for multisite at scale, with centralized logging and strict privacy controls.

## Why this exists

- **Fewer support tickets:** nudge users with outdated browsers to upgrade.
- **Faster root cause analysis:** minimal, structured telemetry (with consent) to spot breakage patterns.
- **Privacy first:** honors WP Consent API + Do-Not-Track / Global Privacy Control; minimizes payload accordingly.

---

## Features

- **Compatibility banner:** unobtrusive bottom banner if required APIs (e.g., `MediaRecorder`, `getUserMedia`, `fetch`, Promises) aren’t present.
- **Daily diagnostics (opt-in):** one POST per user/day with browser + platform capabilities, trimmed when DNT/GPC are enabled.
- **Consent-aware:** blocks logging unless `wp_has_consent( 'statistics' )` (filterable).
- **Network-wide logs:** centralized newline-delimited JSON (`.ndjson`) rotated per day.
- **Security:** nonce check, server-side consent gate, recursive sanitization, rate-limiting, and file locking on write.

---

## Requirements

- WordPress **6.4+**
- PHP **8.2+**
- Multisite compatible (works on single-site too)
- Optional: WP Consent API provider

---

## Install (MU)

1. Copy the plugin folder into:  
   `wp-content/mu-plugins/sparxstar-user-environment-check-universe/`
2. Create a loader in `wp-content/mu-plugins/envcheck-loader.php` (if needed):

   ```php
   <?php
   require_once __DIR__ . '/sparxstar-user-environment-check-universe/sparxstar-user-environment-check.php';


---

## Configuration (Advanced)

You can override the default settings by defining constants in your `wp-config.php` file. These must be placed before the `/* That's all, stop editing! */` line.

**1. Custom Log Directory:**
By default, logs are stored in `wp-content/envcheck-logs`. To change this, define `ENVCHECK_LOG_DIR`.

```php
// wp-config.php
define( 'ENVCHECK_LOG_DIR', WP_CONTENT_DIR . '/private/env-logs' );
```
---
## Accessing & Understanding the Logs

The plugin stores diagnostic data in the `wp-content/envcheck-logs/` directory (or your custom directory) for 30 days. Log entries are automattically deleted after 30 days. This can be changed by adding in wp-config.php the following:

```php
// wp-config.php
define( 'ENVCHECK_RETENTION_DAYS', 14 ); // Keep logs for 14 days

```
Additional details about the logs:

- **Format:** Logs are stored in newline-delimited JSON (`.ndjson`) files. This format is efficient and easy to parse, with each line being a valid JSON object.
- **File Naming:** A new log file is created each day with the naming convention `envcheck-YYYY-MM-DD.ndjson`.
- **Security:** The log directory is protected by `.htaccess` and `index.php` files to prevent direct web access.

### Log Entry Example

Here is an example of a single log entry, with explanations for each key:

```json
{
  "timestamp_utc": "2025-08-28T15:09:00+00:00",
  "site": {
    "home": "https://example.com",
    "blog_id": 1
  },
  "diagnostics": {
    "privacy": {
      "doNotTrack": false,
      "gpc": true
    },
    "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) ...",
    "os": "Win32",
    "compatible": true
  }
}
```
---
       
## FAQ

**Q: What happens if I don't have a WP Consent API plugin installed?**  
A: The plugin is designed to be "privacy-first." If the `wp_has_consent()` function does not exist, the plugin will **not** enqueue its scripts or log any data. To enable logging without a consent plugin (e.g., for an internal tool where all users have implicitly consented), you can use the `envcheck_consent_category` filter to bypass the check. *This is not recommended for public sites.*

**Q: Why a Must-Use (MU) plugin?**  
A: As an environment and diagnostics tool, it should run consistently across an entire network without the risk of being deactivated on a site-by-site basis. The MU-plugin approach ensures it is always active.

**Q: How much of a performance impact does this have?**  
A: Minimal. The client-side script is small and uses modern, efficient APIs like `Promise.allSettled`. The server-side logging is rate-limited to one request per user per day and writes to a simple file, avoiding database queries.

  

