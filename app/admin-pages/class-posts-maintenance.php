<?php
/**
 * Posts Maintenance admin page.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV (https://wpmudev.com)
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
	 * Initializes the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init() {
		$this->page_title = __( 'Posts Maintenance', 'wpmudev-plugin-test' );

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpmudev_scan_posts', array( $this, 'ajax_scan_posts' ) );
		add_action( 'wpmudev_daily_posts_maintenance', array( $this, 'scan_posts_cron' ) );
		add_action( 'admin_init', array( $this, 'schedule_daily_maintenance' ) );
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
		// Assets will be enqueued inline for simplicity.
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

		wp_enqueue_script( 'jquery' );
	}

	/**
	 * Prints the admin page view.
	 *
	 * @return void
	 */
	protected function view() {
		$post_types = get_option( $this->option_name, array( 'post', 'page' ) );
		$last_scan  = get_option( 'wpmudev_posts_maintenance_last_scan', '' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>

			<div class="sui-box">
				<div class="sui-box-header">
					<h2 class="sui-box-title"><?php esc_html_e( 'Scan Posts', 'wpmudev-plugin-test' ); ?></h2>
				</div>
				<div class="sui-box-body">
					<p><?php esc_html_e( 'This tool will scan all public posts and pages, updating the last scan timestamp for each post.', 'wpmudev-plugin-test' ); ?></p>

					<div class="sui-box-settings-row">
						<label for="post_types">
							<strong><?php esc_html_e( 'Post Types to Scan:', 'wpmudev-plugin-test' ); ?></strong>
						</label>
						<?php
						$available_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $available_types as $type ) {
							$checked = in_array( $type->name, $post_types, true ) ? 'checked' : '';
							?>
							<label style="display: block; margin: 5px 0;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php echo esc_attr( $checked ); ?>>
								<?php echo esc_html( $type->label ); ?> (<?php echo esc_html( $type->name ); ?>)
							</label>
							<?php
						}
						?>
					</div>

					<?php if ( $last_scan ) : ?>
						<div class="sui-box-settings-row">
							<p>
								<strong><?php esc_html_e( 'Last Scan:', 'wpmudev-plugin-test' ); ?></strong>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_scan ) ) ); ?>
							</p>
						</div>
					<?php endif; ?>

					<div id="scan-progress" style="display: none; margin: 20px 0;">
						<div class="sui-progress">
							<div class="sui-progress-bar">
								<span class="sui-progress-bar-value" style="width: 0%"></span>
							</div>
							<span class="sui-progress-text">0%</span>
						</div>
						<p id="scan-status"></p>
					</div>
				</div>
				<div class="sui-box-footer">
					<div class="sui-actions-right">
						<button type="button" id="scan-posts-btn" class="sui-button sui-button-blue">
							<?php esc_html_e( 'Scan Posts', 'wpmudev-plugin-test' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#scan-posts-btn').on('click', function() {
				var $btn = $(this);
				var $progress = $('#scan-progress');
				var $status = $('#scan-status');
				var postTypes = [];

				$('input[name="post_types[]"]:checked').each(function() {
					postTypes.push($(this).val());
				});

				if (postTypes.length === 0) {
					alert('<?php echo esc_js( __( 'Please select at least one post type.', 'wpmudev-plugin-test' ) ); ?>');
					return;
				}

				$btn.prop('disabled', true);
				$progress.show();
				$status.text('<?php echo esc_js( __( 'Starting scan...', 'wpmudev-plugin-test' ) ); ?>');

				scanPosts(postTypes, 0, 0);
			});

			function scanPosts(postTypes, offset, totalProcessed) {
				var $progress = $('#scan-progress');
				var $status = $('#scan-status');
				var $progressBar = $progress.find('.sui-progress-bar-value');
				var $progressText = $progress.find('.sui-progress-text');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wpmudev_scan_posts',
						post_types: postTypes,
						offset: offset,
						nonce: '<?php echo esc_js( wp_create_nonce( 'wpmudev_scan_posts' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							var processed = totalProcessed + response.data.processed;
							var total = response.data.total;
							var percentage = total > 0 ? Math.round((processed / total) * 100) : 0;

							$progressBar.css('width', percentage + '%');
							$progressText.text(percentage + '%');
							$status.text(response.data.message);

							if (response.data.completed) {
								$status.text('<?php echo esc_js( __( 'Scan completed successfully!', 'wpmudev-plugin-test' ) ); ?>');
								$('#scan-posts-btn').prop('disabled', false);
								setTimeout(function() {
									location.reload();
								}, 2000);
							} else {
								// Continue scanning.
								setTimeout(function() {
									scanPosts(postTypes, response.data.next_offset, processed);
								}, 100);
							}
						} else {
							$status.text(response.data.message || '<?php echo esc_js( __( 'An error occurred.', 'wpmudev-plugin-test' ) ); ?>');
							$('#scan-posts-btn').prop('disabled', false);
						}
					},
					error: function() {
						$status.text('<?php echo esc_js( __( 'An error occurred during the scan.', 'wpmudev-plugin-test' ) ); ?>');
						$('#scan-posts-btn').prop('disabled', false);
					}
				});
			}
		});
		</script>
		<?php
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
}

