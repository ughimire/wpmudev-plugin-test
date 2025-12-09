<?php
/**
 * WP-CLI command for Posts Maintenance.
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

/**
 * Class Posts_Maintenance_Command
 *
 * WP-CLI command for scanning and maintaining posts.
 *
 * @package WPMUDEV\PluginTest\App\CLI
 */
class Posts_Maintenance_Command {

	/**
	 * Scan posts and update last scan timestamp.
	 *
	 * ## OPTIONS
	 *
	 * [--post-types=<types>]
	 * : Comma-separated list of post types to scan. Default: post,page
	 *
	 * [--batch-size=<size>]
	 * : Number of posts to process per batch. Default: 50
	 *
	 * ## EXAMPLES
	 *
	 *     # Scan all posts and pages
	 *     wp wpmudev posts scan
	 *
	 *     # Scan only posts
	 *     wp wpmudev posts scan --post-types=post
	 *
	 *     # Scan custom post types
	 *     wp wpmudev posts scan --post-types=post,page,product
	 *
	 *     # Scan with custom batch size
	 *     wp wpmudev posts scan --batch-size=100
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		$post_types = isset( $assoc_args['post-types'] ) 
			? array_map( 'trim', explode( ',', $assoc_args['post-types'] ) )
			: array( 'post', 'page' );

		$batch_size = isset( $assoc_args['batch-size'] ) 
			? absint( $assoc_args['batch-size'] )
			: 50;

		// Validate post types.
		$available_types = get_post_types( array( 'public' => true ), 'names' );
		$invalid_types   = array_diff( $post_types, $available_types );

		if ( ! empty( $invalid_types ) ) {
			\WP_CLI::warning(
				sprintf(
					// translators: %s: invalid post types.
					__( 'Invalid post types: %s. Skipping...', 'wpmudev-plugin-test' ),
					implode( ', ', $invalid_types )
				)
			);
			$post_types = array_intersect( $post_types, $available_types );
		}

		if ( empty( $post_types ) ) {
			\WP_CLI::error( __( 'No valid post types to scan.', 'wpmudev-plugin-test' ) );
			return;
		}

		\WP_CLI::log(
			sprintf(
				// translators: %s: post types.
				__( 'Starting scan for post types: %s', 'wpmudev-plugin-test' ),
				implode( ', ', $post_types )
			)
		);

		// Get total count.
		$total_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$total_query = new \WP_Query( $total_args );
		$total       = $total_query->found_posts;

		if ( $total === 0 ) {
			\WP_CLI::success( __( 'No posts found to scan.', 'wpmudev-plugin-test' ) );
			return;
		}

		\WP_CLI::log(
			sprintf(
				// translators: %d: total posts.
				__( 'Found %d posts to process.', 'wpmudev-plugin-test' ),
				$total
			)
		);

		$processed = 0;
		$offset    = 0;
		$progress  = \WP_CLI\Utils\make_progress_bar( __( 'Scanning posts', 'wpmudev-plugin-test' ), $total );

		while ( $offset < $total ) {
			$args = array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
			);

			$query = new \WP_Query( $args );
			$posts = $query->get_posts();

			foreach ( $posts as $post_id ) {
				update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
				$processed++;
				$progress->tick();
			}

			$offset += $batch_size;
		}

		$progress->finish();

		// Update last scan timestamp.
		update_option( 'wpmudev_posts_maintenance_last_scan', current_time( 'mysql' ) );

		\WP_CLI::success(
			sprintf(
				// translators: %d: processed count.
				__( 'Successfully scanned %d posts.', 'wpmudev-plugin-test' ),
				$processed
			)
		);
	}
}

