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

