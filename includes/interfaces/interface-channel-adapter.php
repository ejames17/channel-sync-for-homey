<?php
declare(strict_types=1);

/**
 * Interface definition for all Channel Manager Adapters.
 *
 * This contract ensures all channel manager adapters implement standard
 * methods for credential validation, connection testing, and rate fetching.
 *
 * @package HomeyChannelSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

interface Homey_Sync_Adapter_Interface {

	/**
	 * Test connection to the Channel Manager using provided credentials.
	 *
	 * Performs a remote test call to verify authentication credentials.
	 *
	 * @param array $credentials Association of credentials (e.g. beds24_api_key, beds24_account_id).
	 * @return bool True if the connection succeeds, false otherwise.
	 */
	public function test_connection( array $credentials ): bool;

	/**
	 * Validate that the credentials array contains all required keys and format is correct.
	 *
	 * @param array $credentials Association of credentials.
	 * @return bool True if structural checks pass, false otherwise.
	 */
	public function validate_credentials( array $credentials ): bool;

	/**
	 * Fetch rate information for a list of mapped listings and their external IDs.
	 *
	 * Retrieves pricing records for the external property and room combinations.
	 *
	 * @param array $room_mappings Array of mappings keyed by local WP Post ID.
	 * @return array Nested array of dates and daily rates keyed by local listing ID.
	 */
	public function get_rates( array $room_mappings ): array;
}
