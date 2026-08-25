<?php
/**
 * PHP Stubs for GitHub Actions.
 *
 * This file is required dynamically inside GHA active fallback theme to prevent
 * call to undefined function and post type exceptions during E2E executions.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

// Suppress all PHP warnings and deprecation notices inside GHA to prevent "headers already sent" login cookie blocks
error_reporting( 0 );
@ini_set( 'display_errors', '0' );

if ( ! function_exists( 'homey_option' ) ) {
	/**
	 * Stub for homey_option theme settings retriever.
	 *
	 * @param string $option Option key.
	 * @return string Default value.
	 */
	function homey_option( string $option ): string {
		return 'per_day';
	}
}

add_action( 'init', function () {
	if ( ! post_type_exists( 'listing' ) ) {
		register_post_type( 'listing', [
			'public'      => true,
			'has_archive' => true,
			'label'       => 'Listings',
		] );
	}
} );
