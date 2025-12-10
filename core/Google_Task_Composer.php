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
	 * This method removes:
	 * 1. Entire dev dependency packages (require-dev) and their transitive dependencies
	 * 2. Test files, documentation, and other non-essential files
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function cleanup() {
		$vendor_dir = __DIR__ . '/../vendor';
		$composer_json = __DIR__ . '/../composer.json';
		$composer_lock = __DIR__ . '/../composer.lock';

		if ( ! is_dir( $vendor_dir ) ) {
			return;
		}

		// Step 1: Get list of all dev-only packages (including transitive dependencies)
		$dev_packages_to_remove = self::get_dev_packages( $composer_json, $composer_lock );

		// Step 2: Remove all dev-only packages and their dependencies
		foreach ( $dev_packages_to_remove as $package ) {
			$package_dir = $vendor_dir . '/' . $package;
			if ( is_dir( $package_dir ) ) {
				self::remove_directory( $package_dir );
			}
		}

		// Step 3: Also remove common dev dependency vendor directories as fallback
		// This ensures we catch all dev dependencies even if composer.lock parsing fails
		$dev_vendor_dirs = array( 'phpunit', 'phpcsstandards', 'dealerdirect', 'squizlabs', 'wp-coding-standards', 'phpcompatibility' );
		foreach ( $dev_vendor_dirs as $vendor_name ) {
			$vendor_dir_path = $vendor_dir . '/' . $vendor_name;
			if ( is_dir( $vendor_dir_path ) ) {
				self::remove_directory( $vendor_dir_path );
			}
		}

		// Step 2: Remove test files, documentation, and other non-essential files from remaining packages
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
	 * Get all dev-only packages including their transitive dependencies.
	 *
	 * @param string $composer_json Path to composer.json.
	 * @param string $composer_lock Path to composer.lock.
	 * @return array List of package names to remove.
	 */
	private static function get_dev_packages( $composer_json, $composer_lock ) {
		$dev_packages = array();
		$production_packages = array();

		// Get production packages from composer.json
		if ( file_exists( $composer_json ) ) {
			$composer_data = json_decode( file_get_contents( $composer_json ), true );
			if ( isset( $composer_data['require'] ) && is_array( $composer_data['require'] ) ) {
				$production_packages = array_keys( $composer_data['require'] );
			}
		}

		// Get all packages from composer.lock
		if ( file_exists( $composer_lock ) ) {
			$lock_data = json_decode( file_get_contents( $composer_lock ), true );
			
			// Get all production packages from lock file
			if ( isset( $lock_data['packages'] ) && is_array( $lock_data['packages'] ) ) {
				foreach ( $lock_data['packages'] as $package ) {
					if ( isset( $package['name'] ) ) {
						$production_packages[] = $package['name'];
					}
				}
			}

			// Get all dev-only packages from packages-dev array (these are definitely dev-only)
			if ( isset( $lock_data['packages-dev'] ) && is_array( $lock_data['packages-dev'] ) ) {
				foreach ( $lock_data['packages-dev'] as $package ) {
					if ( isset( $package['name'] ) ) {
						$dev_packages[] = $package['name'];
					}
				}
			}

			// Now recursively find all dependencies of dev packages that aren't in production
			$all_packages = array();
			if ( isset( $lock_data['packages'] ) ) {
				$all_packages = array_merge( $all_packages, $lock_data['packages'] );
			}
			if ( isset( $lock_data['packages-dev'] ) ) {
				$all_packages = array_merge( $all_packages, $lock_data['packages-dev'] );
			}

			// Build a map of package dependencies
			$package_map = array();
			foreach ( $all_packages as $package ) {
				if ( isset( $package['name'] ) ) {
					$package_map[ $package['name'] ] = $package;
				}
			}

			// Recursively find all dependencies of dev packages
			$processed = array();
			foreach ( $dev_packages as $dev_pkg ) {
				self::collect_dependencies( $dev_pkg, $package_map, $production_packages, $dev_packages, $processed );
			}
		}

		return array_unique( $dev_packages );
	}

	/**
	 * Recursively collect dependencies of a package.
	 *
	 * @param string $package_name      Package name.
	 * @param array  $package_map      Map of all packages.
	 * @param array  $production_packages Production package names.
	 * @param array  $dev_packages      Dev packages array (passed by reference).
	 * @param array  $processed        Processed packages (passed by reference).
	 * @return void
	 */
	private static function collect_dependencies( $package_name, $package_map, $production_packages, &$dev_packages, &$processed ) {
		if ( isset( $processed[ $package_name ] ) ) {
			return; // Already processed
		}
		$processed[ $package_name ] = true;

		if ( ! isset( $package_map[ $package_name ] ) ) {
			return;
		}

		$package = $package_map[ $package_name ];
		if ( isset( $package['require'] ) && is_array( $package['require'] ) ) {
			foreach ( array_keys( $package['require'] ) as $dep ) {
				// Skip PHP and extensions
				if ( $dep === 'php' || preg_match( '/^ext-/', $dep ) ) {
					continue;
				}

				// If dependency is not a production package, it's a dev dependency
				if ( ! in_array( $dep, $production_packages, true ) ) {
					if ( ! in_array( $dep, $dev_packages, true ) ) {
						$dev_packages[] = $dep;
					}
					// Recursively process this dependency
					self::collect_dependencies( $dep, $package_map, $production_packages, $dev_packages, $processed );
				}
			}
		}
	}

	/**
	 * Check if a package is only required by dev dependencies.
	 *
	 * @param string $package_name Package name to check.
	 * @param array  $lock_data    Composer lock data.
	 * @param array  $known_dev    Known dev packages.
	 * @return bool True if package is only required by dev packages.
	 */
	private static function is_only_dev_dependency( $package_name, $lock_data, $known_dev ) {
		// Check if it's in packages-dev
		if ( isset( $lock_data['packages-dev'] ) && is_array( $lock_data['packages-dev'] ) ) {
			foreach ( $lock_data['packages-dev'] as $package ) {
				if ( isset( $package['name'] ) && $package['name'] === $package_name ) {
					return true;
				}
			}
		}

		// Check if it's required by any dev package
		$all_packages = array();
		if ( isset( $lock_data['packages'] ) ) {
			$all_packages = array_merge( $all_packages, $lock_data['packages'] );
		}
		if ( isset( $lock_data['packages-dev'] ) ) {
			$all_packages = array_merge( $all_packages, $lock_data['packages-dev'] );
		}

		foreach ( $all_packages as $package ) {
			if ( isset( $package['require'] ) && is_array( $package['require'] ) ) {
				if ( isset( $package['require'][ $package_name ] ) ) {
					// Check if the requiring package is a dev package
					if ( isset( $package['name'] ) && in_array( $package['name'], $known_dev, true ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Recursively remove directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool True on success, false on failure.
	 */
	public static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		// Use shell command for more reliable removal (works better on all systems)
		$dir = escapeshellarg( $dir );
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$command = "rmdir /s /q {$dir} 2>nul";
		} else {
			$command = "rm -rf {$dir}";
		}
		@exec( $command ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return ! is_dir( $dir );
	}
}

