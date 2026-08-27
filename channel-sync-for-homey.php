<?php
/**
 * Plugin Name:       Channel Sync for Homey
 * Plugin URI:        https://github.com/ejames17/channel-sync-for-homey
 * Description:       Synchronize Beds24 channels and availability for the Homey theme.
 * Version:           1.0.0
 * Author:            ejames17
 * License:           GPLv2 or later
 * Text Domain:       channel-sync-for-homey
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Tested up to:      6.6
 *
 * @package           HomeyChannelSync
 */

declare(strict_types=1);

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main Plugin Class.
 *
 * Implements the singleton pattern to bootstrap and manage core plugin
 * modules, activation/deactivation processes, i18n text domain loads,
 * and class autoloading.
 *
 * @package HomeyChannelSync
 */
final class Homey_Channel_Sync {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public const VERSION = '1.0.0';

	/**
	 * Unique singleton instance.
	 *
	 * @var Homey_Channel_Sync|null
	 */
	private static ?Homey_Channel_Sync $instance = null;

	/**
	 * Cached instance of the Admin Settings Panel class.
	 *
	 * @var Homey_Channel_Sync_Admin|null
	 */
	public ?Homey_Channel_Sync_Admin $admin = null;

	/**
	 * Cached instance of the Cron Manager class.
	 *
	 * @var Homey_Channel_Sync_Cron|null
	 */
	public ?Homey_Channel_Sync_Cron $cron = null;

