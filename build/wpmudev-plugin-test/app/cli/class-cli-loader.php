<?php
/**
 * CLI Loader class.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV (https://wpmudev.com)
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\App\CLI;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

/**
 * Class CLI_Loader
 *
 * Loads WP-CLI commands.
 *
 * @package WPMUDEV\PluginTest\App\CLI
 */
class CLI_Loader extends Base {

	/**
	 * Initialize CLI commands.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'wpmudev posts',
			'WPMUDEV\\PluginTest\\App\\CLI\\Posts_Maintenance_Command',
			array(
				'shortdesc' => __( 'Manage posts maintenance operations.', 'wpmudev-plugin-test' ),
			)
		);
	}
}

