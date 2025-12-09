<?php
/**
 * Posts Maintenance REST endpoints.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @package       WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

use WPMUDEV\PluginTest\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

// Abort if called directly.
defined( 'WPINC' ) || die;

/**
 * Class Posts_Maintenance_REST
 */
class Posts_Maintenance_REST extends Base {

	/**
	 * Option name for stored post types.
	 *
	 * @var string
	 */
	private $option_name = 'wpmudev_posts_maintenance_types';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'wpmudev/v1',
			'/posts-maintenance/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			'wpmudev/v1',
			'/posts-maintenance/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'scan_posts' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get status info.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$saved_types    = get_option( $this->option_name, array( 'post', 'page' ) );
		$available      = get_post_types( array( 'public' => true ), 'objects' );
		$available_list = array();

		foreach ( $available as $slug => $type ) {
			$available_list[] = array(
				'slug'  => $slug,
				'label' => $type->label,
			);
		}

		return new WP_REST_Response(
			array(
				'success'         => true,
				'last_scan'       => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
				'saved_posttypes' => array_values( $saved_types ),
				'available_types' => $available_list,
				'default_batch'   => 50,
			),
			200
		);
	}

	/**
	 * Scan posts in batches.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan_posts( WP_REST_Request $request ) {
		$post_types = $request->get_param( 'post_types' );
		$offset     = absint( $request->get_param( 'offset' ) );
		$batch_size = absint( $request->get_param( 'batch_size' ) );

		if ( empty( $batch_size ) || $batch_size > 200 ) {
			$batch_size = 50;
		}

		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$post_types = array_map( 'sanitize_text_field', $post_types );
		update_option( $this->option_name, $post_types );

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
		);

		$query = new \WP_Query( $args );
		$posts = $query->get_posts();
		$total = $query->found_posts;

		$processed = 0;
		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
			$processed++;
		}

		$next_offset = $offset + $batch_size;
		$completed   = $next_offset >= $total;

		if ( $completed ) {
			update_option( 'wpmudev_posts_maintenance_last_scan', current_time( 'mysql' ) );
		}

		return new WP_REST_Response(
			array(
				'success'     => true,
				'processed'   => $processed,
				'total'       => $total,
				'next_offset' => $next_offset,
				'completed'   => $completed,
				'last_scan'   => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
			),
			200
		);
	}
}

