<?php
/**
 * Plugin Name:       Homey Channel Sync
 * Plugin URI:        https://github.com/ejames17/homey-channel-sync
 * Description:       Seamless channel management, dynamic pricing, and reservation sync engine connecting Beds24 and PMS channels to the Homey WordPress Theme.
 * Version:           1.0.0
 * Author:            ejames17
 * License:           GPL-2.0-or-later
 * Text Domain:       homey-channel-sync
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.0
 *
 * @package           HomeyChannelSync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main Plugin Class.
 *
 * Implements the singleton pattern to bootstrap and manage core plugin
 * modules, activation/deactivation processes, i18n text domain loads,
 * and class autoloading.
 *
 * @package HomeyChannelSync
 */
final class Homey_Channel_Sync {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public const VERSION = '1.0.0';

	/**
	 * Unique singleton instance.
	 *
	 * @var Homey_Channel_Sync|null
	 */
	private static ?Homey_Channel_Sync $instance = null;

	/**
	 * Cached instance of the Admin Settings Panel class.
	 *
	 * @var Homey_Channel_Sync_Admin|null
	 */
	public ?Homey_Channel_Sync_Admin $admin = null;

	/**
	 * Cached instance of the Cron Manager class.
	 *
	 * @var Homey_Channel_Sync_Cron|null
	 */
	public ?Homey_Channel_Sync_Cron $cron = null;

	/**
	 * Retrieve the single class instance.
	 *
	 * @return Homey_Channel_Sync Active singleton.
	 */
	public static function get_instance(): Homey_Channel_Sync {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Sets up autoloading, triggers core setups, and registers hooks.
	 */
	private function __construct() {
		$this->register_autoloader();

		// Hook plugin load actions
		add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
		add_action( 'init', [ $this, 'load_text_domain' ] );

		// Register lifecycle hooks
		register_activation_hook( __FILE__, [ $this, 'activate' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
	}

	/**
	 * PSR-4 aligned lightweight Autoloader.
	 *
	 * Maps plugin classes to their respective directory folders dynamically
	 * to isolate load processes.
	 */
	private function register_autoloader(): void {
		spl_autoload_register( function ( string $class_name ) {
			// Only load classes belonging to our namespace prefix
			if ( ! str_starts_with( $class_name, 'Homey_' ) ) {
				return;
			}

			$class_path = '';

			if ( 'Homey_Sync_Adapter_Interface' === $class_name ) {
				$class_path = 'includes/interfaces/interface-channel-adapter.php';
			} elseif ( 'Homey_Channel_Sync_Beds24_Adapter' === $class_name ) {
				$class_path = 'includes/adapters/class-beds24-adapter.php';
			} elseif ( 'Homey_Channel_Sync_Admin' === $class_name ) {
				$class_path = 'includes/admin/class-admin-settings.php';
			} elseif ( 'Homey_Channel_Sync_Cron' === $class_name ) {
				$class_path = 'includes/core/class-cron-manager.php';
			}

			if ( ! empty( $class_path ) ) {
				$full_path = plugin_dir_path( __FILE__ ) . $class_path;
				if ( file_exists( $full_path ) ) {
					require_once $full_path;
				}
			}
		} );
	}

	/**
	 * Initialize core modules after all plugins are loaded.
	 */
	public function init_plugin(): void {
		// Run PHP version requirement check
		if ( PHP_VERSION_ID < 80000 ) {
			add_action( 'admin_notices', [ $this, 'display_php_version_warning' ] );
			return;
		}

		// Instantiate Singleton/Core Modules
		if ( is_admin() ) {
			$this->admin = new Homey_Channel_Sync_Admin();
		}
		$this->cron = new Homey_Channel_Sync_Cron();
	}

	/**
	 * Load translation text domains.
	 */
	public function load_text_domain(): void {
		load_plugin_textdomain(
			'homey-channel-sync',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Execution on plugin activation.
	 *
	 * Creates base options and registers scheduled event hooks.
	 */
	public function activate(): void {
		// Check PHP Version compatibility
		if ( PHP_VERSION_ID < 80000 ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'Homey Channel Sync requires PHP version 8.0 or higher. Installation aborted.', 'homey-channel-sync' ) );
		}

		// Load default settings if not already defined
		$existing_options = get_option( 'homey_channel_sync_options' );
		if ( false === $existing_options ) {
			$default_settings = [
				'active_channel'                 => 'beds24',
				'beds24_auth_method'             => 'exchange', // 'exchange' or 'longlife'
				'beds24_invite_code'             => '',
				'beds24_access_token'            => '',
				'beds24_access_token_expires_at' => 0,
				'beds24_refresh_token'           => '',
				'feature_price_sync'             => '1',
				'feature_booking_ingestion'      => '0',
				'feature_promo_engine'           => '0',
				'cron_schedule'                  => 'twicedaily',
			];
			update_option( 'homey_channel_sync_options', $default_settings );
		}

		// Configure background scheduled cron event
		$options  = get_option( 'homey_channel_sync_options', [] );
		$schedule = $options['cron_schedule'] ?? 'twicedaily';

		if ( ! wp_next_scheduled( 'homey_channel_sync_cron_hook' ) ) {
			wp_schedule_event( time() + 60, $schedule, 'homey_channel_sync_cron_hook' );
		}
	}

	/**
	 * Execution on plugin deactivation.
	 *
	 * Cleans up backgrounds tasks.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'homey_channel_sync_cron_hook' );
	}

	/**
	 * Admin notice warning on PHP version incompatibility.
	 */
	public function display_php_version_warning(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo sprintf(
					/* translators: 1: Current PHP version */
					esc_html__( 'Homey Channel Sync deactivated. This plugin requires PHP version 8.0+; you are currently running version %s.', 'homey-channel-sync' ),
					esc_html( PHP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}

// Bootstrap the plugin
Homey_Channel_Sync::get_instance();
