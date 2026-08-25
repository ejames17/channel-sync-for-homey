# homey-channel-sync

[![CI Status (Master)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml)
[![CI Status (Development)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml/badge.svg?branch=development)](https://github.com/ejames17/homey-channel-sync/actions/workflows/ci.yml)

Seamless channel management, dynamic pricing, and reservation sync engine connecting Beds24 and PMS channels to the Homey WordPress Theme.

**Homey Channel Sync** connects the [Homey Booking Theme]([https://favethemes.com/](https://themeforest.net/item/homey-booking-wordpress-theme/23338013) with Property Management Systems (PMS) like Beds24 to enable automated dynamic daily pricing, rate syncs, and real-time reservation management.

---

## 🚀 Features (Phase 1)
* **Beds24 API V2 Integration:** Native OAuth token management and transient-cached data fetching.
* **Dynamic Daily Pricing:** Automated sync overriding static Homey daily rates with live PMS pricing.
* **Background Syncing:** Asynchronous rate updates powered by WP-Cron to prevent front-end performance bottlenecks.
* **Developer First:** Built with clean hooks and an extensible PMS adapter engine.

---

## 🛠️ Installation & Development

### For Local Plugin Development
1. Clone this repository into your local site's plugin directory:
   ```bash
   cd wp-content/plugins/
   git clone [https://github.com/ejames17/homey-channel-sync.git](https://github.com/ejames17/homey-channel-sync.git)
