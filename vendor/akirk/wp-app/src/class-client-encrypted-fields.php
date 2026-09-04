<?php
/**
 * Client-side encrypted fields manifest runtime.
 *
 * @package WpApp
 */

namespace WpApp;

if ( class_exists( 'WpApp\ClientEncryptedFields' ) ) {
	return;
}

/**
 * Registers AJAX endpoints and asset configuration from an encrypted fields manifest.
 */
class ClientEncryptedFields {
	/**
	 * Manifest path.
	 *
	 * @var string
	 */
	private $manifest_path;

	/**
	 * Normalized manifest.
	 *
	 * @var array
	 */
	private $manifest;

	/**
	 * Runtime config.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Create and register a manifest runtime.
	 *
	 * @param string $manifest_path Path to JSON manifest.
	 * @param array  $config Runtime config.
	 * @return self
	 */
	public static function register( $manifest_path, $config = [] ) {
		$runtime = new self( $manifest_path, $config );
		$runtime->register_hooks();

		return $runtime;
	}

	/**
	 * Constructor.
	 *
	 * @param string $manifest_path Path to JSON manifest.
	 * @param array  $config Runtime config.
	 */
	public function __construct( $manifest_path, $config = [] ) {
		$this->manifest_path = (string) $manifest_path;
		$this->manifest      = $this->load_manifest( $this->manifest_path );
		$this->config        = array_merge(
			[
				'action_prefix' => $this->default_action_prefix(),
				'nonce_action'  => null,
				'require_login' => true,
				'capability'    => 'read',
			],
			is_array( $config ) ? $config : []
		);

		if ( empty( $this->config['nonce_action'] ) ) {
			$this->config['nonce_action'] = $this->config['action_prefix'];
		}
	}

	/**
	 * Get the normalized manifest.
	 *
	 * @return array
	 */
	public function get_manifest() {
		return $this->manifest;
	}

	/**
	 * Get the action prefix for AJAX calls.
	 *
	 * @return string
	 */
	public function get_action_prefix() {
		return $this->config['action_prefix'];
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		foreach ( [ 'manifest', 'settings', 'save_verifier', 'list', 'get', 'save', 'delete' ] as $action ) {
			add_action( 'wp_ajax_' . $this->ajax_action( $action ), [ $this, 'ajax_' . $action ] );
		}
	}

	/**
	 * Enqueue client-side encrypted fields assets and config for a WpApp scope.
	 *
	 * @param string|array|null $scope Optional app scope.
	 */
	public function enqueue_assets( $scope = null ) {
		wp_app_enqueue_crypto_runtime( $scope );
		wp_app_enqueue_script(
			'wp-app-encrypted-fields',
			wp_app_get_asset_url( 'wp-app-encrypted-fields.js' ),
			[ 'wp-app-crypto' ],
			WP_APP_VERSION,
			true,
			$scope
		);

		wp_app_add_inline_script(
			'wp-app-encrypted-fields-config',
			$this->get_client_config_script(),
			false,
			$scope
		);
	}

