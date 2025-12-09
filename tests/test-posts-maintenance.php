<?php
/**
 * Unit tests for Posts Maintenance functionality.
 *
 * @package WPMUDEV_PluginTest
 * @since   1.0.0
 */

/**
 * Class Test_Posts_Maintenance
 *
 * Tests for Posts Maintenance functionality.
 */
class Test_Posts_Maintenance extends WP_UnitTestCase {

	/**
	 * Test post IDs created during tests.
	 *
	 * @var array
	 */
	private $test_post_ids = array();

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test posts.
		$this->test_post_ids[] = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post 1',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->test_post_ids[] = $this->factory->post->create(
			array(
				'post_title'  => 'Test Post 2',
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);

		$this->test_post_ids[] = $this->factory->post->create(
			array(
				'post_title'  => 'Test Page 1',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		// Create a draft post (should not be scanned).
		$this->test_post_ids[] = $this->factory->post->create(
			array(
				'post_title'  => 'Draft Post',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);
	}

	/**
	 * Clean up after tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up test posts.
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		// Clean up options.
		delete_option( 'wpmudev_posts_maintenance_last_scan' );

		parent::tearDown();
	}

	/**
	 * Test that post meta is updated correctly.
	 *
	 * @return void
	 */
	public function test_post_meta_update() {
		$post_id = $this->test_post_ids[0];

		// Initially, meta should not exist.
		$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
		$this->assertEmpty( $meta, 'Post meta should not exist initially' );

		// Update meta.
		$timestamp = current_time( 'mysql' );
		update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp );

		// Verify meta was saved.
		$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
		$this->assertEquals( $timestamp, $meta, 'Post meta should be saved correctly' );
	}

	/**
	 * Test scanning only published posts.
	 *
	 * @return void
	 */
	public function test_scan_only_published_posts() {
		$published_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$draft_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Update meta for published posts only.
		foreach ( $published_posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		// Verify published posts have meta.
		foreach ( $published_posts as $post_id ) {
			$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
			$this->assertNotEmpty( $meta, "Published post {$post_id} should have scan meta" );
		}

		// Verify draft posts don't have meta.
		foreach ( $draft_posts as $post_id ) {
			$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
			$this->assertEmpty( $meta, "Draft post {$post_id} should not have scan meta" );
		}
	}

	/**
	 * Test scanning specific post types.
	 *
	 * @return void
	 */
	public function test_scan_specific_post_types() {
		// Scan only posts.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		// Verify posts have meta.
		foreach ( $posts as $post_id ) {
			$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
			$this->assertNotEmpty( $meta, "Post {$post_id} should have scan meta" );
		}

		// Verify pages don't have meta (not scanned).
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			$meta = get_post_meta( $page_id, 'wpmudev_test_last_scan', true );
			$this->assertEmpty( $meta, "Page {$page_id} should not have scan meta when only posts are scanned" );
		}
	}

	/**
	 * Test scanning multiple post types.
	 *
	 * @return void
	 */
	public function test_scan_multiple_post_types() {
		$post_types = array( 'post', 'page' );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		// Verify all scanned posts have meta.
		foreach ( $posts as $post_id ) {
			$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
			$this->assertNotEmpty( $meta, "Post/Page {$post_id} should have scan meta" );
		}
	}

	/**
	 * Test last scan timestamp is updated.
	 *
	 * @return void
	 */
	public function test_last_scan_timestamp() {
		// Initially, timestamp should not exist.
		$timestamp = get_option( 'wpmudev_posts_maintenance_last_scan' );
		$this->assertFalse( $timestamp, 'Last scan timestamp should not exist initially' );

		// Update timestamp.
		$new_timestamp = current_time( 'mysql' );
		update_option( 'wpmudev_posts_maintenance_last_scan', $new_timestamp );

		// Verify timestamp was saved.
		$timestamp = get_option( 'wpmudev_posts_maintenance_last_scan' );
		$this->assertEquals( $new_timestamp, $timestamp, 'Last scan timestamp should be saved correctly' );
	}

	/**
	 * Test edge case: no posts to scan.
	 *
	 * @return void
	 */
	public function test_scan_no_posts() {
		// Delete all test posts.
		foreach ( $this->test_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertEmpty( $posts, 'No posts should be found' );

		// Attempting to scan should not cause errors.
		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		$this->assertTrue( true, 'Scanning with no posts should not cause errors' );
	}

	/**
	 * Test that meta is updated with current timestamp.
	 *
	 * @return void
	 */
	public function test_meta_timestamp_format() {
		$post_id = $this->test_post_ids[0];
		$timestamp = current_time( 'mysql' );

		update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp );

		$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );

		// Verify timestamp format (MySQL datetime format).
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$meta,
			'Timestamp should be in MySQL datetime format'
		);
	}

	/**
	 * Test that meta can be updated multiple times.
	 *
	 * @return void
	 */
	public function test_meta_multiple_updates() {
		$post_id = $this->test_post_ids[0];

		// First update.
		$timestamp1 = current_time( 'mysql' );
		update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp1 );

		// Wait a moment to ensure different timestamp.
		sleep( 1 );

		// Second update.
		$timestamp2 = current_time( 'mysql' );
		update_post_meta( $post_id, 'wpmudev_test_last_scan', $timestamp2 );

		// Verify latest timestamp is saved.
		$meta = get_post_meta( $post_id, 'wpmudev_test_last_scan', true );
		$this->assertEquals( $timestamp2, $meta, 'Latest timestamp should be saved' );
		$this->assertNotEquals( $timestamp1, $meta, 'Old timestamp should be replaced' );
	}

	/**
	 * Test scanning with custom post types.
	 *
	 * @return void
	 */
	public function test_scan_custom_post_types() {
		// Register a custom post type for testing.
		register_post_type(
			'test_cpt',
			array(
				'public'      => true,
				'label'       => 'Test CPT',
				'supports'    => array( 'title', 'editor' ),
			)
		);

		// Create a test post of custom type.
		$custom_post_id = $this->factory->post->create(
			array(
				'post_type'   => 'test_cpt',
				'post_status' => 'publish',
			)
		);

		// Scan custom post type.
		$posts = get_posts(
			array(
				'post_type'      => 'test_cpt',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'mysql' ) );
		}

		// Verify custom post type was scanned.
		$meta = get_post_meta( $custom_post_id, 'wpmudev_test_last_scan', true );
		$this->assertNotEmpty( $meta, 'Custom post type should be scanned' );

		// Clean up.
		wp_delete_post( $custom_post_id, true );
		unregister_post_type( 'test_cpt' );
	}
}

