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
		if ( ! $this->validate_credentials( $credentials ) ) {
			$this->last_error = esc_html__( 'Missing access token in credentials array.', 'homey-channel-sync' );
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
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			return false;
		}

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
		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/authentication/setup', [
			'headers' => [
				'code'   => trim( $invite_code ),
				'accept' => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			$this->last_error = esc_html__( 'Invalid response format from Beds24 server.', 'homey-channel-sync' );
			return false;
		}

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
		$response = wp_safe_remote_get( 'https://api.beds24.com/v2/authentication/token', [
			'headers' => [
				'refreshToken' => trim( $refresh_token ),
				'accept'       => 'application/json',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['token'] ) ) {
			$this->last_error = esc_html__( 'Invalid refresh response format from Beds24 server.', 'homey-channel-sync' );
			return false;
		}

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
				return $cached;
			}
		}

		if ( ! $this->validate_credentials( $credentials ) ) {
			$this->last_error = esc_html__( 'Missing access token in credentials array.', 'homey-channel-sync' );
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
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->last_error = $this->parse_api_error( $response );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			$this->last_error = esc_html__( 'Invalid JSON response from Beds24.', 'homey-channel-sync' );
			return false;
		}

		// Support both paginated nested format and direct list
		$raw_properties = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

		if ( ! is_array( $raw_properties ) ) {
			$this->last_error = esc_html__( 'Properties collection is empty or invalid.', 'homey-channel-sync' );
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

		return $structured;
	}

	/**
	 * Fetch rate information for a list of mapped Beds24 listings.
	 *
	 * Stub implementation simulating Beds24 V2 rates response for mapped entries.
	 * Returns 14 days of pricing starting from today.
	 *
	 * @param array $room_mappings Array of mappings keyed by local WP Post ID.
	 * @return array Nested array of dates and daily rates keyed by local listing ID.
	 */
	public function get_rates( array $room_mappings ): array {
		if ( empty( $room_mappings ) ) {
			return [];
		}

		$results = [];
		$today   = new DateTime();

		foreach ( $room_mappings as $listing_id => $mapping ) {
			$property_id = $mapping['property_id'] ?? '';
			$room_id     = $mapping['room_id'] ?? '';

			if ( empty( $property_id ) || empty( $room_id ) ) {
				continue;
			}

			$daily_pricing = [];

			// Simulate rate extraction for the upcoming 14 days
			for ( $day_offset = 0; $day_offset < 14; $day_offset++ ) {
				$current_date = ( clone $today )->modify( "+{$day_offset} days" );
				$date_string  = $current_date->format( 'Y-m-d' );
				$day_of_week  = (int) $current_date->format( 'N' ); // 1 (Mon) to 7 (Sun)

				// Base premium if weekend (Friday or Saturday)
				$weekend_premium = ( $day_of_week === 5 || $day_of_week === 6 ) ? 40.0 : 0.0;

				// Generate a realistic, distinct rate per listing
				$base_rate  = 120.0 + (float) ( ( (int) $listing_id % 7 ) * 15 );
				$final_rate = $base_rate + $weekend_premium;

				$daily_pricing[ $date_string ] = $final_rate;
			}

			$results[ (string) $listing_id ] = $daily_pricing;
		}

		return $results;
	}
}
