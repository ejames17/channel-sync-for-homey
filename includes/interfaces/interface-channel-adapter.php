<?php
/**
 * Interface definition for all Channel Manager Adapters.
 *
 * This contract ensures all channel manager adapters implement standard
 * methods for credential validation, connection testing, and rate fetching.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

interface Homey_Sync_Adapter_Interface {

	/**
	 * Retrieve detailed description of the last error.
	 *
	 * @return string Detailed error description.
	 */
	public function get_last_error(): string;

	/**
	 * Verify that established credentials have active connection privileges.
	 *
	 * @param array $credentials Configured connection credentials.
	 * @return bool True if connected.
	 */
	public function test_connection( array $credentials ): bool;

	/**
	 * Enforce basic requirements check on setting parameters.
	 *
	 * @param array $credentials Configured connection credentials.
	 * @return bool True if connection formats are valid.
	 */
	public function validate_credentials( array $credentials ): bool;

	/**
	 * Request and pull daily rate calendars from external providers.
	 *
	 * Fetches 365 days of pricing and maps rates into native post definitions.
	 *
	 * @param array $room_mappings Array of mappings keyed by local WP Post ID.
	 * @return array Nested array of dates and daily rates keyed by local listing ID.
	 */
	public function get_rates( array $room_mappings ): array;
}