	/**
	 * Retrieve the single class instance.
	 *
	 * @return Homey_Channel_Sync Active singleton.
	 */
	public static function get_instance(): Homey_Channel_Sync {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Sets up autoloading, triggers core setups, and registers hooks.
	 */
	private function __construct() {
		$this->register_autoloader();

		// Load pluggable theme core hotpatches
		require_once plugin_dir_path( __FILE__ ) . 'includes/core/theme-hotpatches.php';

		// Hook plugin load actions
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
		add_action( 'init', array( $this, 'load_text_domain' ) );

		// Register lifecycle hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Front-end calendar and pricing display overrides hooks
		add_action( 'wp_footer', array( $this, 'render_front_end_assets' ) );
	}

	/**
	 * PSR-4 aligned lightweight Autoloader.
	 *
	 * Maps plugin classes to their respective directory folders dynamically
	 * to isolate load processes.
	 */
	private function register_autoloader(): void {
		spl_autoload_register(
			function ( string $class_name ) {
				// Only load classes belonging to our namespace prefix
				if ( ! str_starts_with( $class_name, 'Homey_' ) ) {
					  return;
				}

				$class_path = '';

				if ( 'Homey_Sync_Adapter_Interface' === $class_name ) {
					$class_path = 'includes/interfaces/interface-channel-adapter.php';
				} elseif ( 'Homey_Channel_Sync_Beds24_Adapter' === $class_name ) {
					$class_path = 'includes/adapters/class-homey-channel-sync-beds24-adapter.php';
				} elseif ( 'Homey_Channel_Sync_Admin' === $class_name ) {
					$class_path = 'includes/admin/class-homey-channel-sync-admin.php';
				} elseif ( 'Homey_Channel_Sync_Cron' === $class_name ) {
					$class_path = 'includes/core/class-homey-channel-sync-cron.php';
				} elseif ( 'Homey_Sync_Logger' === $class_name ) {
					$class_path = 'includes/core/class-homey-sync-logger.php';
				}

				if ( ! empty( $class_path ) ) {
					$full_path = plugin_dir_path( __FILE__ ) . $class_path;
					if ( file_exists( $full_path ) ) {
						require_once $full_path;
					}
				}
			}
		);
	}

	/**
	 * Initialize core modules after all plugins are loaded.
	 */
	public function init_plugin(): void {
		// Run PHP version requirement check
		if ( PHP_VERSION_ID < 80000 ) {
			add_action( 'admin_notices', array( $this, 'display_php_version_warning' ) );
			return;
		}

		// Instantiate Singleton/Core Modules
		if ( is_admin() ) {
			$this->admin = new Homey_Channel_Sync_Admin();
		}
		$this->cron = new Homey_Channel_Sync_Cron();
	}

	/**
	 * Load translation text domains.
	 */
	public function load_text_domain(): void {
		load_plugin_textdomain(
			'channel-sync-for-homey',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Execution on plugin activation.
	 *
	 * Creates base options and registers scheduled event hooks.
	 */
	public function activate(): void {
		// Check PHP Version compatibility
		if ( PHP_VERSION_ID < 80000 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Homey Channel Sync requires PHP version 8.0 or higher. Installation aborted.', 'channel-sync-for-homey' ) );
		}

		// Load default settings if not already defined
		$existing_options = get_option( 'homey_channel_sync_options' );
		if ( false === $existing_options ) {
			$default_settings = array(
				'active_channel'                 => 'beds24',
				'beds24_auth_method'             => 'exchange', // 'exchange' or 'longlife'
				'beds24_invite_code'             => '',
				'beds24_access_token'            => '',
				'beds24_access_token_expires_at' => 0,
				'beds24_refresh_token'           => '',
				'feature_price_sync'             => '0',
				'feature_booking_ingestion'      => '0',
				'feature_promo_engine'           => '0',
				'cron_schedule'                  => 'twicedaily',
				'enable_debug_log'               => '0',
			);
			update_option( 'homey_channel_sync_options', $default_settings );
		}

		// Configure background scheduled cron event
		$options  = get_option( 'homey_channel_sync_options', array() );
		$schedule = $options['cron_schedule'] ?? 'twicedaily';

		if ( ! wp_next_scheduled( 'homey_channel_sync_cron_hook' ) ) {
			wp_schedule_event( time() + 60, $schedule, 'homey_channel_sync_cron_hook' );
		}
	}

	/**
	 * Execution on plugin deactivation.
	 *
	 * Cleans up backgrounds tasks.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'homey_channel_sync_cron_hook' );
	}

	/**
	 * Render front-end styles and scripts to inject custom daily rates
	 * into the interactive calendar month grids and checkout breakdown tables.
	 */
	public function render_front_end_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$options            = get_option( 'homey_channel_sync_options', array() );
		$feature_price_sync = ! empty( $options['feature_price_sync'] ) && '1' === $options['feature_price_sync'];

		// Fetch the synchronized daily custom periods array
		$custom_periods = get_post_meta( $post_id, 'homey_custom_period', true );

		// If on booking/checkout pages, we might need custom periods of the listing currently being booked.
		// We pull custom periods for all mapped listings, nested securely by Listing ID, to prevent timestamp key collisions
		$pooled_periods = array();

		if ( is_singular( 'listing' ) ) {
			if ( is_array( $custom_periods ) ) {
				$pooled_periods[ $post_id ] = $custom_periods;
			}
		} else {
			// Pull custom periods for all mapped listings to support checkout sidebars
			$mapped_listings = get_posts(
				array(
					'post_type'      => 'listing',
					'posts_per_page' => 100,
					'meta_query'     => array(
						array(
							'key'     => '_homey_sync_cm_room_id',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			foreach ( $mapped_listings as $lst ) {
				$list_periods = get_post_meta( $lst->ID, 'homey_custom_period', true );
				if ( is_array( $list_periods ) ) {
					$pooled_periods[ $lst->ID ] = $list_periods;
				}
			}
		}

		?>
		<!-- Homey Channel Sync — Front End Overlay Pricing Style block -->
		<style>
			#custom-price-section,
			a[href="#custom-price-section"],
			li a[href="#custom-price-section"] {
				display: none !important;
			}
			.single-listing-calendar-wrap li {
				position: relative;
				padding-bottom: 12px !important;
			}
			.homey-pms-calendar-price {
				position: absolute;
				bottom: 2px;
				left: 0;
				right: 0;
				font-size: 9px !important;
				color: #15803d !important;
				font-weight: 700 !important;
				text-align: center;
				line-height: 1 !important;
				display: block;
				pointer-events: none;
			}
			.single-listing-calendar-wrap li.day-booked .homey-pms-calendar-price,
			.single-listing-calendar-wrap li.day-status-booked .homey-pms-calendar-price,
			.single-listing-calendar-wrap li.day-pending .homey-pms-calendar-price,
			.single-listing-calendar-wrap li.day-status-pending .homey-pms-calendar-price {
				display: none !important;
			}
			/* Visual Override: Force yellowish Pending days to display as fully Booked (soft red background) */
			.single-listing-calendar-wrap li.day-pending,
			.single-listing-calendar-wrap li.day-status-pending {
				background-color: #fca5a5 !important; /* Soft red background to match booked */
				color: #991b1b !important;
				text-decoration: line-through !important;
				pointer-events: none !important; /* Prevent clicks/selection */
			}
			.homey-pms-daily-breakdown-box {
				margin: 12px 0 8px 0;
				padding: 12px 15px;
				background: #f8fafc;
				border: 1px solid #e2e8f0;
				border-radius: 6px;
				font-family: inherit;
			}
			.homey-pms-daily-breakdown-box strong {
				color: #1e293b;
				display: block;
				margin-bottom: 8px;
				font-size: 12px;
				font-weight: 700;
				border-bottom: 1px solid #cbd5e1;
				padding-bottom: 4px;
			}
			.homey-pms-daily-breakdown-box ul {
				list-style: none !important;
				margin: 0 !important;
				padding: 0 !important;
			}
			.homey-pms-daily-breakdown-box li {
				display: flex;
				justify-content: space-between;
				margin-bottom: 6px;
				font-size: 11px;
				color: #475569;
			}
			.homey-pms-daily-breakdown-box li:last-child {
				margin-bottom: 0;
				border-bottom: 0;
				padding-bottom: 0;
			}
			.homey-pms-daily-breakdown-box li span {
				font-weight: 500;
			}
			.homey-pms-daily-breakdown-box li strong {
				font-size: 11px;
				color: #1e293b;
				border-bottom: 0;
				padding-bottom: 0;
				margin-bottom: 0;
				display: inline;
			}
		</style>

		<!-- Homey Channel Sync — Front End Overlay Pricing Script block -->
		<script>
			window.homeyCustomPeriodPrice = <?php echo wp_json_encode( $pooled_periods ); ?>;
			window.homeyPriceSyncEnabled = <?php echo $feature_price_sync ? 'true' : 'false'; ?>;

			jQuery(document).ready(function($) {
				var rawPeriods = window.homeyCustomPeriodPrice || {};
				var listingId = $('.homey-pms-row').first().data('listing-id') || $('#listing_id').val() || new URLSearchParams(window.location.search).get('listing_id') || new URLSearchParams(window.location.search).get('p') || '';

				// Extract customPeriod specifically for the active listingId, or fallback to first available
				var customPeriod = {};
				if (listingId && rawPeriods[listingId]) {
					customPeriod = rawPeriods[listingId];
				} else {
					var firstKey = Object.keys(rawPeriods)[0];
					if (firstKey && typeof rawPeriods[firstKey] === 'object' && !Array.isArray(rawPeriods[firstKey])) {
						customPeriod = rawPeriods[firstKey];
					} else {
						customPeriod = rawPeriods;
					}
				}
				console.log('>> [Homey Channel Sync] Core Front-End overlay engine initialized for Listing:', listingId);

				// 1. Inject prices into Calendar Month date cells
				function injectCalendarPrices() {
					try {
						if ($.isEmptyObject(customPeriod)) {
							return;
						}

						$('.single-listing-calendar-wrap li[data-timestamp]').each(function() {
							var li = $(this);
							var timestamp = li.attr('data-timestamp');
							
							// Skip pricing overlays on unavailable, booked, or pending days
							if (li.hasClass('day-booked') || li.hasClass('day-status-booked') || li.hasClass('day-pending') || li.hasClass('day-status-pending')) {
								return;
							}
							
							if (li.find('.homey-pms-calendar-price').length === 0 && customPeriod[timestamp]) {
								var price = customPeriod[timestamp]['night_price'];
								var formattedPrice = '$' + Math.round(price);
								li.append('<span class="homey-pms-calendar-price">' + formattedPrice + '</span>');
							}
						});
					} catch (e) {
						console.log('>> [Homey Channel Sync] Calendar price injection error:', e);
					}
				}

				// Trigger calendar render check initially
				setTimeout(injectCalendarPrices, 500);

				// Re-trigger calendar render check when months navigate
				$(document).on('click', '.next-month, .prev-month', function() {
					setTimeout(injectCalendarPrices, 800);
					setTimeout(updateHeaderPrice, 800);
				});

				// Helper function to format date strings to human-friendly layout (e.g. Friday, August 21)
				function formatFriendlyDate(dateStr) {
					var parts = dateStr.split(/[-/]/);
					if (parts.length !== 3) {
						return dateStr;
					}
					
					var y, m, d;
					if (parts[0].length === 4) {
						y = parseInt(parts[0], 10);
						m = parseInt(parts[1], 10) - 1;
						d = parseInt(parts[2], 10);
					} else {
						y = parseInt(parts[2], 10);
						m = parseInt(parts[1], 10) - 1;
						d = parseInt(parts[0], 10);
					}

					var date = new Date(Date.UTC(y, m, d));
					if (isNaN(date.getTime())) {
						return dateStr;
					}
					var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
					var months = ['Jan', 'Feb', 'March', 'Apr', 'May', 'June', 'July', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
					
					return days[date.getUTCDay()] + ', ' + months[date.getUTCMonth()] + ' ' + date.getUTCDate();
				}

				// 2. Inject transparent Daily Pricing Breakdown inside checkout / reservation details dropdown
				function injectDailyBreakdown() {
					try {
						if ($.isEmptyObject(customPeriod)) {
							return;
						}

						// Fetch start and end date boundaries defensively (uses both ID and Class fallbacks)
						var arriveVal = $('.check_in_date').val() || $('input[name="arrive"]').val() || $('#check_in_date').val() || $('#arrive').val() || '';
						var departVal = $('.check_out_date').val() || $('input[name="depart"]').val() || $('#check_out_date').val() || $('#depart').val() || '';

						if (!arriveVal || !departVal) {
							return;
						}

						var partsArrive = arriveVal.split(/[-/]/);
						var partsDepart = departVal.split(/[-/]/);

						if (partsArrive.length !== 3 || partsDepart.length !== 3) {
							return;
						}

						var yArrive, mArrive, dArrive;
						if (partsArrive[0].length === 4) {
							yArrive = parseInt(partsArrive[0], 10);
							mArrive = parseInt(partsArrive[1], 10) - 1;
							dArrive = parseInt(partsArrive[2], 10);
						} else {
							yArrive = parseInt(partsArrive[2], 10);
							mArrive = parseInt(partsArrive[1], 10) - 1;
							dArrive = parseInt(partsArrive[0], 10);
						}

						var yDepart, mDepart, dDepart;
						if (partsDepart[0].length === 4) {
							yDepart = parseInt(partsDepart[0], 10);
							mDepart = parseInt(partsDepart[1], 10) - 1;
							dDepart = parseInt(partsDepart[2], 10);
						} else {
							yDepart = parseInt(partsDepart[2], 10);
							mDepart = parseInt(partsDepart[1], 10) - 1;
							dDepart = parseInt(partsDepart[0], 10);
						}

						var arriveDate = new Date(Date.UTC(yArrive, mArrive, dArrive));
						var departDate = new Date(Date.UTC(yDepart, mDepart, dDepart));

						if (isNaN(arriveDate.getTime()) || isNaN(departDate.getTime())) {
							return;
						}

						var listContainer = $('#collapseExample ul, .payment-list-price-detail-note, .payment-list-price-detail, .payment-list');
						if (listContainer.length === 0) {
							return;
						}

						// Guard against duplicates
						$('.homey-pms-daily-breakdown-box').remove();

						var breakdownHtml = '<div class="homey-pms-daily-breakdown-box" style="display:none;">';
						breakdownHtml += '<strong>Daily Pricing Details:</strong>';
						breakdownHtml += '<ul>';

						var current = new Date(arriveDate.getTime());
						var nightCount = 0;

						while (current < departDate) {
							var y = current.getUTCFullYear();
							var m = current.getUTCMonth();
							var d = current.getUTCDate();
							var dateUtc = Date.UTC(y, m, d) / 1000;

							var price = null;
							if (customPeriod[dateUtc]) {
								price = customPeriod[dateUtc]['night_price'];
							}

							var mString = (m + 1 < 10) ? '0' + (m + 1) : (m + 1);
							var dString = (d < 10) ? '0' + d : d;
							var dateString = y + '-' + mString + '-' + dString;
							var friendlyDate = formatFriendlyDate(dateString);

							if (price !== null) {
								breakdownHtml += '<li><span>' + friendlyDate + '</span> <strong>$' + price.toFixed(2) + '</strong></li>';
								nightCount++;
							}

							current.setUTCDate(current.getUTCDate() + 1);
						}

						breakdownHtml += '</ul>';
						breakdownHtml += '</div>';

						if (nightCount > 0) {
							// Embed breakdown directly inside #collapseExample details dropdown (Listing details page)
							var firstPriceItem = $('#collapseExample ul li.homey_price_first, .payment-list-price-detail-note .homey_price_first, li.homey_price_first, .payment-list li.homey_price_first');
							if (firstPriceItem.length > 0) {
								$(breakdownHtml).insertAfter(firstPriceItem.first());

								// Format the first item label to remove "(with custom period)" and make it clickable
								firstPriceItem.each(function() {
									var item = $(this);
									if (!item.hasClass('pms-formatted')) {
										item.addClass('pms-formatted');
										var spanVal = item.find('span').prop('outerHTML') || '';
										var rawText = item.text();
										var cleanLabel = rawText.replace(/\(with custom[^\)]+\)/g, '').replace(/\$[0-9.,]+/g, '').trim();

										// Build the toggleable header
										item.html(cleanLabel + ' <span class="homey-pms-toggle-arrow" style="color:#f15a24; font-size:10px; margin-left:4px;">▼</span>' + spanVal);
										item.css({ 'cursor': 'pointer', 'user-select': 'none' });
									}
								});
							} else {
								// Backup append inside the multi-step checkout sidebar widget details
								var checkoutSidebar = $('.payment-list-price-detail');
								if (checkoutSidebar.length > 0) {
									$(breakdownHtml).insertAfter(checkoutSidebar.first());
								}
							}
						}
					} catch (e) {
						console.log('>> [Homey Channel Sync] Daily breakdown injection error:', e);
					}
				}

				// 3. Dynamic Widget Header Price Update
				function updateHeaderPrice() {
					try {
						if (!window.homeyPriceSyncEnabled) {
							return;
						}

						var priceHeader = $('.item-price');
						if (priceHeader.length === 0) {
							return;
						}

						// Save original text once
						if (!window.originalPriceText) {
							window.originalPriceText = priceHeader.first().text();
							window.originalPriceHtml = priceHeader.first().html();
						}

						var originalText = window.originalPriceText;
						var match = originalText.match(/\$?([0-9.,]+)/);
						var baseFallbackPrice = match ? parseFloat(match[1].replace(/,/g, '')) : 0;

						var currencySymbol = '$';
						var symbolMatch = originalText.match(/^([^0-9.,\s]+)/) || originalText.match(/([^0-9.,\s]+)[0-9]/);
						if (symbolMatch) {
							currencySymbol = symbolMatch[1].trim();
						}

						// Check check-in and check-out dates
						var arriveVal = $('.check_in_date').val() || $('input[name="arrive"]').val() || $('#check_in_date').val() || $('#arrive').val() || '';
						var departVal = $('.check_out_date').val() || $('input[name="depart"]').val() || $('#check_out_date').val() || $('#depart').val() || '';

						if (arriveVal && departVal) {
							var partsArrive = arriveVal.split(/[-/]/);
							var partsDepart = departVal.split(/[-/]/);

							if (partsArrive.length === 3 && partsDepart.length === 3) {
								var yArrive, mArrive, dArrive;
								if (partsArrive[0].length === 4) {
									yArrive = parseInt(partsArrive[0], 10);
									mArrive = parseInt(partsArrive[1], 10) - 1;
									dArrive = parseInt(partsArrive[2], 10);
								} else {
									yArrive = parseInt(partsArrive[2], 10);
									mArrive = parseInt(partsArrive[1], 10) - 1;
									dArrive = parseInt(partsArrive[0], 10);
								}

								var yDepart, mDepart, dDepart;
								if (partsDepart[0].length === 4) {
									yDepart = parseInt(partsDepart[0], 10);
									mDepart = parseInt(partsDepart[1], 10) - 1;
									dDepart = parseInt(partsDepart[2], 10);
								} else {
									yDepart = parseInt(partsDepart[2], 10);
									mDepart = parseInt(partsDepart[1], 10) - 1;
									dDepart = parseInt(partsDepart[0], 10);
								}

								var arriveDate = new Date(Date.UTC(yArrive, mArrive, dArrive));
								var departDate = new Date(Date.UTC(yDepart, mDepart, dDepart));

								if (!isNaN(arriveDate.getTime()) && !isNaN(departDate.getTime()) && arriveDate < departDate) {
									var current = new Date(arriveDate.getTime());
									var totalPrice = 0;
									var nightCount = 0;

									while (current < departDate) {
										var y = current.getUTCFullYear();
										var m = current.getUTCMonth();
										var d = current.getUTCDate();
										var dateUtc = Date.UTC(y, m, d) / 1000;

										var price = baseFallbackPrice;
										if (customPeriod && customPeriod[dateUtc]) {
											price = parseFloat(customPeriod[dateUtc]['night_price']);
										}
										totalPrice += price;
										nightCount++;

										current.setUTCDate(current.getUTCDate() + 1);
									}

									if (nightCount > 0) {
										var average = totalPrice / nightCount;
										var dynamicPriceString = currencySymbol + average.toFixed(2) + "/Nightly";
										priceHeader.html(dynamicPriceString);
										return;
									}
								}
							}
						}

						// Default State (No dates selected): Prepend "From " before the lowest available nightly rate
						var minPrice = null;
						if (customPeriod && !$.isEmptyObject(customPeriod)) {
							for (var ts in customPeriod) {
								if (customPeriod.hasOwnProperty(ts)) {
									var dayPrice = parseFloat(customPeriod[ts]['night_price']);
									if (dayPrice && (minPrice === null || dayPrice < minPrice)) {
										minPrice = dayPrice;
									}
								}
							}
						}

						var displayPrice = minPrice !== null ? minPrice : baseFallbackPrice;
						if (displayPrice > 0) {
							priceHeader.html("From " + currencySymbol + displayPrice.toFixed(2) + "/Nightly");
						} else {
							priceHeader.html(window.originalPriceHtml);
						}

					} catch (e) {
						console.log('>> [Homey Channel Sync] Header price update error:', e);
					}
				}

				// Global click delegation to toggle the breakdown box when clicking the first price item row
				$(document).off('click', '.homey_price_first').on('click', '.homey_price_first', function(e) {
					var item = $(this);
					var arrow = item.find('.homey-pms-toggle-arrow');
					var breakdown = item.next('.homey-pms-daily-breakdown-box');

					if (breakdown.length > 0) {
						breakdown.slideToggle(200, function() {
							if (breakdown.is(':visible')) {
								arrow.text('▲');
							} else {
								arrow.text('▼');
							}
						});
					}
				});

				// Hook input date changes to update widget header price
				$(document).on('change', '.check_in_date, .check_out_date, input[name="arrive"], input[name="depart"]', function() {
					setTimeout(updateHeaderPrice, 100);
				});

				// Hook to ajaxSuccess to re-run on booking calculation updates
				$(document).ajaxSuccess(function(event, xhr, settings) {
					var url = settings.url || '';
					var data = settings.data || '';

					// Defensively convert data object to string parameter format
					if (typeof data === 'object') {
						data = $.param(data);
					} else if (typeof data !== 'string') {
						data = '';
					}

					if (url.indexOf('homey_calculate_booking_cost_ajax_nightly') !== -1 || url.indexOf('homey_calculate_booking_cost_ajax_day_date') !== -1) {
						setTimeout(injectDailyBreakdown, 150);
						setTimeout(injectDailyBreakdown, 500);
						setTimeout(injectDailyBreakdown, 1000);
						setTimeout(updateHeaderPrice, 150);
						setTimeout(updateHeaderPrice, 500);
						setTimeout(updateHeaderPrice, 1000);
					} else if (data.indexOf('action=homey_calculate_booking_cost_ajax_nightly') !== -1 || data.indexOf('action=homey_calculate_booking_cost_ajax_day_date') !== -1) {
						setTimeout(injectDailyBreakdown, 150);
						setTimeout(injectDailyBreakdown, 500);
						setTimeout(injectDailyBreakdown, 1000);
						setTimeout(updateHeaderPrice, 150);
						setTimeout(updateHeaderPrice, 500);
						setTimeout(updateHeaderPrice, 1000);
					}
				});

				// Backup hover-based triggers on payment list area to cover laggy async loads
				$(document).on('mouseenter', '.payment-list, #homey_booking_cost, .payment-list-price-detail', function() {
					injectDailyBreakdown();
					updateHeaderPrice();
				});

				// Trigger breakdown initial scan on page load
				setTimeout(injectDailyBreakdown, 600);
				setTimeout(updateHeaderPrice, 600);
			});
		</script>
		<?php
	}

	/**
	 * Admin notice warning on PHP version incompatibility.
	 */
	public function display_php_version_warning(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo sprintf(
					/* translators: 1: Current PHP version */
					esc_html__( 'Homey Channel Sync deactivated. This plugin requires PHP version 8.0+; you are currently running version %s.', 'channel-sync-for-homey' ),
					esc_html( PHP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}

// Bootstrap the plugin
Homey_Channel_Sync::get_instance();
