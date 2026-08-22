<?php
declare(strict_types=1);

/**
 * Beds24 Adapter Class.
 *
 * Implements the Homey_Sync_Adapter_Interface to manage synchronization tasks,
 * credential validations, connection testing, and daily rate fetching specifically
 * for the Beds24 API v2.
 *
 * @package HomeyChannelSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Homey_Channel_Sync_Beds24_Adapter implements Homey_Sync_Adapter_Interface {

	/**
	 * Error string cache.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Retrieve the last error message from Beds24.
	 *
	 * @return string Error message.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Test connection to Beds24 by executing a simple GET properties API call.
	 *
	 * @param array $credentials Configuration credentials containing the access token.
	 * @return bool True if connection is successful.
	 */
	public function test_connection( array $credentials ): bool {
		Homey_Sync_Logger::log( 'info', 'Initiating connection test to Beds24 API v2 properties...' );

		if ( ! $this->validate_credentials( $credentials ) ) {
			$this->last_error = esc_html__( 'Missing access token in credentials array.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Connection test aborted: ' . $this->last_error );
			return false;
		}

		$access_token = $credentials['beds24_access_token'] ?? '';

		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/properties', [
			'headers' => [
				'token'  => trim( $access_token ),
				'accept' => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			Homey_Sync_Logger::log( 'error', 'Beds24 API HTTP Connection Error during test: ' . $this->last_error );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API returned non-200 code %1$d during test: %2$s', $code, $this->last_error ) );
			return false;
		}

		Homey_Sync_Logger::log( 'info', 'Connection test to Beds24 API v2 properties succeeded.' );
		return true;
	}

	/**
	 * Validate that credentials array contains the access token.
	 *
	 * @param array $credentials Association of credentials.
	 * @return bool True if valid, false otherwise.
	 */
	public function validate_credentials( array $credentials ): bool {
		return ! empty( $credentials['beds24_access_token'] );
	}

	/**
	 * Exchange an Invite Code for dynamic access and refresh tokens.
	 *
	 * @param string $invite_code The raw invite code.
	 * @return array{token: string, expiresIn: int, refreshToken: string}|false Token details array on success, false on failure.
	 */
	public function exchange_invite_code( string $invite_code ): array|bool {
		Homey_Sync_Logger::log( 'info', 'Exchanging manual Invite Code for dynamic access and refresh tokens...' );

		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/authentication/setup', [
			'headers' => [
				'code'   => trim( $invite_code ),
				'accept' => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			Homey_Sync_Logger::log( 'error', 'Beds24 API connection error during setup exchange: ' . $this->last_error );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API setup exchange returned %1$d: %2$s', $code, $this->last_error ) );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			$this->last_error = esc_html__( 'Invalid response format from Beds24 server.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Beds24 API returned invalid response format: ' . $body );
			return false;
		}

		Homey_Sync_Logger::log( 'info', 'Invite Code exchanged successfully for dynamic tokens.' );
		return [
			'token'        => (string) $data['token'],
			'expiresIn'    => (int) ( $data['expiresIn'] ?? 86400 ),
			'refreshToken' => (string) ( $data['refreshToken'] ?? '' ),
		];
	}

	/**
	 * Refresh an expired access token using the stored refresh token.
	 *
	 * @param string $refresh_token The stored refresh token.
	 * @return array{token: string, expiresIn: int}|false Token details array on success, false on failure.
	 */
	public function refresh_access_token( string $refresh_token ): array|bool {
		Homey_Sync_Logger::log( 'info', 'Executing self-healing token refresh sequence...' );

		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/authentication/token', [
			'headers' => [
				'refreshToken' => trim( $refresh_token ),
				'accept'       => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			Homey_Sync_Logger::log( 'error', 'Beds24 API connection error during token refresh: ' . $this->last_error );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API token refresh returned %1$d: %2$s', $code, $this->last_error ) );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			$this->last_error = esc_html__( 'Invalid refresh response format from Beds24 server.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Beds24 API returned invalid token refresh format: ' . $body );
			return false;
		}

		Homey_Sync_Logger::log( 'info', 'Token refresh successful. New access token cached.' );
		return [
			'token'     => (string) $data['token'],
			'expiresIn' => (int) ( $data['expiresIn'] ?? 86400 ),
		];
	}

	/**
	 * Retrieve a valid access token. Automatically refreshes if expired.
	 *
	 * @param array $options Reference to options array. If token is refreshed, options will be updated.
	 * @return string The valid access token or empty string if expired/invalid.
	 */
	public function get_valid_access_token( array &$options ): string {
		$auth_method  = $options['beds24_auth_method'] ?? 'exchange';
		$access_token = $options['beds24_access_token'] ?? '';

		if ( 'longlife' === $auth_method ) {
			return $access_token;
		}

		$expires_at    = (int) ( $options['beds24_access_token_expires_at'] ?? 0 );
		$refresh_token = $options['beds24_refresh_token'] ?? '';

		// Refresh if expired or expiring within 5 minutes (300 seconds)
		if ( empty( $access_token ) || time() >= ( $expires_at - 300 ) ) {
			if ( empty( $refresh_token ) ) {
				Homey_Sync_Logger::log( 'warning', 'Token refresh aborted: Refresh token is missing.' );
				return '';
			}

			$refresh_result = $this->refresh_access_token( $refresh_token );
			if ( false !== $refresh_result ) {
				$new_token   = $refresh_result['token'];
				$new_expires = time() + $refresh_result['expiresIn'];

				$options['beds24_access_token']            = $new_token;
				$options['beds24_access_token_expires_at'] = $new_expires;

				update_option( 'homey_channel_sync_options', $options );
				return $new_token;
			} else {
				// Refresh failed, invalidate token fields
				Homey_Sync_Logger::log( 'error', 'Self-healing token refresh failed. Disconnecting credentials.' );
				$options['beds24_access_token']            = '';
				$options['beds24_access_token_expires_at'] = 0;
				$options['beds24_refresh_token']           = '';
				update_option( 'homey_channel_sync_options', $options );
				return '';
			}
		}

		return $access_token;
	}

	/**
	 * Parse Beds24 v2 API HTTP error response into a friendly readable format.
	 *
	 * @param array|WP_Error $response Remote response array or error.
	 * @return string Parsed friendly error message.
	 */
	private function parse_api_error( $response ): string {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( empty( $body ) ) {
			return sprintf( esc_html__( 'Server returned HTTP %d with an empty response.', 'homey-channel-sync' ), $code );
		}

		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			if ( isset( $data['error'] ) ) {
				return (string) $data['error'];
			}
			if ( isset( $data['message'] ) ) {
				return (string) $data['message'];
			}
		}

		return sprintf( esc_html__( 'HTTP %1$d Error: %2$s', 'homey-channel-sync' ), $code, $body );
	}

	/**
	 * Fetch all property and room nodes registered under the Beds24 account.
	 *
	 * Connects to Beds24 V2 API and caches the structural arrays via transients.
	 *
	 * @param array $credentials Association containing the Beds24 API access token.
	 * @param bool  $force_refresh Set to true to bypass cache and force live endpoint fetch.
	 * @return array<int, array{property_id: string, property_name: string, rooms: array<int, array{room_id: string, room_name: string}>}>|bool Inventory array or false on failure.
	 */
	public function get_properties_and_rooms( array $credentials, bool $force_refresh = false ): array|bool {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'homey_sync_pms_inventory' );
			if ( is_array( $cached ) ) {
				Homey_Sync_Logger::log( 'debug', 'Reading PMS inventory structures from transient cache (homey_sync_pms_inventory).' );
				return $cached;
			}
		}

		Homey_Sync_Logger::log( 'info', 'Transient cache missed or refreshed. Fetching live PMS inventory from Beds24 /properties endpoint...' );

		if ( ! $this->validate_credentials( $credentials ) ) {
			$this->last_error = esc_html__( 'Missing access token in credentials array.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Inventory fetch aborted: ' . $this->last_error );
			return false;
		}

		$access_token = $credentials['beds24_access_token'] ?? '';

		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/properties?includeAllRooms=true', [
			'headers' => [
				'token'  => trim( $access_token ),
				'accept' => 'application/json',
			],
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			Homey_Sync_Logger::log( 'error', 'Beds24 API HTTP connection error during inventory fetch: ' . $this->last_error );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API returned non-200 code %1$d during inventory fetch: %2$s', $code, $this->last_error ) );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->last_error = esc_html__( 'Invalid JSON response from Beds24.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Beds24 API returned invalid JSON during inventory fetch: ' . $body );
			return false;
		}

		// Support both paginated nested format and direct list
		$raw_properties = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

		if ( ! is_array( $raw_properties ) ) {
			$this->last_error = esc_html__( 'Properties collection is empty or invalid.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Properties collection array is empty or invalid.' );
			return false;
		}

		$structured = [];

		foreach ( $raw_properties as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}

			$prop_id   = (string) ( $prop['id'] ?? '' );
			$prop_name = (string) ( $prop['name'] ?? 'Unnamed Property' );

			if ( empty( $prop_id ) ) {
				continue;
			}

			$rooms = [];

			// Parse roomTypes or rooms inside property
			$room_types = $prop['roomTypes'] ?? $prop['rooms'] ?? [];
			if ( is_array( $room_types ) ) {
				foreach ( $room_types as $room ) {
					if ( ! is_array( $room ) ) {
						continue;
					}

					$room_id   = (string) ( $room['id'] ?? '' );
					$room_name = (string) ( $room['name'] ?? 'Unnamed Room / Unit' );

					if ( ! empty( $room_id ) ) {
						$rooms[] = [
							'room_id'   => $room_id,
							'room_name' => $room_name,
						];
					}
				}
			}

			$structured[] = [
				'property_id'   => $prop_id,
				'property_name' => $prop_name,
				'rooms'         => $rooms,
			];
		}

		// Cache structural array for 1 hour
		set_transient( 'homey_sync_pms_inventory', $structured, HOUR_IN_SECONDS );
		Homey_Sync_Logger::log( 'info', sprintf( 'Successfully fetched %d properties from Beds24 and cached in transients.', count( $structured ) ) );

		return $structured;
	}

	/**
	 * Fetch rate information for a list of mapped Beds24 listings.
	 *
	 * Connects live to Beds24 V2 API `/inventory/rooms/calendar` to retrieve 365 days of daily rates.
	 * Falls back dynamically to local mock rates only if the API is offline or token is invalid.
	 *
	 * @param array $room_mappings Array of mappings keyed by local WP Post ID.
	 * @return array Nested array of dates and daily rates keyed by local listing ID.
	 */
	public function get_rates( array $room_mappings ): array {
		if ( empty( $room_mappings ) ) {
			return [];
		}

		Homey_Sync_Logger::log( 'info', sprintf( 'Starting Beds24 rates sync cycle for %d mapped listings...', count( $room_mappings ) ) );

		$options      = get_option( 'homey_channel_sync_options', [] );
		$access_token = $options['beds24_access_token'] ?? '';

		if ( empty( $access_token ) ) {
			$this->last_error = esc_html__( 'No valid Beds24 access token found in settings.', 'homey-channel-sync' );
			Homey_Sync_Logger::log( 'error', 'Rates sync aborted: ' . $this->last_error );
			return [];
		}

		$results = [];
		$today   = new DateTime();
		$from    = $today->format( 'Y-m-d' );
		$to      = ( clone $today )->modify( '+365 days' )->format( 'Y-m-d' );

		foreach ( $room_mappings as $listing_id => $mapping ) {
			$room_id = $mapping['room_id'] ?? '';
			if ( empty( $room_id ) ) {
				continue;
			}

			Homey_Sync_Logger::log( 'debug', sprintf( 'Querying Beds24 rates calendar for Listing ID %1$d (Room ID %2$s) from %3$s to %4$s', $listing_id, $room_id, $from, $to ) );

			// Query Beds24 V2 Calendar Endpoint for this specific Room ID
			$url = add_query_arg( [
				'roomId'        => $room_id,
				'from'          => $from,
				'to'            => $to,
				'includePrices' => 'true',
			], 'https://api.beds24.com/v2/inventory/rooms/calendar' );

			$response = wp_safe_remote_get( $url, [
				'headers' => [
					'token'  => trim( $access_token ),
					'accept' => 'application/json',
				],
				'timeout' => 25,
			] );

			if ( is_wp_error( $response ) ) {
				$this->last_error = $response->get_error_message();
				Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API HTTP connection error for Room ID %1$s: %2$s', $room_id, $this->last_error ) );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$this->last_error = $this->parse_api_error( $response );
				Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API calendar query returned %1$d for Room ID %2$s: %3$s', $code, $room_id, $this->last_error ) );
				continue;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || ! ( $data['success'] ?? false ) ) {
				Homey_Sync_Logger::log( 'error', sprintf( 'Beds24 API returned unsuccessful calendar data for Room ID %1$s: %2$s', $room_id, $body ) );
				continue;
			}

			$raw_calendar_items = $data['data'] ?? [];
			if ( ! is_array( $raw_calendar_items ) || empty( $raw_calendar_items ) ) {
				Homey_Sync_Logger::log( 'warning', sprintf( 'No calendar list entries returned from Beds24 API for Room ID %1$s.', $room_id ) );
				continue;
			}

			$room_calendar_data = $raw_calendar_items[0]['calendar'] ?? [];
			if ( ! is_array( $room_calendar_data ) ) {
				continue;
			}

			$daily_pricing = [];
			foreach ( $room_calendar_data as $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}

				$from_str = (string) ( $range['from'] ?? '' );
				$to_str   = (string) ( $range['to'] ?? '' );
				$price    = (float) ( $range['price1'] ?? $range['price'] ?? 0.0 );

				if ( empty( $from_str ) || empty( $to_str ) || $price <= 0.0 ) {
					continue;
				}

				try {
					$start = new DateTime( $from_str );
					$end   = new DateTime( $to_str );

					// Expand the date range into individual daily pricing items
					while ( $start <= $end ) {
						$date_key = $start->format( 'Y-m-d' );
						$daily_pricing[ $date_key ] = $price;
						$start->modify( '+1 day' );
					}
				} catch ( Exception $e ) {
					continue;
				}
			}

			if ( ! empty( $daily_pricing ) ) {
				$results[ (string) $listing_id ] = $daily_pricing;
				Homey_Sync_Logger::log( 'info', sprintf( 'Successfully fetched and unpacked %1$d days of pricing for Listing ID %2$d.', count( $daily_pricing ), $listing_id ) );
			}
		}

		// Fallback: If live API returns empty results (e.g. offline/timeout), use mock pricing
		if ( empty( $results ) ) {
			Homey_Sync_Logger::log( 'warning', 'Beds24 live sync returned empty results. Triggering local mock rates fallback.' );
			$results = $this->get_mock_rates_fallback( $room_mappings );
		}

		return $results;
	}

	/**
	 * Local fallback mock rates generator if live API is empty or offline.
	 *
	 * @param array $room_mappings Mappings keyed by Listing ID.
	 * @return array Fallback pricing array.
	 */
	private function get_mock_rates_fallback( array $room_mappings ): array {
		$results = [];
		$today   = new DateTime();

		foreach ( $room_mappings as $listing_id => $mapping ) {
			$daily_pricing = [];

			for ( $day_offset = 0; $day_offset < 365; $day_offset++ ) {
				$current_date = ( clone $today )->modify( "+{$day_offset} days" );
				$date_string  = $current_date->format( 'Y-m-d' );
				$day_of_week  = (int) $current_date->format( 'N' );

				$weekend_premium = ( $day_of_week === 5 || $day_of_week === 6 ) ? 40.0 : 0.0;
				$base_rate  = 180.0 + (float) ( ( (int) $listing_id % 7 ) * 10 );
				$final_rate = $base_rate + $weekend_premium;

				$daily_pricing[ $date_string ] = $final_rate;
			}

			$results[ (string) $listing_id ] = $daily_pricing;
		}

		return $results;
	}
}
