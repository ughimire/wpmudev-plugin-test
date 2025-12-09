<?php
/**
 * Composer cleanup class to remove unnecessary files from vendor directory.
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
 * Class Google_Task_Composer
 *
 * Handles cleanup of vendor directory to reduce package size.
 *
 * @package WPMUDEV\PluginTest
 */
class Google_Task_Composer {

	/**
	 * Cleanup unnecessary files from vendor directory.
	 *
	 * This method removes test files, documentation, and other non-essential
	 * files from the vendor directory to reduce package size.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function cleanup() {
		$vendor_dir = __DIR__ . '/../vendor';

		if ( ! is_dir( $vendor_dir ) ) {
			return;
		}

		// Patterns of files/directories to remove.
		$patterns_to_remove = array(
			'**/tests/**',
			'**/test/**',
			'**/Tests/**',
			'**/Test/**',
			'**/*.md',
			'**/README*',
			'**/CHANGELOG*',
			'**/LICENSE*',
			'**/.git/**',
			'**/.github/**',
			'**/phpunit.xml*',
			'**/.phpunit.result.cache',
			'**/.travis.yml',
			'**/.scrutinizer.yml',
			'**/.coveralls.yml',
			'**/phpcs.xml*',
			'**/.editorconfig',
			'**/.gitignore',
			'**/.gitattributes',
			'**/composer.json',
			'**/composer.lock',
			'**/.php_cs*',
		);

		foreach ( $patterns_to_remove as $pattern ) {
			$files = glob( $vendor_dir . '/' . $pattern, GLOB_BRACE );
			if ( $files ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					} elseif ( is_dir( $file ) ) {
						self::remove_directory( $file );
					}
				}
			}
		}
	}

	/**
	 * Recursively remove directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				self::remove_directory( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

