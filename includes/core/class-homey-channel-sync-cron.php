<?php
/**
 * Cron Manager Class.
 *
 * Coordinates scheduled daily pricing updates from external channel managers.
 * Integrates WP-Cron with custom execution routines and exposes the manual AJAX
 * trigger endpoints.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cron Manager Class.
 *
 * Coordinates scheduled daily pricing updates from external channel managers.
 * Integrates WP-Cron with custom execution routines and exposes the manual AJAX
 * trigger endpoints.
 *
 * @package HomeyChannelSync
 */
class Homey_Channel_Sync_Cron {

	/**
	 * Options array cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * Registers action hooks, filters, and Ajax listeners.
	 */
	public function __construct() {
		$this->options = get_option( 'homey_channel_sync_options', array() );

		// Hook WP-Cron schedules and actions.
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		add_action( 'homey_channel_sync_cron_hook', array( $this, 'run_synchronization' ) );

		// Register Manual AJAX execution endpoint.
		add_action( 'wp_ajax_homey_sync_run_now', array( $this, 'ajax_run_now' ) );
	}

	/**
	 * Filter scheduled hooks to append custom intervals.
	 *
	 * Registers 'monthly' interval if missing.
	 *
	 * @param array $schedules Standard WordPress Cron schedules.
	 * @return array Modified cron intervals.
	 */
	public function filter_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => esc_html__( 'Once Monthly', 'channel-sync-for-homey' ),
			);
		}
		return $schedules;
	}

	/**
	 * Master execution sequence for rate synchronizations.
	 *
	 * Iterates over mapped listings, retrieves the latest pricing via the active
	 * adapter, and persists data to database structures.
	 *
	 * @return array{success: bool, updated: int, message: string} Status summary.
	 */
	public function run_synchronization(): array {
		$start_time = microtime( true );

		// Stop execution if price override toggle is turned off.
		$price_sync_enabled = $this->options['feature_price_sync'] ?? '0';
		if ( '1' !== $price_sync_enabled ) {
			return array(
				'success' => false,
				'updated' => 0,
				'message' => esc_html__( 'Dynamic Price Sync feature is disabled in configuration.', 'channel-sync-for-homey' ),
			);
		}

		// Retrieve list of all listing records to map.
		$listings = get_posts(
			array(
				'post_type'      => 'listing',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			)
		);

		if ( empty( $listings ) ) {
			return array(
				'success' => true,
				'updated' => 0,
				'message' => esc_html__( 'No Homey listings found to map.', 'channel-sync-for-homey' ),
			);
		}

		$mappings    = array();
		$listing_map = array();

		// Extract mapped property/room IDs.
		foreach ( $listings as $listing ) {
			$listing_id  = $listing->ID;
			$property_id = get_post_meta( $listing_id, '_homey_sync_cm_property_id', true );
			$room_id     = get_post_meta( $listing_id, '_homey_sync_cm_room_id', true );

			if ( ! empty( $property_id ) && ! empty( $room_id ) ) {
				$mappings[ $listing_id ]    = array(
					'property_id' => $property_id,
					'room_id'     => $room_id,
				);
				$listing_map[ $listing_id ] = $listing->post_title;
			}
		}

		if ( empty( $mappings ) ) {
			return array(
				'success' => false,
				'updated' => 0,
				'message' => esc_html__( 'No mappings have been configured for listing records.', 'channel-sync-for-homey' ),
			);
		}

		// Instantiate Adapter based on config.
		$active_channel = $this->options['active_channel'] ?? 'beds24';
		$adapter        = null;

		if ( 'beds24' === $active_channel ) {
			$adapter = new Homey_Channel_Sync_Beds24_Adapter();

			// Dynamically retrieve and auto-refresh the access token.
			$token = $adapter->get_valid_access_token( $this->options );
			if ( empty( $token ) ) {
				return array(
					'success' => false,
					'updated' => 0,
					'message' => esc_html__( 'Synchronization aborted: No valid Beds24 access token. Please authenticate in plugin settings.', 'channel-sync-for-homey' ),
				);
			}
		}

		if ( ! $adapter ) {
			return array(
				'success' => false,
				'updated' => 0,
				'message' => esc_html__( 'Selected Channel Manager is currently unavailable.', 'channel-sync-for-homey' ),
			);
		}

		// Fetch rates from the channel manager adapter.
		$retrieved_rates = $adapter->get_rates( $mappings );
		$updated_count   = 0;

		// Temporarily unhook homey-core's recursive post-meta save action to prevent severe memory exhaustion/recursion leaks during bulk sync.
		$has_action_added   = has_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );
		$has_action_updated = has_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ) );

		if ( false !== $has_action_added ) {
			remove_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
		}
		if ( false !== $has_action_updated ) {
			remove_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10 );
		}

		foreach ( $retrieved_rates as $listing_id_str => $daily_rates ) {
			$listing_id = (int) $listing_id_str;
			if ( empty( $daily_rates ) ) {
				continue;
			}

			// Verify if the listing is configured for nightly/daily bookings (skip hourly, weekly, monthly, etc.).
			$booking_type = get_post_meta( $listing_id, 'homey_booking_type', true );
			if ( ! empty( $booking_type ) && 'per_day' !== $booking_type && 'per_day_date' !== $booking_type ) {
				continue;
			}

			// Store nested dynamic rates array as meta.
			update_post_meta( $listing_id, '_homey_sync_daily_rates', $daily_rates );

			// Merge daily rates into Homey theme's native custom periods array.
			$custom_period = get_post_meta( $listing_id, 'homey_custom_period', true );
			if ( ! is_array( $custom_period ) ) {
				$custom_period = array();
			}

			foreach ( $daily_rates as $date_str => $rate ) {
				try {
					$date_obj  = new DateTime( $date_str );
					$timestamp = $date_obj->getTimestamp();

					$custom_period[ $timestamp ] = array(
						'night_price'   => $rate,
						'weekend_price' => $rate,
						'guest_price'   => 0.0,
					);
				} catch ( Exception $e ) {
					continue;
				}
			}

			// Update the native Homey theme custom period meta field.
			update_post_meta( $listing_id, 'homey_custom_period', $custom_period );

			// Extract first day rate as listing's standard nightly base price override.
			reset( $daily_rates );
			$first_day_rate = current( $daily_rates );

			if ( false !== $first_day_rate ) {
				// Backup original base price before first override.
				$original_price  = get_post_meta( $listing_id, 'homey_night_price', true );
				$existing_backup = get_post_meta( $listing_id, '_homey_sync_original_night_price', true );

				if ( empty( $existing_backup ) && ! empty( $original_price ) ) {
					update_post_meta( $listing_id, '_homey_sync_original_night_price', (string) $original_price );
				}

				// Homey Theme custom field fields.
				if ( 'per_day_date' === $booking_type ) {
					$original_date_price  = get_post_meta( $listing_id, 'homey_day_date_price', true );
					$existing_date_backup = get_post_meta( $listing_id, '_homey_sync_original_day_date_price', true );
					if ( empty( $existing_date_backup ) && ! empty( $original_date_price ) ) {
						update_post_meta( $listing_id, '_homey_sync_original_day_date_price', (string) $original_date_price );
					}

					update_post_meta( $listing_id, 'homey_day_date_price', (string) $first_day_rate );
				} else {
					update_post_meta( $listing_id, 'homey_night_price', (string) $first_day_rate );
					update_post_meta( $listing_id, 'homey_nightly_price', (string) $first_day_rate ); // Keep backup.
				}
				// Also update a backup sync field.
				update_post_meta( $listing_id, '_homey_sync_base_price_override', (string) $first_day_rate );
			}

			// Update timestamp marker.
			$now_string = current_time( 'mysql' );
			update_post_meta( $listing_id, '_homey_sync_last_synced_at', $now_string );

			$updated_count++;
		}

		// Re-hook the actions to preserve system state.
		if ( false !== $has_action_added ) {
			add_action( 'added_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
		}
		if ( false !== $has_action_updated ) {
			add_action( 'updated_post_meta', array( 'Homey_Listing_Post_Type', 'save_guests_meta' ), 10, 4 );
		}

		$time_elapsed = round( microtime( true ) - $start_time, 2 );
		$log_entry    = array(
			'timestamp' => current_time( 'mysql' ),
			'updated'   => $updated_count,
			'elapsed'   => $time_elapsed,
			'status'    => 'success',
		);

		// Persist execution log history.
		$sync_logs = get_option( 'homey_sync_execution_logs', array() );
		array_unshift( $sync_logs, $log_entry );
		$sync_logs = array_slice( $sync_logs, 0, 10 ); // Maintain only latest 10 runs.
		update_option( 'homey_sync_execution_logs', $sync_logs );

		return array(
			'success' => true,
			'updated' => $updated_count,
			'message' => sprintf(
				/* translators: 1: Count of records, 2: Elapsed seconds */
				esc_html__( 'Rates synchronized successfully! Updated %1$d listings in %2$s seconds.', 'channel-sync-for-homey' ),
				$updated_count,
				(string) $time_elapsed
			),
		);
	}

	/**
	 * Callback handler for manual force-sync request via AJAX.
	 */
	public function ajax_run_now(): void {
		check_ajax_referer( 'homey_sync_run_now_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized permissions context.', 'channel-sync-for-homey' ) ) );
		}

		$result = $this->run_synchronization();

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => $result['message'],
					'details' => sprintf(
						/* translators: 1: Record count */
						esc_html__( 'Database write sequence completed for %d entities.', 'channel-sync-for-homey' ),
						$result['updated']
					),
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Rate synchronization failed.', 'channel-sync-for-homey' ),
					'error'   => $result['message'],
				)
			);
		}
	}
}
