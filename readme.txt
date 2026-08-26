=== Homey Channel Sync ===
Contributors: ejames17
Donate link: https://github.com/ejames17/homey-channel-sync
Tags: homey, sync, beds24, channel-manager, booking, pms
Requires at least: 6.0
Requires PHP: 8.0
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automate your property rates, sync availability, and connect Beds24 & PMS channels directly with the Homey Booking Theme.

== Description ==

**Homey Channel Sync** is a high-integrity, modular synchronization engine designed to bridge the gap between Favethemes' popular **Homey WordPress Booking Theme** and leading Property Management Systems (PMS) or Channel Managers, starting with **Beds24**.

For vacation rental owners, boutique hotels, and multi-property managers, manual price updates are a thing of the past. Homey Channel Sync automatically fetches your latest daily pricing structures from Beds24 and overlays them right on your front-end calendar grids, ensures checkout breakdown transparency, and syncs nightly base rate defaults in the background.

=== Key Features ===
* **Beds24 V2 API Integration:** Robust connection utilizing Beds24's secure, modern REST V2 API with automatic self-healing token refresh sequences.
* **Fuzzy Autocompletion:** Instantly matches your WordPress Homey listings with external Beds24 properties and rooms.
* **Dynamic Header Pricing:** Shows a "From $XX" label based on your lowest/first available nightly rate on page load.
* **Date Range Recalculation:** Instantly displays the true average nightly rate inside the booking widget header as soon as dates are selected.
* **Transparent Breakdown Dropdowns:** Appends a slide-toggle daily pricing detail grid directly inside checkout summary panels.
* **Flexible Schedules:** Configure WP-Cron background fetch intervals (12 Hours, Daily, Weekly, Monthly) with a single click.
* **Manual Force Trigger:** Execute instant, real-time rate synchronizations on-demand with a visual ajax progress indicator.
* **Developer Friendly & Modular:** Extensible, driver-based adapter design ready for future channel managers (Guesty, OwnerRez, Hostaway, Cloudbeds).

== Installation ==

1. Upload the plugin folder `homey-channel-sync` to the `/wp-content/plugins/` directory, or upload the zip file directly via the WordPress Admin dashboard under `Plugins > Add New`.
2. Activate the plugin.
3. Navigate to **Homey > Channel Sync** (or Settings > Channel Sync) in your WordPress Admin sidebar.
4. Input your Beds24 credentials (invite code or permanent long-life token) and select your active channel.
5. Save settings, map your listing posts, and configure your preferred cron schedule.

== Frequently Asked Questions ==

= Does this plugin require the Homey Theme? =
Yes. This plugin specifically hooks into the custom post types (`homey_listing`, `homey_booking`) and database tables utilized by the Homey Booking WordPress Theme.

= Will making API requests slow down my website? =
Not at all. This plugin implements a strict caching and background execution design. Live API requests are handled by background WP-Cron intervals and cached securely in post meta and transients. Website visitors will enjoy lightning-fast load times.

= What happens if the Beds24 API goes offline? =
Homey Channel Sync includes a smart self-healing fallback mechanism. If the live API is unreachable or tokens are invalidated, the plugin serves high-quality cached rates and falls back cleanly without breaking your booking calendar.

== Screenshots ==

1. **Active Channel Selection & Secure Auth:** Seamlessly configure the active channel manager and authenticate using dynamic self-healing tokens or long-life keys.
2. **Listings Mapping Control Center:** Map your published listing posts directly to external Property and Room IDs with automatic fuzzy name matching.
3. **Schedules & Feature Toggles:** Toggle individual modules independently, select scheduled WP-Cron background intervals, or trigger manual overrides.
4. **Interactive Front-End Overlays:** Displays "From $XX/Nightly" defaults, recalculates checkout averages, and appends slide-toggle daily details breakdowns.

== Changelog ==

= 1.0.0 =
* Initial open-source release.
* Support for Beds24 V2 API token exchanges and property fetching.
* Interactive frontend pricing overlays, dynamic widget header updates, and toggleable breakdown box grids.
