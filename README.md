# Homey Channel Sync

[![CI Status (Master)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml)
[![CI Status (Development)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml/badge.svg?branch=development)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml)

Seamless channel management, dynamic pricing, and reservation sync engine connecting Beds24 and PMS channels to the Homey WordPress Theme.

**Homey Channel Sync** is a high-integrity, modular synchronization engine designed to bridge the gap between Favethemes' popular [Homey Booking WordPress Theme](https://themeforest.net/item/homey-booking-wordpress-theme/23338013) and leading Property Management Systems (PMS) or Channel Managers, starting with **Beds24**.

---

## 📸 Screenshots

### 1. Active Channel Selection & Secure Auth
![Channel Selection](screenshots/screenshot-1.png)
*Seamlessly select your active PMS, exchange Invite Codes, and authorize using Beds24's secure REST v2 token handshake.*

### 2. Control Center Listing Mappings
![Listing Mapping](screenshots/screenshot-2.png)
*Map your published WordPress listings directly to external Property and Room IDs with automatic, real-time fuzzy name matching.*

### 3. Schedules, Feature Toggles & Overrides
![Sync Settings](screenshots/screenshot-3.png)
*Configure custom WP-Cron background fetch schedules, activate dynamic price sync features, and run on-demand updates instantly.*

### 4. Interactive Front-End Overlays
![Front-End Overlay](screenshots/screenshot-4.png)
*Renders dynamic pricing grids directly on listing calendars, adjusts widget headers based on selection, and overlays details breakdowns.*

---

## 🚀 Key Features

* **Beds24 V2 API Integration:** Robust connection utilizing Beds24's secure, modern REST V2 API with automatic self-healing token refresh sequences.
* **Fuzzy Autocompletion:** Instantly matches your WordPress Homey listings with external Beds24 properties and rooms.
* **Dynamic Header Pricing:** Shows a "From $XX" label based on your lowest/first available nightly rate on page load.
* **Date Range Recalculation:** Instantly displays the true average nightly rate inside the booking widget header as soon as dates are selected.
* **Transparent Breakdown Dropdowns:** Appends a slide-toggle daily pricing detail grid directly inside checkout summary panels.
* **Flexible Schedules:** Configure WP-Cron background fetch intervals (12 Hours, Daily, Weekly, Monthly) with a single click.
* **Manual Force Trigger:** Execute instant, real-time rate synchronizations on-demand with a visual ajax progress indicator.
* **Developer Friendly & Modular:** Extensible, driver-based adapter design ready for future channel managers (Guesty, OwnerRez, Hostaway, Cloudbeds).

---

## 🛠️ Installation & Development

### Local Plugin Setup
1. Clone this repository into your WordPress local site's plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/ejames17/homey-channel-sync.git
   ```
2. Activate the plugin via WordPress Admin dashboard.
3. Configure credentials under **Homey > Channel Sync** in your sidebar.

### Running End-to-End Tests
To guarantee the structural integrity of your changes, we use a robust Playwright-based testing suite that runs on real WordPress environments:
1. Install development dependencies:
   ```bash
   npm install
   ```
2. Run mock API and automated E2E tests:
   ```bash
   npx playwright test
   ```

---

## 🤝 Contributing & Support

We welcome contributions of any size! If you want to expand support for other Property Management Systems (e.g. Guesty, Cloudbeds, OwnerRez):
1. Create a class that implements `Homey_Sync_Adapter_Interface` under `includes/interfaces/`.
2. Register your new driver under `includes/adapters/` and update settings selectors.
3. Submit a Pull Request.

For bugs or feature requests, please open a GitHub Issue or utilize `/bug` commands inside the CLI.

---

## 📄 License
This project is licensed under **GPLv2 or later** (compatible with the WordPress Core License model).
