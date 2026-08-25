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
