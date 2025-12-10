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
	 * Option name for scan state.
	 *
	 * @var string
	 */
	private $state_option = 'wpmudev_posts_maintenance_state';

	/**
	 * Action hook for processing batches.
	 *
	 * @var string
	 */
	private $action_hook = 'wpmudev_posts_maintenance_process_batch';

	/**
	 * Action group for this plugin.
	 *
	 * @var string
	 */
	private $action_group = 'wpmudev-plugin-test';

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( $this->action_hook, array( $this, 'process_batch' ), 10, 1 );
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
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Permission check for Posts Maintenance endpoints.
	 *
	 * Posts maintenance operations can affect all posts on the site, so we need
	 * to ensure only administrators can access these endpoints. Using the same
	 * security approach as the Google Drive endpoints.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		// User must be logged in
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// User must have admin capabilities (manage_options = Administrator role)
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Additional security validation helper for sensitive operations.
	 *
	 * @return WP_Error|true Returns true if valid, WP_Error if not.
	 */
	private function validate_admin_access() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'not_authenticated',
				__( 'You must be logged in to perform this action.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'wpmudev-plugin-test' ),
				array( 'status' => 403 )
			);
		}

		return true;
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

		$state = $this->get_state();
		
		// Check Action Scheduler availability.
		$action_scheduler_available = function_exists( 'as_schedule_single_action' );
		$pending_actions = 0;
		
		if ( $action_scheduler_available && function_exists( 'as_has_scheduled_action' ) ) {
			$pending_actions = as_has_scheduled_action( $this->action_hook, null, $this->action_group ) ? 1 : 0;
		}

		return new WP_REST_Response(
			array(
				'success'                  => true,
				'last_scan'                => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
				'saved_posttypes'          => array_values( $saved_types ),
				'available_types'          => $available_list,
				'default_batch'            => 50,
				'state'                    => $state,
				'action_scheduler_available' => $action_scheduler_available,
				'pending_actions'          => $pending_actions,
			),
			200
		);
	}

	/**
	 * Start scan (background).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( WP_REST_Request $request ) {
		// Additional security validation for posts maintenance
		$security_check = $this->validate_admin_access();
		if ( is_wp_error( $security_check ) ) {
			return $security_check;
		}

		$state = $this->get_state();

		if ( ! empty( $state['running'] ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Scan already running.', 'wpmudev-plugin-test' ),
					'state'   => $state,
				),
			 200
			);
		}

		// Get and validate parameters
		$raw_post_types = $request->get_param( 'post_types' );
		$raw_batch_size = $request->get_param( 'batch_size' );

		// Sanitize and validate batch size
		$batch_size = absint( $raw_batch_size );
		if ( $batch_size < 1 || $batch_size > 200 ) {
			$batch_size = 50; // Safe default
		}

		// Validate and sanitize post types
		if ( empty( $raw_post_types ) || ! is_array( $raw_post_types ) ) {
			$post_types = array( 'post', 'page' ); // Safe defaults
		} else {
			$post_types = array_map( 'sanitize_text_field', $raw_post_types );
			
			// Additional validation: ensure post types exist and are public
			$valid_post_types = array();
			$available_types = get_post_types( array( 'public' => true ), 'names' );
			
			foreach ( $post_types as $post_type ) {
				if ( in_array( $post_type, $available_types, true ) ) {
					$valid_post_types[] = $post_type;
				}
			}
			
			// If no valid post types, use defaults
			if ( empty( $valid_post_types ) ) {
				$post_types = array( 'post', 'page' );
			} else {
				$post_types = $valid_post_types;
			}
		}
		update_option( $this->option_name, $post_types );

		// Count total posts to process.
		$count_query = new \WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$total = intval( $count_query->found_posts );

		// Save state.
		$new_state = array(
			'running'     => true,
			'post_types'  => $post_types,
			'batch_size'  => $batch_size,
			'offset'      => 0,
			'processed'   => 0,
			'total'       => $total,
			'last_scan'   => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
			'started_at'  => time(),
			'updated_at'  => time(),
		);

		$this->save_state( $new_state );
		$this->schedule_first_batch();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Scan started in background.', 'wpmudev-plugin-test' ),
				'state'   => $this->get_state(),
			),
			200
		);
	}

	/**
	 * Process a batch of posts using Action Scheduler.
	 *
	 * @param int $batch_number Batch number (for tracking).
	 * @return void
	 */
	public function process_batch( $batch_number = 0 ) {
		// Check if Action Scheduler is available.
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$state = $this->get_state();

		if ( empty( $state['running'] ) ) {
			return;
		}

		$post_types = ! empty( $state['post_types'] ) ? $state['post_types'] : array( 'post', 'page' );
		$batch_size = ! empty( $state['batch_size'] ) ? absint( $state['batch_size'] ) : 50;
		$offset     = ! empty( $state['offset'] ) ? absint( $state['offset'] ) : 0;
		$total      = isset( $state['total'] ) ? absint( $state['total'] ) : 0;

		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
		);

		$query = new \WP_Query( $args );
		$posts = $query->get_posts();
		
		// If total was not set, set it from first query.
		if ( 0 === $total ) {
			$total = intval( $query->found_posts );
			$state['total'] = $total;
		}

		$processed_batch = 0;
		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
			$processed_batch++;
		}

		$state['processed'] = absint( $state['processed'] ) + $processed_batch;
		$state['offset']    = $offset + $batch_size;
		$state['total']     = $total;
		$state['updated_at'] = time();

		$completed = $state['processed'] >= $state['total'] || $processed_batch === 0;

		if ( $completed ) {
			$state['running']   = false;
			$state['last_scan'] = current_time( 'mysql' );
			update_option( 'wpmudev_posts_maintenance_last_scan', $state['last_scan'] );
		}

		$this->save_state( $state );

		// Schedule next batch if not completed.
		if ( ! $completed ) {
			$this->schedule_next_batch( $batch_number + 1 );
		}
	}

	/**
	 * Schedule the first batch using Action Scheduler.
	 *
	 * Uses scheduled actions (not async) to ensure processing continues
	 * even if the browser tab is closed. WP-Cron will process these actions.
	 *
	 * @return void
	 */
	private function schedule_first_batch() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Cancel any existing actions for this hook to prevent duplicates.
		as_unschedule_all_actions( $this->action_hook, array(), $this->action_group );

		// Schedule the first batch to run immediately (or as soon as WP-Cron runs it).
		// Using scheduled action ensures it will be processed even if browser closes.
		as_schedule_single_action(
			time(),
			$this->action_hook,
			array( 0 ), // First batch number.
			$this->action_group
		);

		// Try to trigger queue runner immediately for faster processing (optional).
		$this->trigger_queue_runner();
	}

	/**
	 * Schedule the next batch using Action Scheduler.
	 *
	 * Uses scheduled actions with minimal delay to ensure processing continues
	 * even if the browser tab is closed. WP-Cron will process these actions.
	 *
	 * @param int $batch_number Next batch number.
	 * @return void
	 */
	private function schedule_next_batch( $batch_number = 1 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		// Schedule next batch with 1-second delay.
		// This ensures WP-Cron will process it even if browser is closed.
		// Action Scheduler's WP-Cron runs every minute, so batches will be processed continuously.
		as_schedule_single_action(
			time() + 1,
			$this->action_hook,
			array( $batch_number ),
			$this->action_group
		);

		// Try to trigger queue runner immediately for faster processing (optional).
		// If this fails (browser closed), WP-Cron will still process the action.
		$this->trigger_queue_runner();
	}

	/**
	 * Trigger Action Scheduler queue runner to process actions immediately.
	 *
	 * This is optional - if the browser is closed, WP-Cron will still process
	 * the scheduled actions. This just makes processing faster when the browser is open.
	 *
	 * @return void
	 */
	private function trigger_queue_runner() {
		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			return;
		}

		// Try to trigger the queue runner via async request for faster processing.
		// If this fails (browser closed), WP-Cron will still process the actions.
		if ( class_exists( 'ActionScheduler_AsyncRequest_QueueRunner' ) ) {
			$async_runner = new \ActionScheduler_AsyncRequest_QueueRunner(
				\ActionScheduler::store()
			);
			$async_runner->maybe_dispatch();
		}
		
		// Note: We don't need to manually trigger WP-Cron here because:
		// 1. Action Scheduler has its own WP-Cron hook that runs every minute
		// 2. Scheduled actions are stored in the database and will be processed automatically
		// 3. This ensures processing continues even if the browser is closed
	}

	/**
	 * Get scan state.
	 *
	 * @return array
	 */
	private function get_state() {
		$default = array(
			'running'    => false,
			'post_types' => array(),
			'batch_size' => 50,
			'offset'     => 0,
			'processed'  => 0,
			'total'      => 0,
			'last_scan'  => get_option( 'wpmudev_posts_maintenance_last_scan', '' ),
			'started_at' => 0,
			'updated_at' => 0,
		);
		$state = get_option( $this->state_option, array() );

		return wp_parse_args( $state, $default );
	}

	/**
	 * Save scan state.
	 *
	 * @param array $state State.
	 *
	 * @return void
	 */
	private function save_state( $state ) {
		update_option( $this->state_option, $state, false );
	}
}