	/**
	 * Get client configuration.
	 *
	 * @return array
	 */
	public function get_client_config() {
		return [
			'ajaxUrl'      => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'actionPrefix' => $this->config['action_prefix'],
			'nonce'        => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $this->config['nonce_action'] ) : '',
			'manifest'     => $this->manifest,
		];
	}

	/**
	 * Get client configuration bootstrap script.
	 *
	 * @return string
	 */
	public function get_client_config_script() {
		$config = $this->get_client_config();
		$key    = $this->config['action_prefix'];

		return 'window.WpAppEncryptedFieldsConfigs = window.WpAppEncryptedFieldsConfigs || {};'
			. 'window.WpAppEncryptedFieldsConfigs[' . wp_json_encode( $key ) . '] = ' . wp_json_encode( $config ) . ';'
			. 'window.WpAppEncryptedFieldsConfig = ' . wp_json_encode( $config ) . ';';
	}

	/**
	 * AJAX manifest endpoint.
	 */
	public function ajax_manifest() {
		$this->send_json_success( [ 'manifest' => $this->manifest ] );
	}

	/**
	 * AJAX settings endpoint.
	 */
	public function ajax_settings() {
		$this->check_ajax_request();

		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$key     = '_wp_app_encrypted_fields_salt_' . $this->config['action_prefix'];
		$salt    = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, $key, true ) : '';

		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = function_exists( 'wp_generate_password' )
				? wp_generate_password( 32, false, false )
				: bin2hex( random_bytes( 16 ) );

			if ( function_exists( 'update_user_meta' ) ) {
				update_user_meta( $user_id, $key, $salt );
			}
		}

		$this->send_json_success(
			[
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a KDF salt for JSON transport.
				'salt'             => base64_encode( $salt ),
				'iterations'       => isset( $this->manifest['crypto']['iterations'] ) ? (int) $this->manifest['crypto']['iterations'] : 250000,
				'verifier'         => $this->get_user_verifier( $user_id ),
				'hasEncryptedData' => $this->has_encrypted_data(),
			]
		);
	}

	/**
	 * AJAX save verifier endpoint.
	 */
	public function ajax_save_verifier() {
		$this->check_ajax_request();

		$body     = $this->get_json_body();
		$verifier = isset( $body['verifier'] ) && is_array( $body['verifier'] ) ? $body['verifier'] : null;

		if ( ! $verifier ) {
			$this->send_json_error( [ 'message' => 'Verifier is required.' ], 400 );
		}

		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( get_current_user_id(), $this->get_verifier_meta_key(), wp_json_encode( $verifier ) );
		}

		$this->send_json_success( [ 'verifier' => $verifier ] );
	}

	/**
	 * AJAX list CPT records.
	 */
	public function ajax_list() {
		$this->check_ajax_request();

		$cpt = $this->get_request_value( 'cpt' );
		$this->assert_cpt( $cpt );

		$query = new \WP_Query(
			[
				'post_type'      => $cpt,
				'post_status'    => [ 'publish', 'private', 'draft' ],
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);

		$records = array_map( [ $this, 'format_post_record' ], $query->posts );

		$this->send_json_success( [ 'records' => $records ] );
	}

	/**
	 * AJAX get one CPT record.
	 */
	public function ajax_get() {
		$this->check_ajax_request();

		$cpt  = $this->get_request_value( 'cpt' );
		$id   = (int) $this->get_request_value( 'id' );
		$post = get_post( $id );

		$this->assert_cpt( $cpt );
		if ( ! $post || $post->post_type !== $cpt ) {
			$this->send_json_error( [ 'message' => 'Record not found.' ], 404 );
		}

		$this->send_json_success( [ 'record' => $this->format_post_record( $post ) ] );
	}

	/**
	 * AJAX save CPT record.
	 */
	public function ajax_save() {
		$this->check_ajax_request();

		$body      = $this->get_json_body();
		$cpt       = isset( $body['cpt'] ) ? sanitize_key( $body['cpt'] ) : '';
		$id        = isset( $body['id'] ) ? (int) $body['id'] : 0;
		$encrypted = isset( $body['encrypted'] ) && is_array( $body['encrypted'] ) ? $body['encrypted'] : [];
		$post_data = [
			'post_type'   => $cpt,
			'post_status' => isset( $body['post']['post_status'] ) ? sanitize_key( $body['post']['post_status'] ) : 'private',
		];

		$this->assert_cpt( $cpt );

		if ( $id > 0 ) {
			$post_data['ID'] = $id;
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			$this->send_json_error( [ 'message' => $post_id->get_error_message() ], 400 );
		}

		$this->save_post_encrypted_fields( $post_id, $cpt, $encrypted );
		$this->save_post_taxonomies( $post_id, $cpt, isset( $body['taxonomies'] ) && is_array( $body['taxonomies'] ) ? $body['taxonomies'] : [] );

		$this->send_json_success( [ 'record' => $this->format_post_record( get_post( $post_id ) ) ] );
	}

	/**
	 * AJAX delete CPT record.
	 */
	public function ajax_delete() {
		$this->check_ajax_request();

		$body = $this->get_json_body();
		$cpt  = isset( $body['cpt'] ) ? sanitize_key( $body['cpt'] ) : '';
		$id   = isset( $body['id'] ) ? (int) $body['id'] : 0;
		$post = get_post( $id );

		$this->assert_cpt( $cpt );
		if ( ! $post || $post->post_type !== $cpt ) {
			$this->send_json_error( [ 'message' => 'Record not found.' ], 404 );
		}

		wp_trash_post( $id );

		$this->send_json_success( [ 'deleted' => true ] );
	}

	/**
	 * Load and normalize manifest JSON.
	 *
	 * @param string $path Manifest path.
	 * @return array
	 */
	private function load_manifest( $path ) {
		if ( ! is_readable( $path ) ) {
			throw new \InvalidArgumentException( 'Encrypted fields manifest is not readable: ' . $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Manifest is a local plugin file, not a remote URL.
		$decoded = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) ) {
			throw new \InvalidArgumentException( 'Encrypted fields manifest must be valid JSON.' );
		}

		return $this->normalize_manifest( $decoded );
	}

	/**
	 * Normalize manifest data.
	 *
	 * @param array $manifest Manifest data.
	 * @return array
	 */
	private function normalize_manifest( $manifest ) {
		$manifest['version']    = isset( $manifest['version'] ) ? (int) $manifest['version'] : 1;
		$manifest['app']        = isset( $manifest['app'] ) && is_array( $manifest['app'] ) ? $manifest['app'] : [];
		$manifest['crypto']     = isset( $manifest['crypto'] ) && is_array( $manifest['crypto'] ) ? $manifest['crypto'] : [];
		$manifest['cpts']       = isset( $manifest['cpts'] ) && is_array( $manifest['cpts'] ) ? $manifest['cpts'] : [];
		$manifest['taxonomies'] = isset( $manifest['taxonomies'] ) && is_array( $manifest['taxonomies'] ) ? $manifest['taxonomies'] : [];

		foreach ( $manifest['cpts'] as $cpt => $definition ) {
			$definition                    = is_array( $definition ) ? $definition : [];
			$definition['encryptedFields'] = isset( $definition['encryptedFields'] ) && is_array( $definition['encryptedFields'] ) ? $definition['encryptedFields'] : [];
			$definition['taxonomies']      = isset( $definition['taxonomies'] ) && is_array( $definition['taxonomies'] ) ? array_values( $definition['taxonomies'] ) : [];

			foreach ( $definition['encryptedFields'] as $field => $field_definition ) {
				$field_definition                        = is_array( $field_definition ) ? $field_definition : [];
				$definition['encryptedFields'][ $field ] = array_merge(
					[
						'label'       => ucwords( str_replace( [ '-', '_' ], ' ', $field ) ),
						'storage'     => in_array( $field, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ? 'post_field' : 'post_meta',
						'field'       => $field,
						'metaKey'     => '_encrypted_' . $field,
						'minBytes'    => 512,
						'bucketBytes' => 512,
					],
					$field_definition
				);
			}

			$manifest['cpts'][ sanitize_key( $cpt ) ] = $definition;
			if ( sanitize_key( $cpt ) !== $cpt ) {
				unset( $manifest['cpts'][ $cpt ] );
			}
		}

		return $manifest;
	}

	/**
	 * Get a default AJAX action prefix.
	 *
	 * @return string
	 */
	private function default_action_prefix() {
		$slug = isset( $this->manifest['app']['slug'] ) ? $this->manifest['app']['slug'] : basename( dirname( $this->manifest_path ) );

		return sanitize_key( $slug ) . '_encrypted_fields';
	}

	/**
	 * Build AJAX action name.
	 *
	 * @param string $action Action suffix.
	 * @return string
	 */
	private function ajax_action( $action ) {
		return $this->config['action_prefix'] . '_' . $action;
	}

	/**
	 * Get user verifier meta key.
	 *
	 * @return string
	 */
	private function get_verifier_meta_key() {
		return '_wp_app_encrypted_fields_verifier_' . $this->config['action_prefix'];
	}

	/**
	 * Get encrypted verifier envelope for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	private function get_user_verifier( $user_id ) {
		$value = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, $this->get_verifier_meta_key(), true ) : '';

		if ( is_array( $value ) ) {
			return $value;
		}

		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Determine whether any configured encrypted field already has data.
	 *
	 * @return bool
	 */
	private function has_encrypted_data() {
		if ( ! class_exists( '\WP_Query' ) ) {
			return false;
		}

		foreach ( $this->manifest['cpts'] as $cpt => $definition ) {
			$query = new \WP_Query(
				[
					'post_type'      => $cpt,
					'post_status'    => [ 'publish', 'private', 'draft' ],
					'posts_per_page' => 100,
					'fields'         => 'all',
				]
			);

			foreach ( $query->posts as $post ) {
				foreach ( $definition['encryptedFields'] as $field_definition ) {
					if ( $this->read_post_encrypted_field( $post, $field_definition ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Check nonce and permission.
	 */
	private function check_ajax_request() {
		if ( function_exists( 'check_ajax_referer' ) ) {
			check_ajax_referer( $this->config['nonce_action'], 'nonce' );
		}

		if ( ! empty( $this->config['require_login'] ) && function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			$this->send_json_error( [ 'message' => 'Login required.' ], 401 );
		}

		if ( ! empty( $this->config['capability'] ) && function_exists( 'current_user_can' ) && ! current_user_can( $this->config['capability'] ) ) {
			$this->send_json_error( [ 'message' => 'Insufficient permission.' ], 403 );
		}
	}

	/**
	 * Assert a CPT exists in manifest.
	 *
	 * @param string $cpt CPT name.
	 */
	private function assert_cpt( $cpt ) {
		if ( ! isset( $this->manifest['cpts'][ $cpt ] ) ) {
			$this->send_json_error( [ 'message' => 'Unknown post type.' ], 400 );
		}
	}

	/**
	 * Get JSON request body.
	 *
	 * @return array
	 */
	private function get_json_body() {
		$body = json_decode( file_get_contents( 'php://input' ), true );

		if ( is_array( $body ) ) {
			return $body;
		}

		return isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : [];
	}

	/**
	 * Get request value from JSON, POST, or GET.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function get_request_value( $key ) {
		$body = $this->get_json_body();

		if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
			return sanitize_key( $body[ $key ] );
		}

		if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
			return sanitize_key( wp_unslash( $_POST[ $key ] ) );
		}

		if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
			return sanitize_key( wp_unslash( $_GET[ $key ] ) );
		}

		return '';
	}

	/**
	 * Format a post record for the client.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	private function format_post_record( $post ) {
		$cpt        = $post->post_type;
		$definition = $this->manifest['cpts'][ $cpt ];
		$record     = [
			'id'         => (int) $post->ID,
			'cpt'        => $cpt,
			'post'       => [
				'post_status' => $post->post_status,
				'post_date'   => $post->post_date_gmt,
				'modified'    => $post->post_modified_gmt,
			],
			'taxonomies' => [],
			'encrypted'  => [],
		];

		foreach ( $definition['encryptedFields'] as $field => $field_definition ) {
			$record['encrypted'][ $field ] = $this->read_post_encrypted_field( $post, $field_definition );
		}

		foreach ( $definition['taxonomies'] as $taxonomy ) {
			$terms                             = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'slugs' ] );
			$record['taxonomies'][ $taxonomy ] = is_wp_error( $terms ) ? [] : $terms;
		}

		return $record;
	}

	/**
	 * Read an encrypted post field.
	 *
	 * @param \WP_Post $post Post object.
	 * @param array    $field_definition Field definition.
	 * @return array|null
	 */
	private function read_post_encrypted_field( $post, $field_definition ) {
		$value = null;

		if ( 'post_field' === $field_definition['storage'] && isset( $post->{$field_definition['field']} ) ) {
			$value = $post->{$field_definition['field']};
		} else {
			$value = get_post_meta( $post->ID, $field_definition['metaKey'], true );
		}

		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Save encrypted post fields.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cpt CPT name.
	 * @param array  $encrypted Encrypted field envelopes.
	 */
	private function save_post_encrypted_fields( $post_id, $cpt, $encrypted ) {
		$definition  = $this->manifest['cpts'][ $cpt ];
		$post_update = [ 'ID' => $post_id ];

		foreach ( $definition['encryptedFields'] as $field => $field_definition ) {
			if ( ! isset( $encrypted[ $field ] ) || ! is_array( $encrypted[ $field ] ) ) {
				continue;
			}

			$value = wp_json_encode( $encrypted[ $field ] );
			if ( 'post_field' === $field_definition['storage'] && in_array( $field_definition['field'], [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
				$post_update[ $field_definition['field'] ] = $value;
				continue;
			}

			update_post_meta( $post_id, $field_definition['metaKey'], wp_slash( $value ) );
		}

		if ( count( $post_update ) > 1 ) {
			wp_update_post( wp_slash( $post_update ) );
		}
	}

	/**
	 * Save configured taxonomies for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $cpt CPT name.
	 * @param array  $taxonomies Taxonomy values.
	 */
	private function save_post_taxonomies( $post_id, $cpt, $taxonomies ) {
		$definition = $this->manifest['cpts'][ $cpt ];

		foreach ( $definition['taxonomies'] as $taxonomy ) {
			if ( ! array_key_exists( $taxonomy, $taxonomies ) ) {
				continue;
			}

			$value = $taxonomies[ $taxonomy ];
			if ( is_string( $value ) ) {
				$value = [ sanitize_key( $value ) ];
			} elseif ( is_array( $value ) ) {
				$value = array_map( 'sanitize_key', $value );
			} else {
				$value = [];
			}

			wp_set_object_terms( $post_id, $value, $taxonomy, false );
		}
	}

	/**
	 * Send JSON success.
	 *
	 * @param array $data Response data.
	 */
	private function send_json_success( $data ) {
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( $data );
		}

		echo wp_json_encode(
			[
				'success' => true,
				'data'    => $data,
			]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response fallback.
		exit;
	}

	/**
	 * Send JSON error.
	 *
	 * @param array $data Response data.
	 * @param int   $status HTTP status.
	 */
	private function send_json_error( $data, $status = 400 ) {
		if ( function_exists( 'wp_send_json_error' ) ) {
			wp_send_json_error( $data, $status );
		}

		if ( function_exists( 'status_header' ) ) {
			status_header( $status );
		}

		echo wp_json_encode(
			[
				'success' => false,
				'data'    => $data,
			]
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response fallback.
		exit;
	}
}
