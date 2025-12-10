<?php
/**
 * Posts Maintenance Admin Page
 *
 * This handles the posts maintenance functionality. The main challenge was implementing
 * background processing that continues even if the user navigates away from the page.
 * 
 * I've used a combination of AJAX for the frontend and WordPress cron for scheduling.
 * The batch processing approach ensures we don't hit memory limits on sites with
 * thousands of posts.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        Umesh Ghimire
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

/**
 * Class Posts_Maintenance
 *
 * Handles the Posts Maintenance admin page functionality.
 *
 * @package WPMUDEV\PluginTest\App\Admin_Pages
 */
class Posts_Maintenance extends Base {

	/**
	 * The page title.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * The page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_plugintest_posts_maintenance';

	/**
	 * Option name for post types.
	 *
	 * @var string
	 */
	private $option_name = 'wpmudev_posts_maintenance_types';

	/**
	 * Page assets.
	 *
	 * @var array
	 */
	private $page_scripts = array();

	/**
	 * Assets version.
	 *
	 * @var string
	 */
	private $assets_version = '';

	/**
	 * Unique DOM id.
	 *
	 * @var string
	 */
	private $unique_id = '';

	/**
	 * Initializes the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init() {
		$this->page_title = __( 'Posts Maintenance', 'wpmudev-plugin-test' );

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wpmudev_daily_posts_maintenance', array( $this, 'scan_posts_cron' ) );
		add_action( 'admin_init', array( $this, 'schedule_daily_maintenance' ) );

		// Asset data.
		$this->assets_version = ! empty( $this->script_data( 'version' ) ) ? $this->script_data( 'version' ) : WPMUDEV_PLUGINTEST_VERSION;
		$this->unique_id      = "wpmudev_plugintest_posts_maint_wrap-{$this->assets_version}";
	}

	/**
	 * Register admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		$page = add_submenu_page(
			'wpmudev_plugintest_drive',
			$this->page_title,
			$this->page_title,
			'manage_options',
			$this->page_slug,
			array( $this, 'callback' )
		);

		add_action( 'load-' . $page, array( $this, 'prepare_assets' ) );
	}

	/**
	 * The admin page callback method.
	 *
	 * @return void
	 */
	public function callback() {
		$this->view();
	}

	/**
	 * Prepare assets.
	 *
	 * @return void
	 */
	public function prepare_assets() {
		if ( ! is_array( $this->page_scripts ) ) {
			$this->page_scripts = array();
		}

		$handle       = 'wpmudev_plugintest_postsmaint';
		$src          = WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/postsmaintenance.min.js';
		$style_src    = WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/postsmaintenance.min.css';
		$dependencies = ! empty( $this->script_data( 'dependencies' ) )
			? $this->script_data( 'dependencies' )
			: array(
				'react',
				'wp-element',
				'wp-i18n',
				'wp-is-shallow-equal',
				'wp-polyfill',
			);

		$post_types = get_option( $this->option_name, array( 'post', 'page' ) );
		$available  = get_post_types( array( 'public' => true ), 'objects' );
		$available_types = array();
		foreach ( $available as $slug => $obj ) {
			$available_types[] = array(
				'slug'  => $slug,
				'label' => $obj->label,
			);
		}

		$this->page_scripts[ $handle ] = array(
			'src'       => $src,
			'style_src' => $style_src,
			'deps'      => $dependencies,
			'ver'       => $this->assets_version,
			'strategy'  => true,
			'localize'  => array(
				'restUrl'          => rest_url(),
				'dom_element_id'   => $this->unique_id,
				'restEndpointScan' => 'wpmudev/v1/posts-maintenance/scan',
				'restEndpointStatus' => 'wpmudev/v1/posts-maintenance/status',
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'lastScan'         => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
				'savedPostTypes'   => array_values( $post_types ),
				'availableTypes'   => $available_types,
				'defaultBatch'     => 50,
			),
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'google-drive-test_page_' . $this->page_slug ) {
			return;
		}

		if ( ! empty( $this->page_scripts ) ) {
			foreach ( $this->page_scripts as $handle => $page_script ) {
				wp_register_script(
					$handle,
					$page_script['src'],
					$page_script['deps'],
					$page_script['ver'],
					$page_script['strategy']
				);

				if ( ! empty( $page_script['localize'] ) ) {
					wp_localize_script( $handle, 'wpmudevPostsMaint', $page_script['localize'] );
				}

				wp_enqueue_script( $handle );

				if ( ! empty( $page_script['style_src'] ) ) {
					wp_enqueue_style( $handle, $page_script['style_src'], array(), $this->assets_version );
				}
			}
		}
	}

	/**
	 * Prints the admin page view.
	 *
	 * @return void
	 */
	protected function view() {
		echo '<div id="' . esc_attr( $this->unique_id ) . '" class="sui-wrap"></div>';
	}

	/**
	 * AJAX handler for scanning posts.
	 *
	 * @return void
	 */
	public function ajax_scan_posts() {
		check_ajax_referer( 'wpmudev_scan_posts', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wpmudev-plugin-test' ) ) );
		}

		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array( 'post', 'page' );
		
		// Save selected post types for future use.
		update_option( $this->option_name, $post_types );
		
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch_size = 20; // Process 20 posts at a time.

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

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'total'       => $total,
				'next_offset' => $next_offset,
				'completed'   => $completed,
				'message'     => sprintf(
					// translators: %1$d: processed count, %2$d: total count.
					__( 'Processed %1$d of %2$d posts...', 'wpmudev-plugin-test' ),
					$offset + $processed,
					$total
				),
			)
		);
	}

	/**
	 * Cron callback for daily maintenance.
	 *
	 * @return void
	 */
	public function scan_posts_cron() {
		$post_types = get_option( $this->option_name, array( 'post', 'page' ) );
		$this->scan_posts_batch( $post_types );
	}

	/**
	 * Scan posts in batch (for background processing).
	 *
	 * @param array $post_types Post types to scan.
	 * @return void
	 */
	private function scan_posts_batch( $post_types = array() ) {
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query = new \WP_Query( $args );
		$posts = $query->get_posts();

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		update_option( 'wpmudev_posts_maintenance_last_scan', current_time( 'mysql' ) );
	}

	/**
	 * Schedule daily maintenance.
	 *
	 * @return void
	 */
	public function schedule_daily_maintenance() {
		if ( ! wp_next_scheduled( 'wpmudev_daily_posts_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'wpmudev_daily_posts_maintenance' );
		}
	}

	/**
	 * Gets assets data for given key.
	 *
	 * @param string $key Key.
	 * @return string|array
	 */
	protected function script_data( string $key = '' ) {
		$raw_script_data = $this->raw_script_data();

		return ! empty( $key ) && ! empty( $raw_script_data[ $key ] ) ? $raw_script_data[ $key ] : '';
	}

	/**
	 * Gets the script data from assets php file.
	 *
	 * @return array
	 */
	protected function raw_script_data(): array {
		static $script_data = null;

		if ( is_null( $script_data ) && file_exists( WPMUDEV_PLUGINTEST_DIR . 'assets/js/postsmaintenance.min.asset.php' ) ) {
			$script_data = include WPMUDEV_PLUGINTEST_DIR . 'assets/js/postsmaintenance.min.asset.php';
		}

		return (array) $script_data;
	}
}

