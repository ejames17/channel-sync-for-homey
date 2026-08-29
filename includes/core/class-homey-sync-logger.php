<?php
/**
 * Singleton Logger Class.
 *
 * Provides a modular, high-integrity logging engine that records API, transient,
 * and background synchronization actions in a secure, isolated directory inside uploads.
 *
 * @package HomeyChannelSync
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Singleton Logger Class.
 *
 * Provides a modular, high-integrity logging engine that records API, transient,
 * and background synchronization actions in a secure, isolated directory inside uploads.
 *
 * @package HomeyChannelSync
 */
final class Homey_Sync_Logger {

	/**
	 * Unique singleton instance.
	 *
	 * @var Homey_Sync_Logger|null
	 */
	private static ?Homey_Sync_Logger $instance = null;

	/**
	 * Log directory path.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Retrieve the single class instance.
	 *
	 * @return Homey_Sync_Logger Active singleton.
	 */
	public static function get_instance(): Homey_Sync_Logger {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Initializes directory and enforces security measures.
	 */
	private function __construct() {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'channel-sync-for-homey-logs/';

		$this->ensure_directory_security();
		$this->rotate_logs();
	}

	/**
	 * Ensure the log directory exists, is hidden from the web, and is fully secure.
	 */
	private function ensure_directory_security(): void {
		if ( ! file_exists( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}

		// 1. Create .htaccess if missing to block direct HTTP web browser access.
		$htaccess_file = $this->log_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_rules = "Deny from all\n";
			file_put_contents( $htaccess_file, $htaccess_rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		// 2. Create empty index.php to prevent directory indexing.
		$index_file = $this->log_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			$index_content = "<?php\n// Silence is golden.\n";
			file_put_contents( $index_file, $index_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}

	/**
	 * Public static helper to log a message with a specific severity level.
	 *
	 * @param string $level   Log level (info, warning, error, debug).
	 * @param string $message Log entry content message.
	 */
	public static function log( string $level, string $message ): void {
		self::get_instance()->write( $level, $message );
	}

	/**
	 * Write log entry to the active monthly log file on disk.
	 *
	 * @param string $level   Severity level.
	 * @param string $message Log entry message.
	 */
	public function write( string $level, string $message ): void {
		// Read settings options defensively to check if logging is enabled.
		$options         = get_option( 'homey_channel_sync_options', array() );
		$logging_enabled = $options['enable_debug_log'] ?? '0';

		if ( '1' !== $logging_enabled && 'error' !== strtolower( $level ) ) {
			// Always write core 'error' severity entries, but bypass others if logger is disabled.
			return;
		}

		$current_time = current_time( 'Y-m-d H:i:s' );
		$level_tag    = strtoupper( $level );
		$log_file     = $this->log_dir . 'sync-' . current_time( 'Y-m' ) . '.log';

		// Sanitize and format log entry line.
		$formatted_entry = sprintf(
			"[%1\$s] [%2\$s]: %3\$s\n",
			$current_time,
			$level_tag,
			trim( wp_strip_all_tags( $message ) )
		);

		error_log( $formatted_entry, 3, $log_file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Delete monthly log files older than 30 days to save disk space.
	 */
	public function rotate_logs(): void {
		if ( ! file_exists( $this->log_dir ) ) {
			return;
		}

		$files = glob( $this->log_dir . 'sync-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}

		$thirty_days_ago = time() - ( 30 * DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			// Clean logs modified more than 30 days ago.
			if ( filemtime( $file ) < $thirty_days_ago ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_unlink
			}
		}
	}

	/**
	 * Retrieve the absolute directory path of our log files.
	 *
	 * @return string Log directory path.
	 */
	public function get_log_directory(): string {
		return $this->log_dir;
	}

	/**
	 * Read the active monthly log file from disk defensively.
	 *
	 * @return string Content of the active log file, or placeholder.
	 */
	public function read_current_log(): string {
		$log_file = $this->log_dir . 'sync-' . current_time( 'Y-m' ) . '.log';

		if ( ! file_exists( $log_file ) ) {
			return esc_html__( 'No logs recorded yet for this month.', 'channel-sync-for-homey' );
		}

		// Read up to 2MB to prevent out-of-memory.
		$size = filesize( $log_file );
		if ( $size > 2 * 1024 * 1024 ) {
			$contents = file_get_contents( $log_file, false, null, $size - ( 1024 * 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			return "--- [TRUNCATED DUE TO SIZE - DISPLAYING LATEST 1MB] ---\n" . ( false !== $contents ? $contents : '' );
		}

		$data = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
		return ! empty( $data ) ? $data : esc_html__( 'Active log is empty.', 'channel-sync-for-homey' );
	}

	/**
	 * Clear/empty the active monthly log file.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_current_log(): bool {
		$log_file = $this->log_dir . 'sync-' . current_time( 'Y-m' ) . '.log';
		if ( file_exists( $log_file ) ) {
			return false !== file_put_contents( $log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
		return true;
	}
}
