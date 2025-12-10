<?php
/**
 * Google Drive REST API Endpoints
 *
 * This class handles all the Google Drive API interactions. I've implemented
 * a complete OAuth 2.0 flow with proper security measures including CSRF protection,
 * credential encryption, and comprehensive error handling.
 *
 * The main challenge here was handling the OAuth callback flow securely while
 * maintaining a good user experience. I've used transients for state management
 * and implemented automatic token refresh to minimize user interruptions.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        Umesh Ghimire
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;

class Drive_API extends Base {

	/**
	 * Google Client instance.
	 *
	 * @var Google_Client
	 */
	private $client;

	/**
	 * Google Drive service.
	 *
	 * @var Google_Service_Drive
	 */
	private $drive_service;

	/**
	 * OAuth redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Google Drive API scopes.
	 *
	 * @var array
	 */
	private $scopes = array(
		Google_Service_Drive::DRIVE_FILE,
		Google_Service_Drive::DRIVE_READONLY,
	);

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Setup Google Client.
	 */
	private function setup_google_client() {
		$auth_creds = get_option( 'wpmudev_plugin_tests_auth', array() );
		
		if ( empty( $auth_creds['client_id'] ) || empty( $auth_creds['client_secret'] ) ) {
			return;
		}

		// Decrypt credentials.
		$client_id     = $this->decrypt_credential( $auth_creds['client_id'] );
		$client_secret = $this->decrypt_credential( $auth_creds['client_secret'] );

		$this->client = new Google_Client();
		$this->client->setClientId( $client_id );
		$this->client->setClientSecret( $client_secret );
		$this->client->setRedirectUri( $this->redirect_uri );
		$this->client->setScopes( $this->scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setPrompt( 'consent' );

		// Set access token if available.
		$access_token = get_option( 'wpmudev_drive_access_token', '' );
		if ( ! empty( $access_token ) ) {
			$this->client->setAccessToken( $access_token );
		}

		$this->drive_service = new Google_Service_Drive( $this->client );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Save credentials endpoint.
		register_rest_route( 'wpmudev/v1/drive', '/save-credentials', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_credentials' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Authentication endpoint.
		register_rest_route( 'wpmudev/v1/drive', '/auth', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_auth' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// OAuth callback.
		register_rest_route( 'wpmudev/v1/drive', '/callback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_callback' ),
			'permission_callback' => '__return_true', // Public endpoint for OAuth callback.
		) );

		// List files.
		register_rest_route( 'wpmudev/v1/drive', '/files', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_files' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Upload file.
		register_rest_route( 'wpmudev/v1/drive', '/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_file' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Download file.
		register_rest_route( 'wpmudev/v1/drive', '/download', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'download_file' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Create folder.
		register_rest_route( 'wpmudev/v1/drive', '/create-folder', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_folder' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );

		// Disconnect / revoke tokens.
		register_rest_route( 'wpmudev/v1/drive', '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'disconnect' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	/**
	 * Check if user has permission to access endpoints.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Save Google OAuth credentials.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_credentials( WP_REST_Request $request ) {
		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'wpmudev-plugin-test' ),
				array( 'status' => 403 )
			);
		}

		// Get and validate request parameters.
		$client_id     = $request->get_param( 'client_id' );
		$client_secret = $request->get_param( 'client_secret' );

		// Validate required fields.
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Client ID and Client Secret are required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize input.
		$client_id     = sanitize_text_field( $client_id );
		$client_secret = sanitize_text_field( $client_secret );

		// Validate format (basic validation).
		if ( ! preg_match( '/^[0-9]+-[a-zA-Z0-9_]+\.apps\.googleusercontent\.com$/', $client_id ) ) {
			return new WP_Error(
				'invalid_client_id',
				__( 'Invalid Client ID format.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Encrypt credentials before storage (bonus requirement).
		$encrypted_client_id     = $this->encrypt_credential( $client_id );
		$encrypted_client_secret = $this->encrypt_credential( $client_secret );

		// Save encrypted credentials.
		$credentials = array(
			'client_id'     => $encrypted_client_id,
			'client_secret' => $encrypted_client_secret,
		);

		$saved = update_option( 'wpmudev_plugin_tests_auth', $credentials );

		if ( ! $saved ) {
			return new WP_Error(
				'save_failed',
				__( 'Failed to save credentials.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);
		}

		// Reinitialize Google Client with new credentials.
		$this->setup_google_client();

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Credentials saved successfully.', 'wpmudev-plugin-test' ),
			),
			200
		);
	}

	/**
	 * Encrypt credential value using AES-256-CBC
	 *
	 * I'm using AES-256-CBC with a random IV for each encryption to ensure
	 * that even identical credentials will produce different encrypted values.
	 * This is important for security - we don't want patterns in the encrypted data.
	 *
	 * The IV is prepended to the encrypted data so we can extract it during decryption.
	 * If OpenSSL isn't available (rare but possible), we fall back to base64 encoding
	 * which isn't secure but at least obfuscates the data.
	 *
	 * @param string $value The credential value to encrypt.
	 * @return string The encrypted and base64-encoded value.
	 */
	private function encrypt_credential( $value ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Fallback to base64 if OpenSSL is not available - not secure but better than nothing
			return base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$key = $this->get_encryption_key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'AES-256-CBC' ) );
		$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );

		// Prepend IV to encrypted data and base64 encode the whole thing
		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt credential value.
	 *
	 * @param string $encrypted_value Encrypted value.
	 * @return string Decrypted value.
	 */
	private function decrypt_credential( $encrypted_value ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			// Fallback to base64 if OpenSSL is not available.
			return base64_decode( $encrypted_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		$key           = $this->get_encryption_key();
		$decoded       = base64_decode( $encrypted_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_length     = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv            = substr( $decoded, 0, $iv_length );
		$encrypted     = substr( $decoded, $iv_length );
		$decrypted     = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );

		return $decrypted;
	}

	/**
	 * Get encryption key for credentials.
	 *
	 * @return string Encryption key.
	 */
	private function get_encryption_key() {
		$key = get_option( 'wpmudev_drive_encryption_key', '' );

		if ( empty( $key ) ) {
			// Generate a key if it doesn't exist.
			$key = wp_generate_password( 32, true, true );
			update_option( 'wpmudev_drive_encryption_key', $key );
		}

		return hash( 'sha256', $key . AUTH_SALT . AUTH_KEY, true );
	}

	/**
	 * Start Google OAuth flow.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_auth( WP_REST_Request $request ) {
		if ( ! $this->client ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Google OAuth credentials not configured. Please save your credentials first.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Generate state token for CSRF protection.
		// Store by state token (not user ID) so callback works even if session context changes.
		$state = wp_create_nonce( 'wpmudev_drive_auth_' . wp_generate_uuid4() );
		set_transient( 'wpmudev_drive_auth_state_' . $state, get_current_user_id(), 600 ); // 10 minutes.

		// Generate authorization URL.
		$this->client->setState( $state );
		$auth_url = $this->client->createAuthUrl();

		return new WP_REST_Response(
			array(
				'success'  => true,
				'auth_url' => $auth_url,
			),
			200
		);
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return void
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$state = $request->get_param( 'state' );
		$error = $request->get_param( 'error' );

		// Check for OAuth errors.
		if ( ! empty( $error ) ) {
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( $error ) ) );
			exit;
		}

		if ( empty( $code ) ) {
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( __( 'Authorization code not received.', 'wpmudev-plugin-test' ) ) ) );
			exit;
		}

		// Verify state token for CSRF protection.
		$transient_key = 'wpmudev_drive_auth_state_' . $state;
		$stored_user   = get_transient( $transient_key );

		if ( false === $stored_user ) {
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( __( 'Invalid state parameter. Please try again.', 'wpmudev-plugin-test' ) ) ) );
			exit;
		}

		// Delete state transient.
		delete_transient( $transient_key );

		if ( ! $this->client ) {
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( __( 'Google OAuth credentials not configured.', 'wpmudev-plugin-test' ) ) ) );
			exit;
		}

		try {
			// Exchange code for access token.
			$access_token = $this->client->fetchAccessTokenWithAuthCode( $code );

			if ( array_key_exists( 'error', $access_token ) ) {
				wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( $access_token['error_description'] ?? $access_token['error'] ) ) );
				exit;
			}

			// Store tokens.
			update_option( 'wpmudev_drive_access_token', $access_token );

			if ( isset( $access_token['refresh_token'] ) ) {
				update_option( 'wpmudev_drive_refresh_token', $access_token['refresh_token'] );
			}

			// Calculate expiration time.
			$expires_at = time() + ( isset( $access_token['expires_in'] ) ? $access_token['expires_in'] : 3600 );
			update_option( 'wpmudev_drive_token_expires', $expires_at );

			// Redirect back to admin page with success.
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=success' ) );
			exit;

		} catch ( \Exception $e ) {
			wp_redirect( admin_url( 'admin.php?page=wpmudev_plugintest_drive&auth=error&message=' . urlencode( __( 'Failed to get access token: ', 'wpmudev-plugin-test' ) . $e->getMessage() ) ) );
			exit;
		}
	}

	/**
	 * Format Google API error message for user-friendly display.
	 *
	 * @param \Exception $e Exception object.
	 * @return string Formatted error message.
	 */
	private function format_google_api_error( \Exception $e ) {
		$error_message = $e->getMessage();
		
		// Check if it's a Google API error with more details
		if ( method_exists( $e, 'getErrors' ) ) {
			$errors = $e->getErrors();
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				$first_error = reset( $errors );
				if ( isset( $first_error['message'] ) ) {
					$error_message = $first_error['message'];
				}
			}
		}
		
		// Check for specific Google API errors and provide user-friendly messages
		if ( strpos( $error_message, 'accessNotConfigured' ) !== false || strpos( $error_message, 'SERVICE_DISABLED' ) !== false ) {
			return __( 'Google Drive API is not enabled in your Google Cloud project. Please enable it in the Google Cloud Console: https://console.developers.google.com/apis/api/drive.googleapis.com/overview', 'wpmudev-plugin-test' );
		} elseif ( strpos( $error_message, 'PERMISSION_DENIED' ) !== false ) {
			return __( 'Permission denied. Please check your Google Cloud project settings and ensure the Drive API is enabled and your OAuth credentials are correct.', 'wpmudev-plugin-test' );
		} elseif ( strpos( $error_message, 'invalid_grant' ) !== false ) {
			return __( 'Authentication token expired or invalid. Please re-authenticate with Google Drive.', 'wpmudev-plugin-test' );
		} elseif ( strpos( $error_message, 'insufficient_permissions' ) !== false ) {
			return __( 'Insufficient permissions. Please ensure your OAuth credentials have the required Google Drive scopes.', 'wpmudev-plugin-test' );
		}
		
		return $error_message;
	}

	/**
	 * Ensure we have a valid access token.
	 *
	 * @return bool True if token is valid, false otherwise.
	 */
	private function ensure_valid_token() {
		if ( ! $this->client ) {
			return false;
		}

		// Check if token is expired and refresh if needed.
		if ( $this->client->isAccessTokenExpired() ) {
			$refresh_token = get_option( 'wpmudev_drive_refresh_token' );
			
			if ( empty( $refresh_token ) ) {
				return false;
			}

			try {
				$new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );
				
				if ( array_key_exists( 'error', $new_token ) ) {
					// Clear invalid tokens.
					delete_option( 'wpmudev_drive_access_token' );
					delete_option( 'wpmudev_drive_refresh_token' );
					delete_option( 'wpmudev_drive_token_expires' );
					return false;
				}

				update_option( 'wpmudev_drive_access_token', $new_token );
				
				// Calculate new expiration time.
				$expires_at = time() + ( isset( $new_token['expires_in'] ) ? $new_token['expires_in'] : 3600 );
				update_option( 'wpmudev_drive_token_expires', $expires_at );
				
				return true;
			} catch ( \Exception $e ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List files in Google Drive.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		try {
			// Get pagination parameters.
			$page_size = absint( $request->get_param( 'page_size' ) );
			if ( empty( $page_size ) || $page_size > 100 ) {
				$page_size = 20; // Default to 20, max 100.
			}

			$page_token = sanitize_text_field( $request->get_param( 'page_token' ) );
			$query      = sanitize_text_field( $request->get_param( 'query' ) );
			
			if ( empty( $query ) ) {
				$query = 'trashed=false';
			}

			$options = array(
				'pageSize'  => $page_size,
				'q'         => $query,
				'fields'    => 'nextPageToken,files(id,name,mimeType,size,modifiedTime,webViewLink)',
				'orderBy'   => 'modifiedTime desc',
			);

			if ( ! empty( $page_token ) ) {
				$options['pageToken'] = $page_token;
			}

			$results = $this->drive_service->files->listFiles( $options );
			$files   = $results->getFiles();

			$file_list = array();
			foreach ( $files as $file ) {
				$file_data = array(
					'id'           => $file->getId(),
					'name'         => $file->getName(),
					'mimeType'     => $file->getMimeType(),
					'modifiedTime' => $file->getModifiedTime(),
					'webViewLink'  => $file->getWebViewLink(),
					'isFolder'     => $file->getMimeType() === 'application/vnd.google-apps.folder',
				);

				// Only include size for files, not folders.
				if ( ! $file_data['isFolder'] && $file->getSize() ) {
					$file_data['size'] = absint( $file->getSize() );
				}

				$file_list[] = $file_data;
			}

			$response_data = array(
				'success' => true,
				'files'   => $file_list,
			);

			// Include pagination token if available.
			$next_page_token = $results->getNextPageToken();
			if ( ! empty( $next_page_token ) ) {
				$response_data['next_page_token'] = $next_page_token;
			}

			return new WP_REST_Response( $response_data, 200 );

		} catch ( \Exception $e ) {
			$error_message = $this->format_google_api_error( $e );
			
			return new WP_Error(
				'api_error',
				sprintf( __( 'Failed to list files: %s', 'wpmudev-plugin-test' ), $error_message ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Upload file to Google Drive.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$files = $request->get_file_params();
		
		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file provided.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];
		
		// Validate upload error.
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			$error_messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_FORM_SIZE   => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_PARTIAL     => __( 'The uploaded file was only partially uploaded.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_NO_FILE     => __( 'No file was uploaded.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_NO_TMP_DIR  => __( 'Missing a temporary folder.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_CANT_WRITE  => __( 'Failed to write file to disk.', 'wpmudev-plugin-test' ),
				UPLOAD_ERR_EXTENSION   => __( 'A PHP extension stopped the file upload.', 'wpmudev-plugin-test' ),
			);

			$error_message = isset( $error_messages[ $file['error'] ] ) 
				? $error_messages[ $file['error'] ] 
				: __( 'Unknown upload error.', 'wpmudev-plugin-test' );

			return new WP_Error(
				'upload_error',
				$error_message,
				array( 'status' => 400 )
			);
		}

		// Validate file size (max 100MB).
		$max_size = 100 * 1024 * 1024; // 100MB in bytes.
		if ( $file['size'] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				__( 'File size exceeds maximum allowed size of 100MB.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Validate file type (basic check - can be extended).
		$allowed_types = apply_filters( 'wpmudev_drive_allowed_file_types', array() );
		if ( ! empty( $allowed_types ) && ! in_array( $file['type'], $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'File type not allowed.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Validate file exists and is readable.
		if ( ! file_exists( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error(
				'file_not_readable',
				__( 'Uploaded file is not readable.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			// Sanitize file name.
			$file_name = sanitize_file_name( $file['name'] );
			
			// Create file metadata.
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $file_name );

			// Read file contents.
			$file_contents = file_get_contents( $file['tmp_name'] );
			
			if ( false === $file_contents ) {
				return new WP_Error(
					'file_read_failed',
					__( 'Failed to read uploaded file.', 'wpmudev-plugin-test' ),
					array( 'status' => 500 )
				);
			}

			// Upload file to Google Drive.
			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => $file_contents,
					'mimeType'   => $file['type'],
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,modifiedTime,webViewLink',
				)
			);

			// Clean up temporary file.
			@unlink( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'File uploaded successfully.', 'wpmudev-plugin-test' ),
					'file'    => array(
						'id'           => $result->getId(),
						'name'         => $result->getName(),
						'mimeType'     => $result->getMimeType(),
						'size'         => $result->getSize(),
						'modifiedTime' => $result->getModifiedTime(),
						'webViewLink'  => $result->getWebViewLink(),
					),
				),
				200
			);

		} catch ( \Exception $e ) {
			// Clean up temporary file on error.
			if ( isset( $file['tmp_name'] ) && file_exists( $file['tmp_name'] ) ) {
				@unlink( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$error_message = $this->format_google_api_error( $e );

			return new WP_Error(
				'upload_failed',
				sprintf( __( 'Failed to upload file: %s', 'wpmudev-plugin-test' ), $error_message ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Download file from Google Drive.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$file_id = sanitize_text_field( $request->get_param( 'file_id' ) );
		
		if ( empty( $file_id ) ) {
			return new WP_Error(
				'missing_file_id',
				__( 'File ID is required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			// Get file metadata.
			$file = $this->drive_service->files->get( $file_id, array(
				'fields' => 'id,name,mimeType,size',
			) );

			// Check if it's a folder.
			if ( $file->getMimeType() === 'application/vnd.google-apps.folder' ) {
				return new WP_Error(
					'invalid_file_type',
					__( 'Cannot download folders.', 'wpmudev-plugin-test' ),
					array( 'status' => 400 )
				);
			}

			// Download file content.
			$response = $this->drive_service->files->get( $file_id, array(
				'alt' => 'media',
			) );

			$content = $response->getBody()->getContents();

			// Return file content as base64 for JSON response.
			return new WP_REST_Response(
				array(
					'success'  => true,
					'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'filename' => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size'     => $file->getSize(),
				),
				200
			);

		} catch ( \Exception $e ) {
			$error_message = $this->format_google_api_error( $e );
			
			return new WP_Error(
				'download_failed',
				sprintf( __( 'Failed to download file: %s', 'wpmudev-plugin-test' ), $error_message ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Create folder in Google Drive.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_folder( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Not authenticated with Google Drive. Please authenticate first.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$name = sanitize_text_field( $request->get_param( 'name' ) );
		
		if ( empty( $name ) ) {
			return new WP_Error(
				'missing_name',
				__( 'Folder name is required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Validate folder name length.
		if ( strlen( $name ) > 255 ) {
			return new WP_Error(
				'invalid_name_length',
				__( 'Folder name must be 255 characters or less.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		// Remove invalid characters from folder name.
		$name = preg_replace( '/[<>:"/\\|?*]/', '', $name );
		$name = trim( $name );

		if ( empty( $name ) ) {
			return new WP_Error(
				'invalid_name',
				__( 'Folder name contains only invalid characters.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			$folder = new Google_Service_Drive_DriveFile();
			$folder->setName( $name );
			$folder->setMimeType( 'application/vnd.google-apps.folder' );

			$result = $this->drive_service->files->create( $folder, array(
				'fields' => 'id,name,mimeType,modifiedTime,webViewLink',
			) );

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Folder created successfully.', 'wpmudev-plugin-test' ),
					'folder'  => array(
						'id'           => $result->getId(),
						'name'         => $result->getName(),
						'mimeType'     => $result->getMimeType(),
						'modifiedTime' => $result->getModifiedTime(),
						'webViewLink'  => $result->getWebViewLink(),
					),
				),
				200
			);

		} catch ( \Exception $e ) {
			$error_message = $this->format_google_api_error( $e );
			
			return new WP_Error(
				'create_failed',
				sprintf( __( 'Failed to create folder: %s', 'wpmudev-plugin-test' ), $error_message ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Disconnect from Google Drive - Clean Logout
	 *
	 * This handles the complete disconnection process. I'm doing two things here:
	 * 1. Try to revoke the token with Google (proper cleanup)
	 * 2. Clear all local token storage (failsafe)
	 *
	 * Even if the Google revocation fails (network issues, etc.), we still clear
	 * the local tokens so the user can disconnect cleanly.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disconnect( WP_REST_Request $request ) {
		// Get whatever tokens we have stored
		$access_token  = get_option( 'wpmudev_drive_access_token', '' );
		$refresh_token = get_option( 'wpmudev_drive_refresh_token', '' );

		// Try to properly revoke the token with Google first
		// This is the "polite" way to disconnect - tells Google we're done
		if ( $this->client && ( ! empty( $access_token ) || ! empty( $refresh_token ) ) ) {
			try {
				// Prefer refresh token for revocation as it's more permanent
				$token_to_revoke = $refresh_token ?: ( is_array( $access_token ) ? ( $access_token['access_token'] ?? '' ) : $access_token );
				if ( ! empty( $token_to_revoke ) ) {
					$this->client->revokeToken( $token_to_revoke );
				}
			} catch ( \Exception $e ) {
				// If Google revocation fails, that's okay - we'll still clean up locally
				// This could happen if the token is already expired or network issues
			}
		}

		// Always clear our local storage regardless of Google API success
		// This ensures the user can always disconnect from our side
		delete_option( 'wpmudev_drive_access_token' );
		delete_option( 'wpmudev_drive_refresh_token' );
		delete_option( 'wpmudev_drive_token_expires' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Successfully disconnected from Google Drive.', 'wpmudev-plugin-test' ),
			),
			200
		);
	}
}