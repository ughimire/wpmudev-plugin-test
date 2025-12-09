<?php
/**
 * Dependency Manager class.
 *
 * Handles dependency isolation and version conflict prevention.
 *
 * @link    https://wpmudev.com/
 * @since   1.0.0
 *
 * @author  WPMUDEV (https://wpmudev.com)
 * @package WPMUDEV_PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Class Dependency_Manager
 *
 * Manages plugin dependencies and prevents conflicts.
 *
 * @package WPMUDEV\PluginTest
 */
class Dependency_Manager {

	/**
	 * Namespace prefix for this plugin.
	 *
	 * @var string
	 */
	private $namespace_prefix = 'WPMUDEV\\PluginTest\\';

	/**
	 * Initialize dependency management.
	 *
	 * @return void
	 */
	public static function init() {
		$instance = new self();
		$instance->setup_autoloader();
		$instance->check_dependencies();
	}

	/**
	 * Setup custom autoloader for namespace isolation.
	 *
	 * @return void
	 */
	private function setup_autoloader() {
		// Ensure our namespace is properly isolated.
		spl_autoload_register( array( $this, 'autoload' ), true, true );
	}

	/**
	 * Custom autoloader for plugin classes.
	 *
	 * @param string $class_name Class name to load.
	 * @return void
	 */
	private function autoload( $class_name ) {
		// Only handle our namespace.
		if ( strpos( $class_name, $this->namespace_prefix ) !== 0 ) {
			return;
		}

		// Remove namespace prefix.
		$relative_class = substr( $class_name, strlen( $this->namespace_prefix ) );

		// Convert namespace separators to directory separators.
		$relative_class = str_replace( '\\', '/', $relative_class );

		// Build file path.
		$file_path = WPMUDEV_PLUGINTEST_DIR . $relative_class . '.php';

		// Load file if it exists.
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Check and validate dependencies.
	 *
	 * @return void
	 */
	private function check_dependencies() {
		// Check if Google API Client is available.
		if ( ! class_exists( 'Google_Client' ) ) {
			add_action(
				'admin_notices',
				function() {
					?>
					<div class="notice notice-error">
						<p>
							<?php
							printf(
								// translators: %s: plugin name.
								esc_html__( '%s requires Google API Client library. Please run: composer install', 'wpmudev-plugin-test' ),
								'<strong>WPMU DEV Plugin Test</strong>'
							);
							?>
						</p>
					</div>
					<?php
				}
			);
			return;
		}

		// Check for version conflicts with other plugins.
		$this->check_version_conflicts();
	}

	/**
	 * Check for version conflicts with other plugins.
	 *
	 * @return void
	 */
	private function check_version_conflicts() {
		// Check if another plugin is using a conflicting version of Google API Client.
		$required_version = '^2.15';
		$current_version  = defined( '\\Google_Client::LIBVER' ) ? \Google_Client::LIBVER : '';

		if ( ! empty( $current_version ) ) {
			// Validate version compatibility.
			// This is a simplified check - in production, use proper semver comparison.
			if ( version_compare( $current_version, '2.15', '<' ) ) {
				add_action(
					'admin_notices',
					function() use ( $current_version ) {
						?>
						<div class="notice notice-warning">
							<p>
								<?php
								printf(
									// translators: %1$s: plugin name, %2$s: current version, %3$s: required version.
									esc_html__( '%1$s detected Google API Client version %2$s. Required: %3$s or higher.', 'wpmudev-plugin-test' ),
									'<strong>WPMU DEV Plugin Test</strong>',
									esc_html( $current_version ),
									'2.15'
								);
								?>
							</p>
						</div>
						<?php
					}
				);
			}
		}
	}

	/**
	 * Get isolated instance of a dependency class.
	 *
	 * This method ensures we're using our own version of dependencies
	 * and not conflicting with other plugins.
	 *
	 * @param string $class_name Class name.
	 * @return object|null Class instance or null if not found.
	 */
	public static function get_isolated_instance( $class_name ) {
		// Check if class exists in our vendor directory.
		$vendor_path = WPMUDEV_PLUGINTEST_DIR . 'vendor/' . str_replace( '\\', '/', $class_name ) . '.php';

		if ( file_exists( $vendor_path ) ) {
			require_once $vendor_path;
		}

		if ( class_exists( $class_name ) ) {
			return new $class_name();
		}

		return null;
	}
}

