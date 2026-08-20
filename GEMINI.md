# Homey Channel Sync — Gemini CLI Context & Guidelines

## 1. Project Overview & Scope
- **Plugin Name:** Homey Channel Sync (`homey-channel-sync`)
- **Primary Goal:** Extend the Homey WordPress Booking Theme to sync pricing, availability, and reservation data with external Channel Managers (CM) / Property Management Systems (PMS).
- **Core Strategy:** Modular, driver-based architecture. Beds24 is the primary active adapter, but the system must easily accept future CM adapters (e.g., Guesty, OwnerRez, Hostaway, Cloudbeds).
- **Theme Target:** Homey Theme by Favethemes. Post types used: `homey_listing`, `homey_booking`.

---

## 2. Architectural Principles
- **Strict OOP Modular Structure:** Separate settings UI, database abstractions, theme hooks, and channel manager adapters into distinct, single-responsibility classes.
- **Adapter Pattern:** All channel manager integrations must implement a shared interface (e.g., `Homey_Sync_Adapter_Interface`) to handle token authentication, room mapping, and rate fetching.
- **Asynchronous Operations:** Never make live API calls on front-end page loads. Store synced data in post meta and transients via background `WP-Cron` tasks.

---

## 3. Required Admin UI Specifications (WP-Admin)

Build a clean Settings Page under the main WordPress Admin (or under `Homey > Channel Sync` menu):

### Section A: Channel Manager Selection
- Dropdown or card-select for choosing the active Channel Manager.
- **Active Option:** Beds24
- **Disabled/Grayed-Out Options (Planned):** Guesty, OwnerRez, Hostaway, Cloudbeds (Marked with a "Coming Soon" badge).

### Section B: API Credentials
- Dynamic credentials form based on the active Channel Manager selected.
- For Beds24: Inputs for **API Refresh Token** and **Client Account ID / Key**.
- Secure storage in `wp_options` with sanitized inputs and a "Test Connection" AJAX action.

### Section C: Listing & Room Mappings
- Render an admin table listing all published `homey_listing` post types.
- **Columns to display:**
  1. **Thumbnail:** Small post featured image (50x50px thumbnail).
  2. **Internal Details:** Listing Title & Internal WP Post ID.
  3. **Mapping Inputs:**
     - `Channel Manager Property ID` (Text input)
     - `Channel Manager Room ID` (Text input)
- Save mapping pairs directly to post meta (e.g., `_homey_sync_cm_property_id`, `_homey_sync_cm_room_id`).

### Section D: Feature Toggles (Modular Feature Engine)
- Settings switches to enable/disable specific sync components independently:
  - `[x] Dynamic Daily Price Sync` (Primary Phase 1 feature)
  - `[ ] Full Booking Data Ingestion` (Disabled / Feature Flag)
  - `[ ] Dynamic Promo Code Engine` (Disabled / Feature Flag)

### Section E: Automated Sync Schedules & Manual Override
- **Cron Schedule Selector:** Dropdown setting to configure automated background fetching:
  - Every 12 Hours (`twicedaily`)
  - Daily (`daily`)
  - Weekly (`weekly`)
  - Monthly (`monthly`)
- **Manual Trigger Button ("Sync Now"):**
  - An AJAX-powered button to execute the full sync routine immediately.
  - Show a real-time progress indicator or success message displaying time elapsed and total listings updated.

---

## 4. Coding Standards & Conventions
- **PHP Version:** 8.0+
- **Security:** Always use nonces (`wp_nonce_field`), check capabilities (`manage_options`), and sanitize inputs using `sanitize_text_field()`.
- **Text Domain:** `homey-channel-sync` for all i18n functions (`__()`, `_e()`).
- **Theme Isolation:** Do not hardcode dependency paths. Check if `homey_listing` post type exists before executing theme-specific hooks.
- **PHP Documentation & Annotations:**
  - Mandatory **PHPDoc blocks** on all classes, properties, and methods.
  - Type-hint all parameters and return types (`string`, `array`, `bool`, `void`, etc.).
  - Document parameter descriptions (`@param`), expected return values (`@return`), thrown exceptions (`@throws`), and package details (`@package HomeyChannelSync`).
  - Use PHP 8 Attributes where appropriate (e.g., route or hook annotations if implementing custom routing/mapping wrappers).

---

## 5. Local Reference Paths & Context
- **Plugin Root Directory:** `web/app/plugins/homey-channel-sync/`
- **Parent Theme Path:** `../../themes/homey/`
- **Child Theme Path:** `../../themes/homey-child/`
- **Related Homey Core Plugins:**
  - `../homey-core/`
  - `../homey-login-register/`

### Key File Mapping for Inspection:
- **Homey Theme Functions & Hooks:** `../../themes/homey/functions.php`
- **Homey Booking & Pricing Engine:** `../../themes/homey/inc/booking-functions.php`
- **Homey Calendar & Daily Rates:** `../../themes/homey/framework/functions/calendar.php`
- **Homey Listing Custom Meta Fields:** `../../themes/homey/inc/listing-custom-options.php`