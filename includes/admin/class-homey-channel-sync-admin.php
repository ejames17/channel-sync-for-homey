<?php
/**
 * Admin Settings Class.
 *
 * Renders and handles options for the Homey Channel Sync settings screen,
 * containing credentials, tabbed interfaces, listing mappings, feature toggles,
 * and background WP-Cron intervals.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Homey_Channel_Sync_Admin {

	/**
	 * Options array cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * Hooks up menus, options, styles, and AJAX handlers.
	 */
	public function __construct() {
		// Initialize options
		$this->options = get_option( 'homey_channel_sync_options', $this->get_defaults() );

		// Hook WordPress Admin actions
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings_and_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_homey_sync_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_homey_sync_exchange_code', array( $this, 'ajax_exchange_code' ) );
		add_action( 'wp_ajax_homey_sync_connect_longlife', array( $this, 'ajax_connect_longlife' ) );
		add_action( 'wp_ajax_homey_sync_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_homey_sync_fetch_pms_inventory', array( $this, 'ajax_fetch_pms_inventory' ) );
		add_action( 'wp_ajax_homey_sync_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Fetch default plugin options.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	public function get_defaults(): array {
		return array(
			'active_channel'                 => 'beds24',
			'beds24_auth_method'             => 'exchange', // 'exchange' or 'longlife'
			'beds24_invite_code'             => '',
			'beds24_access_token'            => '',
			'beds24_access_token_expires_at' => 0,
			'beds24_refresh_token'           => '',
			'feature_price_sync'             => '0',
			'feature_booking_ingestion'      => '0',
			'cron_schedule'                  => 'twicedaily',
			'enable_debug_log'               => '0',
		);
	}

	/**
	 * Register submenu page under listings menu.
	 *
	 * Falls back to a main top-level menu page if listing post-type is absent.
	 */
	public function add_settings_menu(): void {
		// Attach to Homey listings custom top-level admin menu page
		$parent_slug = 'homey-listings';

		if ( ! post_type_exists( 'listing' ) ) {
			// Fallback to top-level menu if Homey theme listings are not defined
			add_menu_page(
				esc_html__( 'Homey Sync', 'channel-sync-for-homey' ),
				esc_html__( 'Homey Sync', 'channel-sync-for-homey' ),
				'manage_options',
				'channel-sync-for-homey',
				array( $this, 'render_settings_page' ),
				'dashicons-update',
				30
			);
		} else {
			add_submenu_page(
				$parent_slug,
				esc_html__( 'Channel Sync Settings', 'channel-sync-for-homey' ),
				esc_html__( 'Channel Sync', 'channel-sync-for-homey' ),
				'manage_options',
				'channel-sync-for-homey',
				array( $this, 'render_settings_page' )
			);
		}
	}

	/**
	 * Register assets like Javascript or CSS on Settings Page only.
	 *
	 * @param string $hook_suffix Page hook context.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'channel-sync-for-homey' ) ) {
			return;
		}

		$beds24_connected = ! empty( $this->options['beds24_access_token'] );

		// Inline custom CSS for a highly polished, modern Admin UI experience
		$custom_css = "
			.homey-sync-wrap { margin-top: 20px; max-width: 1100px; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif; }
			.homey-sync-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; background: #fff; padding: 15px 25px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-left: 4px solid #f15a24; }
			.homey-sync-header h1 { margin: 0; font-size: 23px; font-weight: 600; color: #1d2327; }
			.homey-sync-logo-text { font-weight: 300; color: #72777c; }
			.homey-sync-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); padding: 25px; margin-bottom: 20px; }
			.homey-sync-card-title { font-size: 18px; margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1; font-weight: 600; color: #2c3338; }
			.nav-tab-wrapper { margin-bottom: 25px; border-bottom: 1px solid #c3c4c7; }
			.nav-tab { font-weight: 600; font-size: 14px; padding: 8px 16px; margin-bottom: -1px; }
			.nav-tab-active { border-bottom: 1px solid #f1f1f1; background: #f1f1f1; }
			.coming-soon-badge { background-color: #8c8f94; color: #fff; font-size: 9px; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 3px; margin-left: 8px; vertical-align: middle; display: inline-block; }
			.coming-soon-card { opacity: 0.65; cursor: not-allowed; }
			.coming-soon-card * { pointer-events: none; }
			.homey-sync-status-box { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; justify-content: space-between; }
			.homey-sync-status-success { background: #edfaef; border-left: 4px solid #46b450; color: #255d28; }
			.homey-sync-status-error { background: #fcf0f1; border-left: 4px solid #d63638; color: #7a1d20; }
			.homey-sync-status-info { background: #f0f6fc; border-left: 4px solid #0a4b78; color: #0a4b78; }
			.form-table th { font-weight: 600; color: #2c3338; width: 220px; }
			.homey-sync-btn-primary { background-color: #f15a24 !important; border-color: #f15a24 !important; color: #fff !important; }
			.homey-sync-btn-primary:hover { background-color: #d44c1b !important; border-color: #d44c1b !important; }
			.homey-sync-badge { background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
			.mapping-thumb { width: 50px; height: 50px; border-radius: 4px; object-fit: cover; border: 1px solid #ccd0d4; background: #f0f0f1; }
			.channel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px; }
			.channel-option { border: 2px solid #ccd0d4; border-radius: 6px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.2s ease; position: relative; }
			.channel-option.selected { border-color: #f15a24; background: #fffcf9; }
			.channel-option input[type=radio] { position: absolute; top: 10px; right: 10px; }
			.channel-option-logo { font-size: 32px; margin-bottom: 10px; color: #50575e; }
			.channel-option-name { font-weight: 600; font-size: 14px; color: #2c3338; }
			.test-connection-loader { display: none; margin-left: 10px; vertical-align: middle; }
			.sync-progress-console { background: #1e1e1e; color: #39ff14; font-family: monospace; padding: 15px; border-radius: 4px; max-height: 200px; overflow-y: auto; margin-top: 15px; font-size: 12px; display: none; }
			.auth-details-table { width: 100%; border-collapse: collapse; margin-top: 15px; background: #fcfcfc; border: 1px solid #e5e5e5; }
			.auth-details-table th, .auth-details-table td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #e5e5e5; }
			.auth-details-table th { font-weight: 600; background: #f6f6f6; width: 200px; }

			/* Dynamic Mapping Layout Classes */
			.homey-sync-actions-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
			.homey-sync-actions-left { display: flex; align-items: center; gap: 10px; }
			.homey-sync-actions-right { display: flex; align-items: center; gap: 15px; }
			.manual-mode-active .homey-pms-dropdown-wrap { display: none !important; }
			.manual-mode-active .homey-pms-manual-wrap { display: block !important; }
			.homey-pms-dropdown-wrap { display: flex; flex-direction: column; gap: 8px; }
			.homey-pms-dropdown-wrap select { width: 100% !important; max-width: 250px; }
			.homey-pms-manual-wrap { display: none; }
			.mapping-match-high { background-color: #edfaef !important; transition: background-color 0.5s ease; }
			.mapping-match-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 700; margin-left: 5px; }
			.mapping-match-badge.high { background: #46b450; color: #fff; }
			.mapping-match-badge.none { display: none; }
		";
		wp_register_style( 'homey-sync-admin-css', false );
		wp_enqueue_style( 'homey-sync-admin-css' );
		wp_add_inline_style( 'homey-sync-admin-css', $custom_css );

		// Inline Javascript for AJAX requests
		$custom_js = "
			jQuery(document).ready(function($) {
				// Handle Tab Auth Method Toggle
				$('#beds24_auth_method').on('change', function() {
					var method = $(this).val();
					if (method === 'exchange') {
						$('#exchange-method-container').show();
						$('#longlife-method-container').hide();
					} else {
						$('#exchange-method-container').hide();
						$('#longlife-method-container').show();
					}
				});

				// Handle Screenshot Guide Toggle
				$('#homey-toggle-screenshot').on('click', function(e) {
					e.preventDefault();
					$('#homey-screenshot-guide').slideToggle();
				});

				// Exchange Invite Code & Connect
				$('#homey-sync-exchange-btn').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var inviteInput = $('#beds24_invite_code');
					var statusBox = $('#connection-status-msg');
					var loader = $('.test-connection-loader');

					if (!inviteInput.val().trim()) {
						statusBox.addClass('homey-sync-status-error').html('Please enter an Invite Code.').fadeIn();
						return;
					}

					button.prop('disabled', true);
					loader.show();
					statusBox.hide().removeClass('homey-sync-status-success homey-sync-status-error');

					var data = {
						action: 'homey_sync_exchange_code',
						security: '" . wp_create_nonce( 'homey_sync_auth_nonce' ) . "',
						invite_code: inviteInput.val()
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						if (response.success) {
							statusBox.addClass('homey-sync-status-success').html(response.data.message).fadeIn();
							setTimeout(function() {
								location.reload();
							}, 1500);
						} else {
							button.prop('disabled', false);
							statusBox.addClass('homey-sync-status-error').html('Error: ' + response.data.message).fadeIn();
						}
					}).fail(function() {
						loader.hide();
						button.prop('disabled', false);
						statusBox.addClass('homey-sync-status-error').html('An unexpected server error occurred during authentication exchange.').fadeIn();
					});
				});

				// Connect via Long-Life Token
				$('#homey-sync-longlife-btn').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var tokenInput = $('#beds24_longlife_token');
					var statusBox = $('#connection-status-msg');
					var loader = $('.test-connection-loader');

					if (!tokenInput.val().trim()) {
						statusBox.addClass('homey-sync-status-error').html('Please enter a Long-Life Token.').fadeIn();
						return;
					}

					button.prop('disabled', true);
					loader.show();
					statusBox.hide().removeClass('homey-sync-status-success homey-sync-status-error');

					var data = {
						action: 'homey_sync_connect_longlife',
						security: '" . wp_create_nonce( 'homey_sync_auth_nonce' ) . "',
						longlife_token: tokenInput.val()
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						if (response.success) {
							statusBox.addClass('homey-sync-status-success').html(response.data.message).fadeIn();
							setTimeout(function() {
								location.reload();
							}, 1500);
						} else {
							button.prop('disabled', false);
							statusBox.addClass('homey-sync-status-error').html('Error: ' + response.data.message).fadeIn();
						}
					}).fail(function() {
						loader.hide();
						button.prop('disabled', false);
						statusBox.addClass('homey-sync-status-error').html('An unexpected server error occurred during token verification.').fadeIn();
					});
				});

				// Disconnect Action
				$('.homey-sync-disconnect-btn').on('click', function(e) {
					e.preventDefault();
					if (!confirm('Are you sure you want to disconnect from Beds24 and clear your API credentials?')) {
						return;
					}
					var button = $(this);
					var statusBox = $('#connection-status-msg');
					var loader = $('.test-connection-loader');

					button.prop('disabled', true);
					loader.show();

					var data = {
						action: 'homey_sync_disconnect',
						security: '" . wp_create_nonce( 'homey_sync_auth_nonce' ) . "'
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						if (response.success) {
							statusBox.addClass('homey-sync-status-success').html(response.data.message).fadeIn();
							setTimeout(function() {
								location.reload();
							}, 1000);
						} else {
							button.prop('disabled', false);
							statusBox.addClass('homey-sync-status-error').html('Error: ' + response.data.message).fadeIn();
						}
					});
				});

				// Handle Listing Status Filter Change
				$('input[name=\"listing_status_filter\"]').on('change', function() {
					var status = $(this).val();
					var url = new URL(window.location.href);
					url.searchParams.set('status_filter', status);
					window.location.href = url.toString();
				});

				// Handle dynamic pricing feature checkbox change to enable/disable Manual Sync button
				$('input[name=\"feature_price_sync\"]').on('change', function() {
					var isChecked = $(this).is(':checked');
					var beds24Connected = " . ( $beds24_connected ? 'true' : 'false' ) . ";
					var triggerBtn = $('#homey-sync-trigger');
					var featureWarning = $('#homey-sync-feature-warning');

					if (beds24Connected) {
						if (isChecked) {
							triggerBtn.prop('disabled', false);
							featureWarning.fadeOut();
						} else {
							triggerBtn.prop('disabled', true);
							featureWarning.fadeIn();
						}
					}
				});

				// Active Tab Selection Helper
				$('.channel-option').on('click', function() {
					if (!$(this).hasClass('coming-soon-card')) {
						$('.channel-option').removeClass('selected');
						$(this).addClass('selected');
						$(this).find('input[type=radio]').prop('checked', true);
					}
				});

				// Active Stored Connection Test
				$('#homey-sync-test-conn').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var statusBox = $('#connection-status-msg');
					var loader = $('.test-connection-loader');

					button.prop('disabled', true);
					loader.show();
					statusBox.hide().removeClass('homey-sync-status-success homey-sync-status-error');

					var data = {
						action: 'homey_sync_test_connection',
						security: '" . wp_create_nonce( 'homey_sync_test_connection_nonce' ) . "'
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						button.prop('disabled', false);
						statusBox.addClass(response.success ? 'homey-sync-status-success' : 'homey-sync-status-error').html(response.data.message).fadeIn();
					});
				});

				// Handle Sync Now Click
				$('#homey-sync-trigger').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var progressBox = $('#sync-progress-box');
					var consoleBox = $('#sync-console');

					button.prop('disabled', true);
					progressBox.show().removeClass('homey-sync-status-success homey-sync-status-error').addClass('homey-sync-status-info').html('Initializing background rates synchronization...');
					consoleBox.show().html('>> [INFO] Connecting to Beds24 API v2\\n>> [INFO] Spawning asynchronous sync job...');

					var data = {
						action: 'homey_sync_run_now',
						security: '" . wp_create_nonce( 'homey_sync_run_now_nonce' ) . "'
					};

					var appendConsole = function(text) {
						consoleBox.append('\\n' + text);
						consoleBox.scrollTop(consoleBox[0].scrollHeight);
					};

					setTimeout(function() {
						appendConsole('>> [INFO] Loaded ' + $('#listing-count-val').val() + ' active listing mappings.');
						appendConsole('>> [INFO] Requesting daily rate matrices from Beds24 Server...');
					}, 800);

					$.post(ajaxurl, data, function(response) {
						button.prop('disabled', false);
						progressBox.removeClass('homey-sync-status-info');

						if (response.success) {
							progressBox.addClass('homey-sync-status-success').html(response.data.message);
							appendConsole('>> [SUCCESS] ' + response.data.details);
							appendConsole('>> [SUCCESS] Rates mapped & database transient caches refreshed!');
						} else {
							progressBox.addClass('homey-sync-status-error').html(response.data.message);
							appendConsole('>> [ERROR] Synchronization routine halted.');
							appendConsole('>> [ERROR] ' + response.data.error);
						}
					}).fail(function() {
						button.prop('disabled', false);
						progressBox.removeClass('homey-sync-status-info').addClass('homey-sync-status-error').html('An unexpected critical server error occurred during mapping compilation.');
						appendConsole('>> [ERROR] Request failed with status 500.');
					});
				});

				// ==========================================
				// DYNAMIC INVENTORY & DROPDOWNS CODE
				// ==========================================
				var inventory = window.homeyPmsInventory || [];

				function populateDropdowns() {
					var activeInventory = window.homeyPmsInventory || inventory || [];
					if (!activeInventory || activeInventory.length === 0) {
						return;
					}

					$('.homey-pms-row').each(function() {
						var row = $(this);
						var propSelect = row.find('.homey-pms-property-select');
						var roomSelect = row.find('.homey-pms-room-select');
						var propInput  = row.find('.homey-pms-property-input');
						var roomInput  = row.find('.homey-pms-room-input');

						var savedPropId = propInput.val();
						var savedRoomId = roomInput.val();

						// Populate Property Select Options
						propSelect.empty().append('<option value=\"\">-- Select Property --</option>');
						$.each(activeInventory, function(i, prop) {
							var selected = (String(prop.property_id) === String(savedPropId)) ? 'selected' : '';
							propSelect.append('<option value=\"' + prop.property_id + '\" ' + selected + '>' + prop.property_name + ' (ID: ' + prop.property_id + ')</option>');
						});

						// Populate nested room selects based on chosen property
						function updateRooms(propId, currentRoomId) {
							roomSelect.empty().append('<option value=\"\">-- Select Room --</option>');
							if (!propId) {
								return;
							}
							var matchedProp = activeInventory.find(function(p) { return String(p.property_id) === String(propId); });
							if (matchedProp && matchedProp.rooms) {
								$.each(matchedProp.rooms, function(j, r) {
									var selected = (String(r.room_id) === String(currentRoomId)) ? 'selected' : '';
									roomSelect.append('<option value=\"' + r.room_id + '\" ' + selected + '>' + r.room_name + ' (ID: ' + r.room_id + ')</option>');
								});
							}
						}

						// Set initial state
						if (savedPropId) {
							updateRooms(savedPropId, savedRoomId);
						}

						// Change handlers to synchronize hidden manual inputs in real-time
						propSelect.on('change', function() {
							var pId = $(this).val();
							propInput.val(pId);
							updateRooms(pId, '');
							roomInput.val('');
						});

						roomSelect.on('change', function() {
							var rId = $(this).val();
							roomInput.val(rId);
						});
					});
				}

				populateDropdowns();

				// Handle Manual Input Toggle Switch
				$('#homey-pms-manual-toggle').on('change', function() {
					var isChecked = $(this).is(':checked');
					if (isChecked) {
						$('#homey-mapping-table-wrap').addClass('manual-mode-active');
					} else {
						$('#homey-mapping-table-wrap').removeClass('manual-mode-active');
					}
				});

				// Trigger initial check on load
				if ($('#homey-pms-manual-toggle').is(':checked')) {
					$('#homey-mapping-table-wrap').addClass('manual-mode-active');
				}

				// Live Inventory Purge & Refresh Action
				$('#homey-sync-refresh-inv-btn').on('click', function(e) {
					e.preventDefault();
					var button = $(this);
					var loader = $('.test-connection-loader');
					var statusMsg = $('#automatch-status-msg');

					button.prop('disabled', true);
					loader.show();
					statusMsg.hide().removeClass('homey-sync-status-success homey-sync-status-error');

					var data = {
						action: 'homey_sync_fetch_pms_inventory',
						security: '" . wp_create_nonce( 'homey_sync_inventory_nonce' ) . "',
						force_refresh: '1'
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						button.prop('disabled', false);

						if (response.success) {
							inventory = response.data.inventory;
							window.homeyPmsInventory = inventory;
							populateDropdowns();
							statusMsg.addClass('homey-sync-status-success').html('Beds24 Live Inventory refreshed successfully and transient cache updated!').fadeIn();
						} else {
							statusMsg.addClass('homey-sync-status-error').html('Error: ' + response.data.message).fadeIn();
						}
					}).fail(function() {
						loader.hide();
						button.prop('disabled', false);
						statusMsg.addClass('homey-sync-status-error').html('An unexpected server error occurred during inventory refresh.').fadeIn();
					});
				});

				// ==========================================
				// FUZZY STRING SIMILARITY ENGINE (Levenshtein)
				// ==========================================
				function stringSimilarity(s1, s2) {
					var longer = s1.toLowerCase();
					var shorter = s2.toLowerCase();
					if (s1.length < s2.length) {
						longer = s2.toLowerCase();
						shorter = s1.toLowerCase();
					}
					var longerLength = longer.length;
					if (longerLength === 0) {
						return 1.0;
					}
					return (longerLength - editDistance(longer, shorter)) / parseFloat(longerLength);
				}

				function editDistance(s1, s2) {
					var costs = [];
					for (var i = 0; i <= s1.length; i++) {
						var lastValue = i;
						for (var j = 0; j <= s2.length; j++) {
							if (i == 0) {
								costs[j] = j;
							} else {
								if (j > 0) {
									var newValue = costs[j - 1];
									if (s1.charAt(i - 1) != s2.charAt(j - 1)) {
										newValue = Math.min(Math.min(newValue, lastValue), costs[j]) + 1;
									}
									costs[j - 1] = lastValue;
									lastValue = newValue;
								}
							}
						}
						if (i > 0) costs[s2.length] = lastValue;
					}
					return costs[s2.length];
				}

				// Auto Match Listings fuzzy trigger
				$('#homey-sync-automatch-btn').on('click', function(e) {
					e.preventDefault();
					var activeInventory = window.homeyPmsInventory || inventory || [];
					if (!activeInventory || activeInventory.length === 0) {
						alert('Beds24 Inventory is empty or connection is inactive. Cannot perform auto-matching.');
						return;
					}

					var matchedCount = 0;
					var statusMsg = $('#automatch-status-msg');
					statusMsg.hide().removeClass('homey-sync-status-success homey-sync-status-error').addClass('homey-sync-status-info').html('Executing fuzzy auto-matching algorithm...').fadeIn();

					$('.homey-pms-row').each(function() {
						var row = $(this);
						var title = row.find('.homey-listing-title').text().trim();
						var propSelect = row.find('.homey-pms-property-select');
						var roomSelect = row.find('.homey-pms-room-select');
						var propInput  = row.find('.homey-pms-property-input');
						var roomInput  = row.find('.homey-pms-room-input');
						var matchBadge = row.find('.mapping-match-badge');

						var bestMatch = null;
						var highestScore = 0;

						// Loop through properties and nested rooms to find highest similarity
						$.each(activeInventory, function(i, prop) {
							$.each(prop.rooms, function(j, room) {
								var scoreRoom = stringSimilarity(title, room.room_name);
								var combinedName = prop.property_name + ' ' + room.room_name;
								var scoreCombined = stringSimilarity(title, combinedName);
								
								var score = Math.max(scoreRoom, scoreCombined);
								if (score > highestScore) {
									highestScore = score;
									bestMatch = {
										property_id: prop.property_id,
										room_id: room.room_id
									};
								}
							});
						});

						// Confidence threshold > 70%
						if (bestMatch && highestScore >= 0.70) {
							propInput.val(bestMatch.property_id);
							roomInput.val(bestMatch.room_id);
							propSelect.val(bestMatch.property_id).trigger('change');
							roomSelect.val(bestMatch.room_id).trigger('change');

							row.addClass('mapping-match-high');
							matchBadge.removeClass('none').addClass('high').html('✔️ Auto-Matched (' + Math.round(highestScore * 100) + '%)');
							matchedCount++;
						}
					});

					statusMsg.removeClass('homey-sync-status-info').addClass('homey-sync-status-success').html('Fuzzy auto-matching completed successfully! Auto-matched ' + matchedCount + ' listings with confidence > 70%. Please click \"Save Settings\" below to persist changes.').fadeIn();
				});

				// ==========================================
				// DEBUG LOGS TAB AJAX HANDLERS
				// ==========================================
				$('#homey-sync-clear-logs-btn').on('click', function(e) {
					e.preventDefault();
					if (!confirm('Are you sure you want to empty the active monthly log file?')) {
						return;
					}
					var button = $(this);
					var statusBox = $('#automatch-status-msg');
					var loader = $('.test-connection-loader');
					var logConsole = $('#homey-sync-log-viewer-console');

					button.prop('disabled', true);
					loader.show();
					statusBox.hide().removeClass('homey-sync-status-success homey-sync-status-error');

					var data = {
						action: 'homey_sync_clear_logs',
						security: '" . wp_create_nonce( 'homey_sync_clear_logs_nonce' ) . "'
					};

					$.post(ajaxurl, data, function(response) {
						loader.hide();
						button.prop('disabled', false);

						if (response.success) {
							statusBox.addClass('homey-sync-status-success').html(response.data.message).fadeIn();
							logConsole.html('No logs recorded yet for this month.');
						} else {
							statusBox.addClass('homey-sync-status-error').html('Error: ' + response.data.message).fadeIn();
						}
					});
				});
			});
		";
		wp_register_script( 'homey-sync-admin-js', false, array( 'jquery' ), false, true );
		wp_enqueue_script( 'homey-sync-admin-js' );
		wp_add_inline_script( 'homey-sync-admin-js', $custom_js );
	}

	/**
	 * Register option settings and check form submission.
	 */
	public function register_settings_and_save(): void {
		register_setting( 'homey_channel_sync_group', 'homey_channel_sync_options' );

		$settings_url = admin_url( 'admin.php?page=channel-sync-for-homey' );

		// Process Log Download Request on admin_init (defensive security checks)
		if ( isset( $_GET['action_download_logs'] ) && '1' === $_GET['action_download_logs'] ) {
			if ( ! isset( $_GET['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['security'] ) ), 'homey_sync_download_logs_nonce' ) ) {
				wp_die( esc_html__( 'Nonce verification failed.', 'channel-sync-for-homey' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized access.', 'channel-sync-for-homey' ) );
			}

			$logger   = Homey_Sync_Logger::get_instance();
			$log_file = $logger->get_log_directory() . 'sync-' . current_time( 'Y-m' ) . '.log';

			if ( file_exists( $log_file ) ) {
				header( 'Content-Description: File Transfer' );
				header( 'Content-Type: text/plain' );
				header( 'Content-Disposition: attachment; filename="' . basename( $log_file ) . '"' );
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );
				header( 'Content-Length: ' . filesize( $log_file ) );
				readfile( $log_file );
				exit;
			} else {
				wp_die( esc_html__( 'Active monthly log file is empty or does not exist.', 'channel-sync-for-homey' ) );
			}
		}

		// Process manual setting form submission
		if ( ! isset( $_POST['homey_sync_nonce_field'] ) || ! isset( $_POST['action_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'channel-sync-for-homey' ) );
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['homey_sync_nonce_field'] ) ), 'homey_sync_save_action' ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'channel-sync-for-homey' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'channels';

		if ( $current_tab === 'channels' ) {
			$this->options['active_channel'] = sanitize_text_field( wp_unslash( $_POST['active_channel'] ?? 'beds24' ) );
			update_option( 'homey_channel_sync_options', $this->options );

			add_settings_error( 'homey_sync_messages', 'homey_sync_updated', esc_html__( 'Active channel updated successfully.', 'channel-sync-for-homey' ), 'updated' );
		}

		if ( $current_tab === 'mappings' ) {
			$mappings = $_POST['homey_sync_mappings'] ?? array();
			if ( is_array( $mappings ) ) {
				// Temporarily unhook homey-core's recursive post-meta save action to prevent severe memory exhaustion/recursion leaks during bulk update
				$has_action_added   = has_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );
				$has_action_updated = has_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );

				if ( false !== $has_action_added ) {
					remove_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
				}
				if ( false !== $has_action_updated ) {
					remove_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
				}

				foreach ( $mappings as $listing_id => $mapping_data ) {
					$listing_id = (int) $listing_id;
					if ( $listing_id <= 0 ) {
						continue;
					}

					$property_id = sanitize_text_field( $mapping_data['property_id'] ?? '' );
					$room_id     = sanitize_text_field( $mapping_data['room_id'] ?? '' );

					update_post_meta( $listing_id, '_homey_sync_cm_property_id', $property_id );
					update_post_meta( $listing_id, '_homey_sync_cm_room_id', $room_id );
				}

				// Re-hook the actions to preserve system state
				if ( false !== $has_action_added ) {
					add_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
				}
				if ( false !== $has_action_updated ) {
					add_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
				}
			}
			add_settings_error( 'homey_sync_messages', 'homey_sync_updated', esc_html__( 'Listing and Room mappings updated successfully.', 'channel-sync-for-homey' ), 'updated' );
		}

		if ( $current_tab === 'settings' ) {
			$this->options['feature_price_sync']        = isset( $_POST['feature_price_sync'] ) ? '1' : '0';
			$this->options['feature_booking_ingestion'] = isset( $_POST['feature_booking_ingestion'] ) ? '1' : '0';

			$old_schedule                   = $this->options['cron_schedule'] ?? 'twicedaily';
			$new_schedule                   = sanitize_text_field( wp_unslash( $_POST['cron_schedule'] ?? 'twicedaily' ) );
			$this->options['cron_schedule'] = $new_schedule;

			update_option( 'homey_channel_sync_options', $this->options );

			// Re-schedule cron job on transition, or if currently unscheduled
			if ( $old_schedule !== $new_schedule || ! wp_next_scheduled( 'homey_channel_sync_cron_hook' ) ) {
				wp_clear_scheduled_hook( 'homey_channel_sync_cron_hook' );
				wp_schedule_event( time() + 60, $new_schedule, 'homey_channel_sync_cron_hook' );
			}

			// If dynamic price sync is disabled, cleanly roll back listings to static defaults
			if ( '0' === $this->options['feature_price_sync'] ) {
				$this->revert_to_default_pricing();
			}

			add_settings_error( 'homey_sync_messages', 'homey_sync_updated', esc_html__( 'Sync Schedules and Feature toggles updated.', 'channel-sync-for-homey' ), 'updated' );
		}

		if ( $current_tab === 'logs' ) {
			$this->options['enable_debug_log'] = isset( $_POST['enable_debug_log'] ) ? '1' : '0';
			update_option( 'homey_channel_sync_options', $this->options );
			add_settings_error( 'homey_sync_messages', 'homey_sync_updated', esc_html__( 'Logging configuration saved successfully.', 'channel-sync-for-homey' ), 'updated' );
		}
	}

	/**
	 * Clean up and revert all listings' pricing fields back to their pre-plugin defaults.
	 */
	private function revert_to_default_pricing(): void {
		$listings = get_posts(
			array(
				'post_type'      => 'listing',
				'posts_per_page' => 100,
				'post_status'    => 'any',
			)
		);

		if ( empty( $listings ) ) {
			return;
		}

		// Temporarily unhook homey-core's recursive post-meta save action during rollback
		$has_action_added   = has_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );
		$has_action_updated = has_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );

		if ( false !== $has_action_added ) {
			remove_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
		}
		if ( false !== $has_action_updated ) {
			remove_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
		}

		foreach ( $listings as $listing ) {
			$listing_id = $listing->ID;

			// Restore standard nightly price from backup
			$original_night_price = get_post_meta( $listing_id, '_homey_sync_original_night_price', true );
			if ( ! empty( $original_night_price ) ) {
				update_post_meta( $listing_id, 'homey_night_price', $original_night_price );
				update_post_meta( $listing_id, 'homey_nightly_price', $original_night_price );
			}

			// Restore day date price from backup
			$original_day_date_price = get_post_meta( $listing_id, '_homey_sync_original_day_date_price', true );
			if ( ! empty( $original_day_date_price ) ) {
				update_post_meta( $listing_id, 'homey_day_date_price', $original_day_date_price );
			}

			// Delete all synced custom metadata keys cleanly
			delete_post_meta( $listing_id, '_homey_sync_cm_property_id' );
			delete_post_meta( $listing_id, '_homey_sync_cm_room_id' );
			delete_post_meta( $listing_id, '_homey_sync_daily_rates' );
			delete_post_meta( $listing_id, '_homey_sync_base_price_override' );
			delete_post_meta( $listing_id, '_homey_sync_last_synced_at' );
			delete_post_meta( $listing_id, '_homey_sync_original_night_price' );
			delete_post_meta( $listing_id, '_homey_sync_original_day_date_price' );
			delete_post_meta( $listing_id, 'homey_custom_period' ); // Removes custom periods calendar entirely
		}

		// Re-hook the actions
		if ( false !== $has_action_added ) {
			add_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
		}
		if ( false !== $has_action_updated ) {
			add_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
		}
	}

	/**
	 * AJAX Action callback to exchange invite code and connect.
	 */
	public function ajax_exchange_code(): void {
		check_ajax_referer( 'homey_sync_auth_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$invite_code = sanitize_text_field( wp_unslash( $_POST['invite_code'] ?? '' ) );

		if ( empty( $invite_code ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invite code cannot be empty.', 'channel-sync-for-homey' ) ) );
		}

		$adapter = new Homey_Channel_Sync_Beds24_Adapter();
		$tokens  = $adapter->exchange_invite_code( $invite_code );

		if ( false === $tokens ) {
			wp_send_json_error( array( 'message' => $adapter->get_last_error() ) );
		}

		// Now let's test the connection using the exchanged access token!
		$test_credentials = array(
			'beds24_access_token' => $tokens['token'],
		);
		$connection_ok    = $adapter->test_connection( $test_credentials );

		if ( ! $connection_ok ) {
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Tokens exchanged, but properties endpoint test failed: %s', 'channel-sync-for-homey' ), $adapter->get_last_error() ) ) );
		}

		// Save tokens on successful test
		$this->options['beds24_auth_method']             = 'exchange';
		$this->options['beds24_invite_code']             = $invite_code;
		$this->options['beds24_access_token']            = $tokens['token'];
		$this->options['beds24_access_token_expires_at'] = time() + $tokens['expiresIn'];
		$this->options['beds24_refresh_token']           = $tokens['refreshToken'];

		update_option( 'homey_channel_sync_options', $this->options );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Connection established successfully! Exchanged invite code for valid API credentials.', 'channel-sync-for-homey' ),
			)
		);
	}

	/**
	 * AJAX Action callback to connect using long life token.
	 */
	public function ajax_connect_longlife(): void {
		check_ajax_referer( 'homey_sync_auth_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$longlife_token = sanitize_text_field( wp_unslash( $_POST['longlife_token'] ?? '' ) );

		if ( empty( $longlife_token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Long-life token cannot be empty.', 'channel-sync-for-homey' ) ) );
		}

		$adapter          = new Homey_Channel_Sync_Beds24_Adapter();
		$test_credentials = array(
			'beds24_access_token' => $longlife_token,
		);
		$connection_ok    = $adapter->test_connection( $test_credentials );

		if ( ! $connection_ok ) {
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Properties endpoint check failed: %s', 'channel-sync-for-homey' ), $adapter->get_last_error() ) ) );
		}

		// Save on success
		$this->options['beds24_auth_method']             = 'longlife';
		$this->options['beds24_invite_code']             = '';
		$this->options['beds24_access_token']            = $longlife_token;
		$this->options['beds24_access_token_expires_at'] = 0; // Permanent
		$this->options['beds24_refresh_token']           = '';

		update_option( 'homey_channel_sync_options', $this->options );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Connection established successfully using your Long-Life Token!', 'channel-sync-for-homey' ),
			)
		);
	}

	/**
	 * AJAX Action callback to disconnect from Beds24.
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'homey_sync_auth_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$this->options['beds24_invite_code']             = '';
		$this->options['beds24_access_token']            = '';
		$this->options['beds24_access_token_expires_at'] = 0;
		$this->options['beds24_refresh_token']           = '';

		update_option( 'homey_channel_sync_options', $this->options );

		wp_send_json_success(
			array(
				'message' => esc_html__( 'Successfully disconnected and cleared API credentials.', 'channel-sync-for-homey' ),
			)
		);
	}

	/**
	 * AJAX Action callback for testing active stored credentials.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'homey_sync_test_connection_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$adapter      = new Homey_Channel_Sync_Beds24_Adapter();
		$access_token = $adapter->get_valid_access_token( $this->options );

		if ( empty( $access_token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No valid access token available. Please reconnect.', 'channel-sync-for-homey' ) ) );
		}

		$success = $adapter->test_connection(
			array(
				'beds24_access_token' => $access_token,
			)
		);

		if ( $success ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Connection successfully verified with Beds24 properties!', 'channel-sync-for-homey' ) ) );
		} else {
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Failed to connect: %s', 'channel-sync-for-homey' ), $adapter->get_last_error() ) ) );
		}
	}

	/**
	 * AJAX Action callback to fetch active PMS inventory.
	 */
	public function ajax_fetch_pms_inventory(): void {
		check_ajax_referer( 'homey_sync_inventory_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$force_refresh = isset( $_POST['force_refresh'] ) && '1' === $_POST['force_refresh'];

		$adapter      = new Homey_Channel_Sync_Beds24_Adapter();
		$access_token = $adapter->get_valid_access_token( $this->options );

		if ( empty( $access_token ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Beds24 API connection is inactive. Please connect on Tab 1.', 'channel-sync-for-homey' ) ) );
		}

		$inventory = $adapter->get_properties_and_rooms(
			array(
				'beds24_access_token' => $access_token,
			),
			$force_refresh
		);

		if ( false === $inventory ) {
			wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Failed to retrieve inventory: %s', 'channel-sync-for-homey' ), $adapter->get_last_error() ) ) );
		}

		wp_send_json_success(
			array(
				'inventory' => $inventory,
				'message'   => esc_html__( 'Inventory fetched successfully.', 'channel-sync-for-homey' ),
			)
		);
	}

	/**
	 * AJAX Action callback to clear/empty the active monthly log file.
	 */
	public function ajax_clear_logs(): void {
		check_ajax_referer( 'homey_sync_clear_logs_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized access privileges.', 'channel-sync-for-homey' ) ) );
		}

		$logger  = Homey_Sync_Logger::get_instance();
		$success = $logger->clear_current_log();

		if ( $success ) {
			wp_send_json_success( array( 'message' => esc_html__( 'Active monthly log file emptied successfully!', 'channel-sync-for-homey' ) ) );
		} else {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to empty log file. Please check folder permissions.', 'channel-sync-for-homey' ) ) );
		}
	}

	/**
	 * Renders the primary Tabbed Settings interface.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'channel-sync-for-homey' ) );
		}

		$active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'channels';
		$settings_url = admin_url( 'admin.php?page=channel-sync-for-homey' );

		$beds24_connected = ! empty( $this->options['beds24_access_token'] );
		$auth_method      = $this->options['beds24_auth_method'] ?? 'exchange';

		// Query total active listing records globally
		$listings_count = count(
			get_posts(
				array(
					'post_type'      => 'listing',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			)
		);

		// Pre-fetch Beds24 live inventory in PHP to inject as a local JS object (minimizes roundtrips)
		$pms_inventory = array();
		if ( $beds24_connected && $active_tab === 'mappings' ) {
			$adapter      = new Homey_Channel_Sync_Beds24_Adapter();
			$access_token = $adapter->get_valid_access_token( $this->options );
			if ( ! empty( $access_token ) ) {
				$pms_inventory = $adapter->get_properties_and_rooms(
					array(
						'beds24_access_token' => $access_token,
					)
				);
				if ( ! is_array( $pms_inventory ) ) {
					$pms_inventory = array();
				}
			}
		}

		// Inject fetched inventory as a window variable
		echo '<script>window.homeyPmsInventory = ' . wp_json_encode( $pms_inventory ) . ';</script>';
		?>
		<div class="wrap homey-sync-wrap">
			<div class="homey-sync-header">
				<h1>Channel Sync for Homey <span class="homey-sync-logo-text">| PMS Adapter Dashboard</span></h1>
				<span class="homey-sync-badge"><?php echo esc_html__( 'Phase 1 Active', 'channel-sync-for-homey' ); ?></span>
			</div>

			<?php settings_errors( 'homey_sync_messages' ); ?>

			<!-- Community Voting Banner -->
			<div class="homey-sync-voting-banner" style="background:#fff; border-left:4px solid #f15a24; padding:15px 20px; margin: 15px 0 20px 0; box-shadow:0 1px 3px rgba(0,0,0,0.1); display:flex; align-items:center; justify-content:space-between; border-radius:3px;">
				<div style="flex-grow:1; padding-right:20px;">
					<h3 style="margin:0 0 5px 0; color:#1e1e1c; font-size:15px; font-weight:600;"><?php echo esc_html__( 'Help Shape Channel Sync for Homey Premium!', 'channel-sync-for-homey' ); ?></h3>
					<p style="margin:0; font-size:13px; color:#50575e; line-height:1.4;">
						<?php echo esc_html__( 'We are actively planning support for other major Property Management Systems (PMS) and additional vacation booking WordPress themes as premium paid solutions. Your vote decides our roadmap!', 'channel-sync-for-homey' ); ?>
					</p>
				</div>
				<div style="display:flex; gap:10px; flex-shrink:0;">
					<a href="https://github.com/ejames17/channel-sync-for-homey/discussions/1" target="_blank" class="button button-primary" style="background:#f15a24; border-color:#f15a24; font-weight:500; text-shadow:none; box-shadow:none; color: #fff;">
						<?php echo esc_html__( 'Vote on Next PMS', 'channel-sync-for-homey' ); ?> 🗳️
					</a>
					<a href="https://github.com/ejames17/channel-sync-for-homey/discussions/2" target="_blank" class="button button-secondary" style="border-color:#f15a24; color:#f15a24; font-weight:500;">
						<?php echo esc_html__( 'Vote on Next Theme', 'channel-sync-for-homey' ); ?> 🗳️
					</a>
				</div>
			</div>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'channels', $settings_url ) ); ?>" class="nav-tab <?php echo $active_tab === 'channels' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '1. Channel & API Credentials', 'channel-sync-for-homey' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'mappings', $settings_url ) ); ?>" class="nav-tab <?php echo $active_tab === 'mappings' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '2. Listing Room Mappings', 'channel-sync-for-homey' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $settings_url ) ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '3. Sync Configuration', 'channel-sync-for-homey' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'logs', $settings_url ) ); ?>" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html__( '4. Debug Logs', 'channel-sync-for-homey' ); ?>
				</a>
			</h2>

			<form method="post" action="">
				<?php wp_nonce_field( 'homey_sync_save_action', 'homey_sync_nonce_field' ); ?>
				<input type="hidden" name="action_save_settings" value="1" />
				<input type="hidden" id="listing-count-val" value="<?php echo esc_attr( (string) $listings_count ); ?>"/>

				<!-- TAB 1: Channels and Credentials -->
				<?php if ( $active_tab === 'channels' ) : ?>
					<div class="homey-sync-card">
						<h3 class="homey-sync-card-title"><?php echo esc_html__( 'Choose Active Channel Manager', 'channel-sync-for-homey' ); ?></h3>

						<div class="channel-grid">
							<div class="channel-option selected">
								<input type="radio" name="active_channel" value="beds24" checked />
								<div class="channel-option-logo">🛏️</div>
								<div class="channel-option-name">Beds24</div>
								<span class="coming-soon-badge badge-active"><?php echo esc_html__( 'Active', 'channel-sync-for-homey' ); ?></span>
							</div>

							<div class="channel-option coming-soon-card">
								<input type="radio" name="active_channel" value="guesty" disabled />
								<div class="channel-option-logo">🏠</div>
								<div class="channel-option-name">Guesty</div>
								<span class="coming-soon-badge"><?php echo esc_html__( 'Soon', 'channel-sync-for-homey' ); ?></span>
							</div>

							<div class="channel-option coming-soon-card">
								<input type="radio" name="active_channel" value="ownerrez" disabled />
								<div class="channel-option-logo">🔑</div>
								<div class="channel-option-name">OwnerRez</div>
								<span class="coming-soon-badge"><?php echo esc_html__( 'Soon', 'channel-sync-for-homey' ); ?></span>
							</div>

							<div class="channel-option coming-soon-card">
								<input type="radio" name="active_channel" value="cloudbeds" disabled />
								<div class="channel-option-logo">☁️</div>
								<div class="channel-option-name">Cloudbeds</div>
								<span class="coming-soon-badge"><?php echo esc_html__( 'Soon', 'channel-sync-for-homey' ); ?></span>
							</div>
						</div>

						<h3 class="homey-sync-card-title"><?php echo esc_html__( 'Beds24 Authentication Settings', 'channel-sync-for-homey' ); ?></h3>
						
						<?php if ( $beds24_connected ) : ?>
							<div class="homey-sync-status-box homey-sync-status-success">
								<span>
									<strong>✔️ <?php echo esc_html__( 'CONNECTED', 'channel-sync-for-homey' ); ?></strong> — 
									<?php
									if ( 'longlife' === $auth_method ) {
										echo esc_html__( 'Active via read-only Long-Life Token', 'channel-sync-for-homey' );
									} else {
										echo esc_html__( 'Active via dynamic OAuth Invite Code Exchange', 'channel-sync-for-homey' );
									}
									?>
								</span>
								<button type="button" class="button button-secondary homey-sync-disconnect-btn">
									<?php echo esc_html__( 'Disconnect Channel', 'channel-sync-for-homey' ); ?>
								</button>
							</div>

							<table class="auth-details-table">
								<tr>
									<th><?php echo esc_html__( 'Auth Method', 'channel-sync-for-homey' ); ?></th>
									<td><code><?php echo esc_html( strtoupper( $auth_method ) ); ?></code></td>
								</tr>
								<tr>
									<th><?php echo esc_html__( 'Access Token', 'channel-sync-for-homey' ); ?></th>
									<td>
										<code>
											<?php
											$tok = $this->options['beds24_access_token'] ?? '';
											echo esc_html( substr( $tok, 0, 10 ) . '...' . substr( $tok, -10 ) );
											?>
										</code>
									</td>
								</tr>
								<?php if ( 'exchange' === $auth_method ) : ?>
									<tr>
										<th><?php echo esc_html__( 'Token Expires At', 'channel-sync-for-homey' ); ?></th>
										<td>
											<code>
												<?php
												$exp = (int) ( $this->options['beds24_access_token_expires_at'] ?? 0 );
												echo esc_html( wp_date( 'Y-m-d H:i:s', $exp ) );
												?>
											</code>
											<?php if ( time() > $exp ) : ?>
												<span style="color:#d63638; font-weight:600; margin-left: 10px;"><?php echo esc_html__( '(Expired - Auto refreshing on next run)', 'channel-sync-for-homey' ); ?></span>
											<?php else : ?>
												<span style="color:#46b450; font-weight:600; margin-left: 10px;"><?php echo esc_html__( '(Active)', 'channel-sync-for-homey' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th><?php echo esc_html__( 'Refresh Token', 'channel-sync-for-homey' ); ?></th>
										<td>
											<code>
												<?php
												$ref = $this->options['beds24_refresh_token'] ?? '';
												echo esc_html( substr( $ref, 0, 8 ) . '...' . substr( $ref, -8 ) );
												?>
											</code>
										</td>
									</tr>
								<?php endif; ?>
							</table>

							<div style="margin-top:20px;">
								<button type="button" id="homey-sync-test-conn" class="button button-secondary">
									<?php echo esc_html__( 'Verify Connection Status', 'channel-sync-for-homey' ); ?>
								</button>
								<span class="spinner test-connection-loader"></span>
							</div>
						<?php else : ?>
							<p class="description">
								<?php echo esc_html__( 'Select your preferred connection method and supply your credentials to hook up Beds24 V2.', 'channel-sync-for-homey' ); ?>
							</p>

							<table class="form-table">
								<tr>
									<th scope="row">
										<label for="beds24_auth_method"><?php echo esc_html__( 'Authentication Method', 'channel-sync-for-homey' ); ?></label>
									</th>
									<td>
										<select id="beds24_auth_method" name="beds24_auth_method">
											<option value="exchange" <?php selected( $auth_method, 'exchange' ); ?>><?php echo esc_html__( 'Invite Code Exchange (Recommended)', 'channel-sync-for-homey' ); ?></option>
											<option value="longlife" <?php selected( $auth_method, 'longlife' ); ?>><?php echo esc_html__( 'Long-Life Token (Read-Only)', 'channel-sync-for-homey' ); ?></option>
										</select>
									</td>
								</tr>
							</table>

							<!-- Method A: Invite Code Exchange -->
							<div id="exchange-method-container" style="margin-top:20px; <?php echo 'exchange' === $auth_method ? '' : 'display:none;'; ?>">
								<div class="homey-sync-status-box homey-sync-status-info" style="margin-bottom:15px; font-size:12px;">
									<div>
										<strong>How to get an Invite Code:</strong> Log in to Beds24, navigate to <em>Settings > Marketplace > API</em>, click <strong>Generate Invite Code</strong>, select your desired scopes, and paste the code below. It will be immediately exchanged for standard API access and refresh tokens.
										<p style="margin: 8px 0 0 0;">
											<a href="#" id="homey-toggle-screenshot" style="color: #f15a24; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
												🔍 <?php echo esc_html__( 'Show Screenshot Walkthrough Guide', 'channel-sync-for-homey' ); ?>
											</a>
										</p>
										<div id="homey-screenshot-guide" style="display:none; margin-top: 15px; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; background: #fff; max-width: 100%;">
											<img src="<?php echo esc_url( plugins_url( 'screenshots/beds24_invite_code_masked.png', dirname( __DIR__, 2 ) . '/channel-sync-for-homey.php' ) ); ?>" style="max-width: 100%; height: auto; border: 1px solid #ccd0d4; border-radius: 3px;" alt="Beds24 Invite Code Location" />
										</div>
									</div>
								</div>
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th scope="row"><label for="beds24_invite_code"><?php echo esc_html__( 'Beds24 Invite Code', 'channel-sync-for-homey' ); ?></label></th>
										<td>
											<input type="text" id="beds24_invite_code" class="regular-text" placeholder="Paste your generated Invite Code" style="width: 80%;" />
											<p class="description"><?php echo esc_html__( 'Invite codes are single-use only.', 'channel-sync-for-homey' ); ?></p>
										</td>
									</tr>
								</table>
								<div style="margin-top:15px;">
									<button type="button" id="homey-sync-exchange-btn" class="button homey-sync-btn-primary">
										🗝️ <?php echo esc_html__( 'Exchange Code & Connect', 'channel-sync-for-homey' ); ?>
									</button>
									<span class="spinner test-connection-loader"></span>
								</div>
							</div>

							<!-- Method B: Long Life Token -->
							<div id="longlife-method-container" style="margin-top:20px; <?php echo 'longlife' === $auth_method ? '' : 'display:none;'; ?>">
								<div class="homey-sync-status-box homey-sync-status-info" style="margin-bottom:15px; font-size:12px;">
									<div>
										<strong>How to get a Long-Life Token:</strong> Log in to Beds24, navigate to <em>Settings > Marketplace > API</em>, and generate a <strong>Long-Life Token</strong>. Since they do not expire, they are simpler to maintain for read-only dynamic pricing operations.
									</div>
								</div>
								<table class="form-table" style="margin-top:0;">
									<tr>
										<th scope="row"><label for="beds24_longlife_token"><?php echo esc_html__( 'Long-Life Access Token', 'channel-sync-for-homey' ); ?></label></th>
										<td>
											<input type="password" id="beds24_longlife_token" class="regular-text" placeholder="Enter your Long-Life Token" style="width: 80%;" />
										</td>
									</tr>
								</table>
								<div style="margin-top:15px;">
									<button type="button" id="homey-sync-longlife-btn" class="button homey-sync-btn-primary">
										🔗 <?php echo esc_html__( 'Connect with Token', 'channel-sync-for-homey' ); ?>
									</button>
									<span class="spinner test-connection-loader"></span>
								</div>
							</div>
						<?php endif; ?>

						<div id="connection-status-msg" class="homey-sync-status-box" style="display:none; margin-top: 15px;"></div>
					</div>
				<?php endif; ?>

				<!-- TAB 2: Room Mapping -->
				<?php
				if ( $active_tab === 'mappings' ) :
					$status_filter = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : 'publish';
					if ( ! in_array( $status_filter, array( 'publish', 'all' ), true ) ) {
						$status_filter = 'publish';
					}

					$post_status = ( 'all' === $status_filter ) ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish';
					?>
					<div class="homey-sync-card">
						<h3 class="homey-sync-card-title"><?php echo esc_html__( 'Listing & Room Mappings', 'channel-sync-for-homey' ); ?></h3>
						<p class="description">
							<?php echo esc_html__( 'Map each Homey Listing to its corresponding Property ID and Room ID configured in the Beds24 PMS Dashboard.', 'channel-sync-for-homey' ); ?>
						</p>

						<!-- Listing Status Radio Filter & Smart Actions Header -->
						<div class="homey-sync-actions-row">
							<div class="homey-sync-actions-left">
								<!-- Listing Status Radio Filter -->
								<div class="homey-sync-filter-wrap" style="background: #f6f7f7; padding: 10px 15px; border-radius: 4px; border: 1px solid #ccd0d4; display: flex; align-items: center; gap: 15px; margin:0;">
									<strong style="color: #2c3338;"><?php echo esc_html__( 'Filter Status:', 'channel-sync-for-homey' ); ?></strong>
									<label style="font-weight: 600; color: #1d2327; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
										<input type="radio" name="listing_status_filter" value="publish" <?php checked( $status_filter, 'publish' ); ?> style="margin: 0;" />
										<?php echo esc_html__( 'Only Published', 'channel-sync-for-homey' ); ?>
									</label>
									<label style="font-weight: 600; color: #1d2327; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
										<input type="radio" name="listing_status_filter" value="all" <?php checked( $status_filter, 'all' ); ?> style="margin: 0;" />
										<?php echo esc_html__( 'All Statuses', 'channel-sync-for-homey' ); ?>
									</label>
								</div>
							</div>

							<div class="homey-sync-actions-right">
								<?php if ( $beds24_connected ) : ?>
									<button type="button" id="homey-sync-automatch-btn" class="button button-secondary">
										✨ <?php echo esc_html__( 'Auto-Match Listings', 'channel-sync-for-homey' ); ?>
									</button>
									<button type="button" id="homey-sync-refresh-inv-btn" class="button button-secondary">
										🔄 <?php echo esc_html__( 'Refresh PMS Inventory', 'channel-sync-for-homey' ); ?>
									</button>
									<span class="spinner test-connection-loader" style="margin:0;"></span>
								<?php endif; ?>

								<label style="font-weight:600; color:#2c3338; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
									<input type="checkbox" id="homey-pms-manual-toggle" value="1" />
									<?php echo esc_html__( 'Manual Input Mode', 'channel-sync-for-homey' ); ?>
								</label>
							</div>
						</div>

						<div id="automatch-status-msg" class="homey-sync-status-box" style="display:none; margin: 15px 0;"></div>

						<div id="homey-mapping-table-wrap">
							<table class="widefat fixed striped" style="margin-top:20px;">
								<thead>
									<tr>
										<th style="width: 70px;"><?php echo esc_html__( 'Thumbnail', 'channel-sync-for-homey' ); ?></th>
										<th style="width: 30%;"><?php echo esc_html__( 'Listing Details', 'channel-sync-for-homey' ); ?></th>
										<th><?php echo esc_html__( 'Channel Property ID', 'channel-sync-for-homey' ); ?></th>
										<th><?php echo esc_html__( 'Channel Room ID', 'channel-sync-for-homey' ); ?></th>
										<th><?php echo esc_html__( 'Last Sync Status', 'channel-sync-for-homey' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$listings = get_posts(
										array(
											'post_type'   => 'listing',
											'posts_per_page' => 100,
											'post_status' => $post_status,
										)
									);

									$count = count( $listings );

									if ( empty( $listings ) ) :
										?>
										<tr>
											<td colspan="5" style="text-align:center; padding: 25px;">
												<strong>
													<?php
													if ( 'all' === $status_filter ) {
														echo esc_html__( 'No Homey Listings of any status found in the database.', 'channel-sync-for-homey' );
													} else {
														echo esc_html__( 'No published Homey Listings found in the database. Please create a listing or select "All Statuses" above.', 'channel-sync-for-homey' );
													}
													?>
												</strong>
											</td>
										</tr>
										<?php
									else :
										foreach ( $listings as $listing ) :
											$listing_id  = $listing->ID;
											$property_id = get_post_meta( $listing_id, '_homey_sync_cm_property_id', true );
											$room_id     = get_post_meta( $listing_id, '_homey_sync_cm_room_id', true );
											$last_sync   = get_post_meta( $listing_id, '_homey_sync_last_synced_at', true );

											$thumb = get_the_post_thumbnail( $listing_id, array( 50, 50 ), array( 'class' => 'mapping-thumb' ) );
											if ( empty( $thumb ) ) {
												$thumb = '<div class="mapping-thumb" style="display:flex;align-items:center;justify-content:center;font-size:20px;">🏠</div>';
											}
											?>
											<tr class="homey-pms-row" data-listing-id="<?php echo esc_attr( (string) $listing_id ); ?>">
												<td><?php echo wp_kses_post( $thumb ); ?></td>
												<td>
													<a href="<?php echo esc_url( get_permalink( $listing_id ) ); ?>" target="_blank" rel="noopener" style="text-decoration:none; color:#1d2327;" onmouseover="this.style.color='#f15a24';" onmouseout="this.style.color='#1d2327';">
														<strong class="homey-listing-title"><?php echo esc_html( $listing->post_title ); ?> 🔗</strong>
													</a>
													<span class="mapping-match-badge none"></span>
													<br/>
													<span class="description">ID: <?php echo esc_html( (string) $listing_id ); ?> | Status: <code><?php echo esc_html( get_post_status( $listing_id ) ); ?></code></span>
												</td>
												<td>
													<!-- Dynamic Property Selector -->
													<div class="homey-pms-dropdown-wrap">
														<select class="homey-pms-property-select">
															<option value=""><?php echo $beds24_connected ? esc_html__( '-- Select Property --', 'channel-sync-for-homey' ) : esc_html__( 'Connect API first', 'channel-sync-for-homey' ); ?></option>
														</select>
													</div>
													<!-- Fallback Manual Input -->
													<div class="homey-pms-manual-wrap">
														<input type="text" name="homey_sync_mappings[<?php echo esc_attr( (string) $listing_id ); ?>][property_id]" value="<?php echo esc_attr( (string) $property_id ); ?>" class="regular-text homey-pms-property-input" style="width: 90%;" placeholder="e.g. 210452" />
													</div>
												</td>
												<td>
													<!-- Dynamic Room Selector -->
													<div class="homey-pms-dropdown-wrap">
														<select class="homey-pms-room-select">
															<option value=""><?php echo $beds24_connected ? esc_html__( '-- Select Room --', 'channel-sync-for-homey' ) : esc_html__( 'Connect API first', 'channel-sync-for-homey' ); ?></option>
														</select>
													</div>
													<!-- Fallback Manual Input -->
													<div class="homey-pms-manual-wrap">
														<input type="text" name="homey_sync_mappings[<?php echo esc_attr( (string) $listing_id ); ?>][room_id]" value="<?php echo esc_attr( (string) $room_id ); ?>" class="regular-text homey-pms-room-input" style="width: 90%;" placeholder="e.g. 562134" />
													</div>
												</td>
												<td>
													<?php if ( ! empty( $last_sync ) ) : ?>
														<span style="color:#46b450; font-weight:600;">✔️ <?php echo esc_html__( 'Synced', 'channel-sync-for-homey' ); ?></span><br/>
														<span class="description" style="font-size:10px;"><?php echo esc_html( (string) $last_sync ); ?></span>
													<?php else : ?>
														<span style="color:#72777c;">— <?php echo esc_html__( 'Pending Sync', 'channel-sync-for-homey' ); ?></span>
													<?php endif; ?>
												</td>
											</tr>
											<?php
										endforeach;
									endif;
									?>
								</tbody>
							</table>
						</div>
					</div>
				<?php endif; ?>

				<!-- TAB 3: Advanced Settings and Schedules -->
				<?php if ( $active_tab === 'settings' ) : ?>
					<div class="homey-sync-card">
						<h3 class="homey-sync-card-title"><?php echo esc_html__( 'Modular Sync Toggles', 'channel-sync-for-homey' ); ?></h3>
						<p class="description"><?php echo esc_html__( 'Deconstruct synchronization processes. Turn modular aspects of the plugin on or off.', 'channel-sync-for-homey' ); ?></p>

						<table class="form-table" style="margin-top:15px;">
							<tr>
								<th scope="row"><?php echo esc_html__( 'Dynamic Price Overrides', 'channel-sync-for-homey' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="checkbox" name="feature_price_sync" value="1" <?php checked( $this->options['feature_price_sync'] ?? '0', '1' ); ?> />
											<strong><?php echo esc_html__( 'Enable Dynamic Daily Pricing Sync (Active)', 'channel-sync-for-homey' ); ?></strong>
										</label>
										<p class="description"><?php echo esc_html__( 'Pull Beds24 daily prices and apply prices to Homey nightly or daily rates.', 'channel-sync-for-homey' ); ?></p>
									</fieldset>
								</td>
							</tr>
							<tr class="coming-soon-card">
								<th scope="row"><?php echo esc_html__( 'Reservation Sync', 'channel-sync-for-homey' ); ?> <span class="coming-soon-badge"><?php echo esc_html__( 'Soon', 'channel-sync-for-homey' ); ?></span></th>
								<td>
									<fieldset>
										<label>
											<input type="checkbox" name="feature_booking_ingestion" value="1" disabled />
											<strong><?php echo esc_html__( 'Enable Real-time Bidirectional Reservation Sync (Planned)', 'channel-sync-for-homey' ); ?></strong>
										</label>
										<p class="description"><?php echo esc_html__( 'Enables a powerful bidirectional synchronization between the Homey theme and Beds24. Unlike standard static iCal syncing, this engine pushes rich reservation metadata, guest profiles, and financial amounts to Beds24, while dynamically importing external reservations to update and sync active bookings in the guest dashboard.', 'channel-sync-for-homey' ); ?></p>
									</fieldset>
								</td>
							</tr>
						</table>

						<h3 class="homey-sync-card-title" style="margin-top:30px;"><?php echo esc_html__( 'WP-Cron background schedule', 'channel-sync-for-homey' ); ?></h3>
						<p class="description"><?php echo esc_html__( 'Configure the background frequency at which dynamic rates are updated from the Beds24 server.', 'channel-sync-for-homey' ); ?></p>

						<table class="form-table">
							<tr>
								<th scope="row"><label for="cron_schedule"><?php echo esc_html__( 'Automated Sync Interval', 'channel-sync-for-homey' ); ?></label></th>
								<td>
									<select name="cron_schedule" id="cron_schedule">
										<option value="twicedaily" <?php selected( $this->options['cron_schedule'] ?? 'twicedaily', 'twicedaily' ); ?>><?php echo esc_html__( 'Every 12 Hours (Twice Daily)', 'channel-sync-for-homey' ); ?></option>
										<option value="daily" <?php selected( $this->options['cron_schedule'] ?? 'twicedaily', 'daily' ); ?>><?php echo esc_html__( 'Every 24 Hours (Once Daily)', 'channel-sync-for-homey' ); ?></option>
										<option value="weekly" <?php selected( $this->options['cron_schedule'] ?? 'twicedaily', 'weekly' ); ?>><?php echo esc_html__( 'Every 7 Days (Once Weekly)', 'channel-sync-for-homey' ); ?></option>
										<option value="monthly" <?php selected( $this->options['cron_schedule'] ?? 'twicedaily', 'monthly' ); ?>><?php echo esc_html__( 'Every 30 Days (Once Monthly)', 'channel-sync-for-homey' ); ?></option>
									</select>

									<?php
									$next_run = wp_next_scheduled( 'homey_channel_sync_cron_hook' );
									if ( $next_run ) :
										?>
										<p class="description" style="margin-top:10px; color:#0a4b78; font-weight:600;">
											⏰ <?php echo esc_html__( 'Next Automated Run:', 'channel-sync-for-homey' ); ?> 
											<code><?php echo esc_html( wp_date( 'l F j \a\t g:ia', $next_run ) ); ?></code>
										</p>
									<?php else : ?>
										<p class="description" style="margin-top:10px; color:#d63638; font-weight:600;">
											⚠️ <?php echo esc_html__( 'Background sync task is currently unscheduled. Connect your API and save settings to schedule.', 'channel-sync-for-homey' ); ?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>

						<h3 class="homey-sync-card-title" style="margin-top:30px;"><?php echo esc_html__( 'Manual Sync Trigger', 'channel-sync-for-homey' ); ?></h3>
						<p class="description"><?php echo esc_html__( 'Execute the full Beds24 daily pricing synchronization instantly bypassing the next automated background task run.', 'channel-sync-for-homey' ); ?></p>

						<?php
						$features_active   = ( '1' === ( $this->options['feature_price_sync'] ?? '0' ) );
						$sync_btn_disabled = ( ! $beds24_connected || ! $features_active ) ? 'disabled' : '';
						?>
						<div style="margin-top:20px;">
							<button type="button" id="homey-sync-trigger" class="button button-large homey-sync-btn-primary" <?php echo $sync_btn_disabled; ?>>
								🔄 <?php echo esc_html__( 'Sync Now (Force Update)', 'channel-sync-for-homey' ); ?>
							</button>
							
							<p id="homey-sync-conn-warning" class="description" style="color:#d63638; margin-top: 5px; <?php echo ! $beds24_connected ? '' : 'display:none;'; ?>">
								<?php echo esc_html__( 'Please establish API connection first to enable synchronization.', 'channel-sync-for-homey' ); ?>
							</p>

							<p id="homey-sync-feature-warning" class="description" style="color:#d63638; margin-top: 5px; <?php echo ( $beds24_connected && ! $features_active ) ? '' : 'display:none;'; ?>">
								<?php echo esc_html__( 'Please enable at least one active synchronization feature (e.g. Dynamic Price Overrides) above to enable manual triggering.', 'channel-sync-for-homey' ); ?>
							</p>
						</div>

						<div id="sync-progress-box" class="homey-sync-status-box" style="display:none; margin-top:20px;"></div>
						<pre id="sync-console" class="sync-progress-console"></pre>
					</div>
				<?php endif; ?>

				<!-- TAB 4: Debug Logs -->
				<?php
				if ( $active_tab === 'logs' ) :
					$logger       = Homey_Sync_Logger::get_instance();
					$log_content  = $logger->read_current_log();
					$download_url = add_query_arg(
						array(
							'action_download_logs' => '1',
							'security'             => wp_create_nonce( 'homey_sync_download_logs_nonce' ),
						),
						$settings_url
					);
					?>
					<div class="homey-sync-card">
						<h3 class="homey-sync-card-title"><?php echo esc_html__( 'API & Sync Debug Logging', 'channel-sync-for-homey' ); ?></h3>
						<p class="description">
							<?php echo esc_html__( 'Track API requests, payload responses, transient states, and cron telemetry for Beds24 PMS integration.', 'channel-sync-for-homey' ); ?>
						</p>

						<table class="form-table" style="margin-top:15px; margin-bottom: 25px;">
							<tr>
								<th scope="row"><?php echo esc_html__( 'Logging Toggle', 'channel-sync-for-homey' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="checkbox" name="enable_debug_log" value="1" <?php checked( $this->options['enable_debug_log'] ?? '0', '1' ); ?> />
											<strong><?php echo esc_html__( 'Enable API & Sync Debug Logging', 'channel-sync-for-homey' ); ?></strong>
										</label>
										<p class="description"><?php echo esc_html__( 'Only writes non-error synchronization events and detailed API telemetries to disk when checked.', 'channel-sync-for-homey' ); ?></p>
									</fieldset>
								</td>
							</tr>
						</table>

						<div class="homey-sync-actions-row" style="margin-bottom: 10px;">
							<div class="homey-sync-actions-left">
								<h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #2c3338;"><?php echo esc_html__( 'Active Monthly Log File Content:', 'channel-sync-for-homey' ); ?></h4>
							</div>
							<div class="homey-sync-actions-right">
								<a href="<?php echo esc_url( $download_url ); ?>" class="button button-secondary">
									📥 <?php echo esc_html__( 'Download Log File', 'channel-sync-for-homey' ); ?>
								</a>
								<button type="button" id="homey-sync-clear-logs-btn" class="button button-secondary" style="color: #d63638; border-color: #d63638;">
									🗑️ <?php echo esc_html__( 'Clear Log File', 'channel-sync-for-homey' ); ?>
								</button>
								<span class="spinner test-connection-loader" style="margin:0;"></span>
							</div>
						</div>

						<div id="automatch-status-msg" class="homey-sync-status-box" style="display:none; margin: 15px 0;"></div>

						<pre id="homey-sync-log-viewer-console" style="background: #1e1e1e; color: #f1f1f1; font-family: monospace; padding: 18px; border-radius: 4px; max-height: 400px; overflow-y: auto; font-size: 12px; line-height: 1.5; border: 1px solid #333; margin-top: 10px;"><?php echo esc_html( $log_content ); ?></pre>
					</div>
				<?php endif; ?>

				<!-- Form Actions Footer -->
				<div style="margin-top:20px;">
					<input type="submit" name="submit_save" class="button button-primary button-large" value="<?php echo esc_attr__( 'Save Settings', 'channel-sync-for-homey' ); ?>" />
				</div>
			</form>
		</div>
		<?php
	}
}
