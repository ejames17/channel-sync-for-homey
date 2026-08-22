<?php
/**
 * Uninstall file.
 *
 * Triggered when the plugin is deleted via the WordPress Admin dashboard.
 * Removes all options, transient caches, scheduled WP-Cron hooks, and reverts
 * listing post meta overrides back to their pre-plugin static defaults.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

// Exit if accessed directly or not uninstalled through WP Admin.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 1. Revert and clean up Listing post metadata
$listings = get_posts( [
	'post_type'      => 'listing',
	'posts_per_page' => -1,
	'post_status'    => 'any',
] );

if ( ! empty( $listings ) ) {
	// Temporarily unhook homey-core's recursive post-meta save action during rollback
	$has_action_added   = has_action( 'added_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ] );
	$has_action_updated = has_action( 'updated_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ] );

	if ( false !== $has_action_added ) {
		remove_action( 'added_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ], 10 );
	}
	if ( false !== $has_action_updated ) {
		remove_action( 'updated_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ], 10 );
	}

	foreach ( $listings as $listing ) {
		$listing_id = $listing->ID;

		// Restore standard nightly price from backup if it exists
		$original_night_price = get_post_meta( $listing_id, '_homey_sync_original_night_price', true );
		if ( ! empty( $original_night_price ) ) {
			update_post_meta( $listing_id, 'homey_night_price', $original_night_price );
			update_post_meta( $listing_id, 'homey_nightly_price', $original_night_price );
		}

		// Restore day date price from backup if it exists
		$original_day_date_price = get_post_meta( $listing_id, '_homey_sync_original_day_date_price', true );
		if ( ! empty( $original_day_date_price ) ) {
			update_post_meta( $listing_id, 'homey_day_date_price', $original_day_date_price );
		}

		// Delete all synced custom metadata keys cleanly from database
		delete_post_meta( $listing_id, '_homey_sync_cm_property_id' );
		delete_post_meta( $listing_id, '_homey_sync_cm_room_id' );
		delete_post_meta( $listing_id, '_homey_sync_daily_rates' );
		delete_post_meta( $listing_id, '_homey_sync_base_price_override' );
		delete_post_meta( $listing_id, '_homey_sync_last_synced_at' );
		delete_post_meta( $listing_id, '_homey_sync_original_night_price' );
		delete_post_meta( $listing_id, '_homey_sync_original_day_date_price' );
		delete_post_meta( $listing_id, 'homey_custom_period' ); // Removes custom periods calendar entirely
	}

	// Re-hook the actions (standard security practice even during uninstall request cycle)
	if ( false !== $has_action_added ) {
		add_action( 'added_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ], 10, 4 );
	}
	if ( false !== $has_action_updated ) {
		add_action( 'updated_post_meta', [ 'Homey_Listing_Post_Type', 'save_guests_meta' ], 10, 4 );
	}
}

// 2. Delete options stored in wp_options table
delete_option( 'homey_channel_sync_options' );
delete_option( 'homey_sync_execution_logs' );

// 3. Delete cached transient records
delete_transient( 'homey_sync_pms_inventory' );

// 4. Clear scheduled background WP-Cron tasks
wp_clear_scheduled_hook( 'homey_channel_sync_cron_hook' );
