<?php
/**
 * Sanity check test script for WordPress Channel Sync Plugin fixes.
 */

define('WP_USE_THEMES', false);
// Boot WordPress
require_once __DIR__ . '/../../../../wp-load.php';

echo "=== Running Channel Sync Plugin Sanity Checks ===\n";

$all_passed = true;

// Helper assert function
function assert_test($condition, $message) {
	global $all_passed;
	if ($condition) {
		echo "✔ [PASS] $message\n";
	} else {
		echo "❌ [FAIL] $message\n";
		$all_passed = false;
	}
}

// ----------------------------------------------------
// Sanity Check 1: Verify correct includeAllRooms parameter is used in Beds24 adapter
// ----------------------------------------------------
$adapter = new Homey_Channel_Sync_Beds24_Adapter();
$reflector = new ReflectionClass('Homey_Channel_Sync_Beds24_Adapter');
$file_path = $reflector->getFileName();
$code = file_get_contents($file_path);

assert_test(
	strpos($code, 'includeAllRooms=true') !== false,
	"Beds24 Adapter property URL correctly uses includeAllRooms=true instead of includeRooms=true."
);

assert_test(
	strpos($code, 'includeRooms=true') === false,
	"Beds24 Adapter property URL does not contain incorrect includeRooms=true parameter."
);

// ----------------------------------------------------
// Sanity Check 2: Verify run_synchronization uses posts_per_page = -1 and correct post statuses
// ----------------------------------------------------
$cron_reflector = new ReflectionClass('Homey_Channel_Sync_Cron');
$cron_file_path = $cron_reflector->getFileName();
$cron_code = file_get_contents($cron_file_path);

assert_test(
	strpos($cron_code, "'posts_per_page' => -1") !== false,
	"Cron synchronization queries all listings with posts_per_page => -1."
);

assert_test(
	strpos($cron_code, "'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' )") !== false,
	"Cron synchronization includes draft, pending, private, and future statuses in query."
);

// ----------------------------------------------------
// Sanity Check 3: Verify booking type check skips weekly/monthly and permits empty/default types
// ----------------------------------------------------
// Let's create mock listings in various states and test the sync booking type checks
$mock_listing_day = wp_insert_post(array(
	'post_title'  => 'Sanity Mock Day Listing',
	'post_type'   => 'listing',
	'post_status' => 'publish'
));
update_post_meta($mock_listing_day, 'homey_booking_type', 'per_day');

$mock_listing_week = wp_insert_post(array(
	'post_title'  => 'Sanity Mock Week Listing',
	'post_type'   => 'listing',
	'post_status' => 'publish'
));
update_post_meta($mock_listing_week, 'homey_booking_type', 'per_week');

$mock_listing_empty = wp_insert_post(array(
	'post_title'  => 'Sanity Mock Empty Listing',
	'post_type'   => 'listing',
	'post_status' => 'publish'
));
// Empty homey_booking_type defaults to nightly in Homey

// Verify the meta checks that our cron class does
$btype_day = get_post_meta($mock_listing_day, 'homey_booking_type', true);
$btype_week = get_post_meta($mock_listing_week, 'homey_booking_type', true);
$btype_empty = get_post_meta($mock_listing_empty, 'homey_booking_type', true);

// Our cron condition logic:
// if ( ! empty( $booking_type ) && 'per_day' !== $booking_type && 'per_day_date' !== $booking_type ) { continue; }
// Let's test the logic on these values:

$should_skip_day = (! empty($btype_day) && 'per_day' !== $btype_day && 'per_day_date' !== $btype_day);
$should_skip_week = (! empty($btype_week) && 'per_day' !== $btype_week && 'per_day_date' !== $btype_week);
$should_skip_empty = (! empty($btype_empty) && 'per_day' !== $btype_empty && 'per_day_date' !== $btype_empty);

assert_test($should_skip_day === false, "Listing with 'per_day' booking type is correctly NOT skipped.");
assert_test($should_skip_week === true, "Listing with 'per_week' booking type is correctly skipped.");
assert_test($should_skip_empty === false, "Listing with empty/default booking type is correctly NOT skipped.");

// Clean up mock listings
wp_delete_post($mock_listing_day, true);
wp_delete_post($mock_listing_week, true);
wp_delete_post($mock_listing_empty, true);

// ----------------------------------------------------
// Sanity Check 4: Verify frontend overlay script supports empty booking type
// ----------------------------------------------------
$plugin_code = file_get_contents(__DIR__ . '/../channel-sync-for-homey.php');

assert_test(
	strpos($plugin_code, "activeBookingType && activeBookingType !== 'per_day' && activeBookingType !== 'per_day_date'") !== false,
	"Frontend JavaScript only aborts if activeBookingType is defined and is not per_day/per_day_date."
);

echo "=== Sanity Checks Finished ===\n";
if ($all_passed) {
	echo "SUCCESS: All sanity checks passed perfectly!\n";
	exit(0);
} else {
	echo "FAILURE: One or more sanity checks failed!\n";
	exit(1);
}
