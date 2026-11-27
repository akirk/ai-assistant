<?php

namespace WpApp;

if ( class_exists( 'WpApp\Pwa' ) ) {
	return;
}

/**
 * Progressive Web App support for WpApp applications.
 */
class Pwa {
	const APP_SHELL_SUPPORTED     = true;
	const OFFLINE_PAGES_SUPPORTED = true;
	const MANIFEST_REQUEST        = 'wp-app-manifest';
	const SERVICE_WORKER_REQUEST  = 'wp-app-service-worker';

	/**
	 * Registered PWA configurations keyed by app path.
	 *
	 * @var array
	 */
	private static $apps = [];

	/**
	 * Register PWA support for an app.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $config PWA configuration.
	 * @return array Normalized PWA configuration.
	 */
	public static function register( $app_path, $config = [] ) {
		$app_path = trim( (string) $app_path, '/' );

		if ( '' === $app_path ) {
			throw new \InvalidArgumentException( 'PWA support requires a non-empty WpApp path.' );
		}

		$config                  = self::normalize_config( $app_path, $config );
		self::$apps[ $app_path ] = $config;

		self::register_template_hooks( $app_path, $config );

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'redirect_canonical', [ __CLASS__, 'filter_canonical_redirect' ], 10, 2 );
		}

		if ( function_exists( 'did_action' ) && ( did_action( 'init' ) || doing_action( 'init' ) ) ) {
			self::add_rewrite_rules_for_app( $app_path );
		}

		return $config;
	}

	/**
	 * Prevent canonical redirects for registered PWA endpoints.
	 *
	 * WordPress can append a trailing slash to file-like endpoint URLs. Service
	 * worker registration rejects redirected script responses, so these exact
	 * endpoints must be served without a canonical redirect.
	 *
	 * @param string|false $redirect_url  Proposed canonical URL.
	 * @param string       $requested_url Requested URL.
	 * @return string|false
	 */
	public static function filter_canonical_redirect( $redirect_url, $requested_url ) {
		$app_path     = trim( (string) get_query_var( 'wp_app_path' ), '/' );
		$request_path = trim( (string) get_query_var( 'wp_app_request' ), '/' );

		if (
			isset( self::$apps[ $app_path ] )
			&& in_array( $request_path, self::get_endpoint_paths( self::$apps[ $app_path ] ), true )
		) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Add rewrite rules for PWA endpoints that are not covered by the app router.
	 *
	 * WpApp's generic app rewrite intentionally excludes dotted paths. Manifest
	 * and service worker URLs often use file-like names, so register exact PWA
	 * endpoint rewrites too.
	 *
	 * @param string $app_path App URL path.
	 */
	public static function add_rewrite_rules_for_app( $app_path ) {
		$app_path = trim( (string) $app_path, '/' );

		if ( ! isset( self::$apps[ $app_path ] ) || ! function_exists( 'add_rewrite_rule' ) ) {
			return;
		}

		foreach ( self::get_endpoint_paths( self::$apps[ $app_path ] ) as $path ) {
			$path = trim( (string) $path, '/' );
			if ( '' === $path ) {
				continue;
			}

			add_rewrite_rule(
				'^' . preg_quote( $app_path, '/' ) . '/' . preg_quote( $path, '/' ) . '/?$',
				'index.php?wp_app_request=' . $path . '&wp_app_path=' . $app_path,
				'top'
			);
		}
	}

	/**
	 * Determine whether a WpApp request belongs to the PWA support endpoints.
	 *
	 * @param string $app_path App URL path.
	 * @param string $request_path Request path under the app.
	 * @return bool Whether the request was handled.
	 */
	public static function maybe_handle_app_request( $app_path, $request_path ) {
		$app_path     = trim( (string) $app_path, '/' );
		$request_path = trim( (string) $request_path, '/' );

		if ( ! isset( self::$apps[ $app_path ] ) ) {
			return false;
		}

		$config = self::$apps[ $app_path ];

		if ( in_array( $request_path, $config['manifest_paths'], true ) ) {
			self::send_manifest( $config );
			return true;
		}

		if ( in_array( $request_path, $config['service_worker_paths'], true ) ) {
			self::send_service_worker( $config );
			return true;
		}

		if ( ! empty( $config['offline_shell'] ) && $request_path === $config['offline_shell_path'] ) {
			global $wp_app_route;
			$previous     = $wp_app_route;
			$wp_app_route = [
				'app_path' => $app_path,
				'pattern'  => $request_path,
			];
			try {
				do_action( 'wp_app_before_render', __DIR__ . '/templates/wp-app-shell.php', $wp_app_route );
				self::render_shell( $app_path, true );
			} finally {
				$wp_app_route = $previous; }
			return true;
		}
		return false;
	}

	/**
	 * Get a registered PWA config.
	 *
	 * @param string $app_path App URL path.
	 * @return array|null Registered config, or null when absent.
	 */
	public static function get_config( $app_path ) {
		$app_path = trim( (string) $app_path, '/' );

		return self::$apps[ $app_path ] ?? null;
	}

	/**
	 * Get the manifest URL for an app, with optional query args.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $args Query arguments.
	 * @return string Manifest URL.
	 */
	public static function get_manifest_url( $app_path, $args = [] ) {
		$app_path = trim( (string) $app_path, '/' );
		$config   = self::$apps[ $app_path ] ?? self::normalize_config( $app_path, [] );
		$url      = $config['manifest_url'];

		return self::add_query_args( $url, is_array( $args ) ? $args : [] );
	}

	/** Render an app document; only the isolated fallback is suitable for precaching. */
	public static function render_shell( $app_path, $offline = false ) {
		$config = self::get_config( $app_path );
		$shell  = $config['offline_shell'] ?? [];
		if ( empty( $shell['content'] ) || ! is_file( $shell['content'] ) ) {
			throw new \InvalidArgumentException( 'An app shell requires an existing content template.' );
		}
		if ( ! empty( $shell['capability'] ) && ! current_user_can( $shell['capability'] ) ) {
			wp_die( 'Sign in with permission to open this app.', '', [ 'response' => 403 ] );
		}
		$bootstrap = [
			'url'     => $shell['bootstrap_url'],
			'offline' => $shell['offline_data'],
			'worker'  => $config['register_service_worker'] ? [
				'url'                    => $config['service_worker_url'],
				'scope'                  => $config['scope_path'],
				'cacheMessageType'       => $config['cache_message_type'],
				'cacheStatusMessageType' => $config['cache_status_message_type'],
				'cacheName'              => $config['cache_name'],
				'offlineUrl'             => $config['offline_url'],
				'requiredUrls'           => $config['precache'],
			] : null,
		];
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			do_action( 'wp_enqueue_scripts' );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Wp-App-Offline: ' . $config['cache_identity'] );
			if ( $offline || ! empty( $shell['csp'] ) ) {
				header( 'Content-Security-Policy: ' . ( $shell['csp'] ?? "script-src 'self'; object-src 'none'; base-uri 'self'" ) );
			}
		}
		require __DIR__ . '/templates/wp-app-shell.php';
	}

	/** Version the selected framework's offline runtime, independent of app files. */
	public static function runtime_version() {
		static $version;
		if ( ! $version ) {
			$hashes = [ hash_file( 'sha256', __FILE__ ), hash_file( 'sha256', __DIR__ . '/templates/wp-app-shell.php' ) ];
			foreach ( [ 'offline/worker.js', 'offline/outbox.js', 'offline/client.js', 'offline/data.js' ] as $asset ) {
				$hashes[] = hash_file( 'sha256', dirname( __DIR__ ) . '/assets/' . $asset );
			}
			$version = WP_APP_VERSION . '-' . substr( hash( 'sha256', implode( '', $hashes ) ), 0, 12 );
		}
		return $version;
	}

	/** Resolve runtime assets from the selected provider for enabled features. */
	private static function runtime_assets( $config ) {
		$assets = [];
		if ( ! empty( $config['outbox'] ) || ! empty( $config['local_data'] ) ) {
			$assets['wp-app-offline-data'] = 'offline/data.js';
		}
		if ( ! empty( $config['outbox'] ) ) {
			$assets['wp-app-outbox'] = 'offline/outbox.js';
		}
		if ( ! empty( $config['visited_pages'] ) || ! empty( $config['offline_shell'] ) ) {
			$assets['wp-app-offline-client'] = 'offline/client.js';
		}

		foreach ( $assets as $handle => $file ) {
			$assets[ $handle ] = self::add_query_args( wp_app_get_asset_url( $file ), [ 'ver' => self::runtime_version() ] );
		}
		return $assets;
	}

	/**
	 * Register head and service worker registration output.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $config Normalized PWA config.
	 */
	private static function register_template_hooks( $app_path, $config ) {
		$enqueue = static function () use ( $app_path, $config ) {
			foreach ( $config['runtime_assets'] as $handle => $url ) {
				wp_app_enqueue_script( $handle, $url, [], null, false, $app_path );
			}
		};
		if ( function_exists( 'did_action' ) && did_action( 'wp_enqueue_scripts' ) ) {
			$enqueue(); } else {
			add_action( 'wp_enqueue_scripts', $enqueue ); }

			if ( $config['head_tags'] ) {
				$meta_hook = 'wp_app_head_meta_' . self::sanitize_hook_suffix( $app_path );
				add_action(
                    $meta_hook,
                    function () use ( $config ) {
                        self::render_head_tags( $config );
                    }
				);
			}

			if ( $config['register_service_worker'] && empty( $config['offline_shell'] ) ) {
				$script_hook = 'wp_app_body_close_' . self::sanitize_hook_suffix( $app_path );
				add_action(
                    $script_hook,
                    function () use ( $config ) {
                        self::render_registration_script( $config );
                    }
				);
			}
	}

	/**
	 * Output PWA tags for app templates.
	 *
	 * @param array $config Normalized PWA config.
	 */
	private static function render_head_tags( $config ) {
		echo '<link rel="manifest" href="' . esc_url( $config['manifest_url'] ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $config['manifest']['theme_color'] ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $config['manifest']['short_name'] ) . '">' . "\n";
	}

	/**
	 * Output the service worker registration script.
	 *
	 * @param array $config Normalized PWA config.
	 */
	private static function render_registration_script( $config ) {
		$registration = [
			'serviceWorkerUrl'        => $config['service_worker_url'],
			'scope'                   => $config['scope_path'],
			'cacheMessageType'        => $config['cache_message_type'],
			'cacheStatusMessageType'  => $config['cache_status_message_type'],
			'versionMessageType'      => $config['version_message_type'],
			'syncMessageType'         => $config['sync_message_type'],
			'cacheUrls'               => $config['client_cache_urls'],
			'cacheSelector'           => $config['client_cache_selector'],
			'cacheUrlAttribute'       => $config['client_cache_url_attribute'],
			'cacheAvailableAttribute' => $config['client_cache_available_attribute'],
			'cacheStatusEvent'        => $config['client_cache_status_event'],
			'versionEvent'            => $config['client_version_event'],
			'syncEvent'               => $config['client_sync_event'],
		];
		?>
<script id="wp-app-pwa-registration-js">
(function(config) {
	var api = window.wpAppPwa || {};
	window.wpAppPwa = api;

	function emit(name, detail) {
		if (!name || !window.dispatchEvent) {
			return;
		}

		if (typeof window.CustomEvent === 'function') {
			window.dispatchEvent(new CustomEvent(name, { detail: detail }));
			return;
		}

		var event = document.createEvent('CustomEvent');
		event.initCustomEvent(name, false, false, detail);
		window.dispatchEvent(event);
	}

	function cacheUrlFor(element) {
		var url = element.getAttribute(config.cacheUrlAttribute) || element.getAttribute('href') || element.getAttribute('src');
		return url ? new URL(url, window.location.href).href : '';
	}

	function cacheElements() {
		return Array.prototype.slice.call(document.querySelectorAll(config.cacheSelector));
	}

	function updateCacheAvailability(cachedUrls) {
		var cached = {};
		(cachedUrls || []).forEach(function(url) {
			cached[url] = true;
		});

		cacheElements().forEach(function(element) {
			var available = !!cached[cacheUrlFor(element)];
			element.toggleAttribute(config.cacheAvailableAttribute, available);
		});
	}

	api.cacheUrls = function(urls, requiredUrls) {
		if (!('serviceWorker' in navigator)) {
			return Promise.reject(new Error('Service workers are not supported.'));
		}

		return navigator.serviceWorker.ready.then(function(registration) {
			if (!registration.active) {
				throw new Error('Service worker is not active.');
			}

			registration.active.postMessage({
				type: config.cacheMessageType,
				urls: urls || [],
				requiredUrls: requiredUrls || urls || []
			});
		});
	};

	api.checkCache = function() {
		var requiredUrls = [window.location.href].concat(config.cacheUrls || []);
		var urls = requiredUrls.slice();

		cacheElements().forEach(function(element) {
			var url = cacheUrlFor(element);
			if (url) {
				urls.push(url);
			}
		});

		return api.cacheUrls(urls, requiredUrls);
	};

	if (!window.isSecureContext || !('serviceWorker' in navigator)) {
		return;
	}

	navigator.serviceWorker.addEventListener('message', function(event) {
		if (!event.data) {
			return;
		}

		if (event.data.type === config.cacheStatusMessageType) {
			updateCacheAvailability(event.data.cachedUrls || []);
			emit(config.cacheStatusEvent, event.data);
		}

		if (event.data.type === config.versionMessageType) {
			emit(config.versionEvent, event.data);
		}

		if (event.data.type === config.syncMessageType) {
			emit(config.syncEvent, event.data);
		}
	});

	window.addEventListener('load', function() {
		navigator.serviceWorker.register(config.serviceWorkerUrl, { scope: config.scope }).then(function() {
			return navigator.serviceWorker.ready;
		}).then(function(registration) {
			if (registration.active) {
				registration.active.postMessage({ type: config.versionMessageType });
			}
			return api.checkCache();
		}).catch(function(error) {
			if (window.console && window.console.warn) {
				window.console.warn('WpApp PWA service worker registration failed.', error);
			}
		});
	});
})(<?php echo wp_json_encode( $registration ); ?>);
</script>
		<?php
	}

	/**
	 * Send the generated web app manifest.
	 *
	 * @param array $config Normalized PWA config.
	 */
	private static function send_manifest( $config ) {
		self::send_header( 'Content-Type: application/manifest+json; charset=UTF-8' );
		echo wp_json_encode( self::get_manifest( $config ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get manifest data after WordPress filters have run.
	 *
	 * @param array $config Normalized PWA config.
	 * @return array Manifest data.
	 */
	private static function get_manifest( $config ) {
		$manifest = $config['manifest'];

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the generated PWA manifest for any WpApp app.
			 *
			 * @param array $manifest Web app manifest data.
			 * @param array $config   Normalized PWA config.
			 */
			$manifest = apply_filters( 'wp_app_pwa_manifest', $manifest, $config );

			/**
			 * Filters the generated PWA manifest for a specific WpApp app.
			 *
			 * The dynamic portion of the hook name is the sanitized app path.
			 *
			 * @param array $manifest Web app manifest data.
			 * @param array $config   Normalized PWA config.
			 */
			$manifest = apply_filters( 'wp_app_pwa_manifest_' . self::sanitize_hook_suffix( $config['app_path'] ), $manifest, $config );
		}

		return is_array( $manifest ) ? $manifest : $config['manifest'];
	}

	/**
	 * Send the generated service worker.
	 *
	 * @param array $config Normalized PWA config.
	 */
	private static function send_service_worker( $config ) {
		self::send_header( 'Cache-Control: no-store' );
		self::send_header( 'Content-Type: application/javascript; charset=UTF-8' );
		self::send_header( 'Service-Worker-Allowed: ' . $config['service_worker_allowed'] );
		echo self::get_service_worker_script( $config );
	}

	/**
	 * Get the generated service worker JavaScript.
	 *
	 * @param array $config Normalized PWA config.
	 * @return string Service worker script.
	 */
	private static function get_service_worker_script( $config ) {
		$service_worker_config = [
			'cacheName'              => $config['cache_name'],
			'cachePolicy'            => $config['cache_policy'],
			'visitedPages'           => $config['visited_pages'],
			'cacheIdentity'          => $config['cache_identity'],
			'cachePrefix'            => $config['cache_prefix'],
			'precache'               => $config['precache'],
			'offlineUrl'             => $config['offline_url'],
			'scopeUrl'               => $config['scope_url'],
			'cacheablePaths'         => $config['cacheable_paths'],
			'cacheableSearchParams'  => $config['cacheable_search_params'],
			'cacheMessageType'       => $config['cache_message_type'],
			'cacheStatusMessageType' => $config['cache_status_message_type'],
			'versionMessageType'     => $config['version_message_type'],
			'syncTag'                => $config['sync_tag'],
			'syncMessageType'        => $config['sync_message_type'],
			'appData'                => $config['worker_data'],
		];

		$extensions = '';
		foreach ( $config['worker_sources'] as $source ) {
			// Separate lexical scopes let extensions use their own top-level names.
			$extensions .= "\n;(function () {\n" . $source . "\n}).call(self);\n";
		}

		return 'self.wpAppWorker = ' . wp_json_encode( $service_worker_config ) . ";\n"
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a bundled local asset, never a remote URL.
			. file_get_contents( dirname( __DIR__ ) . '/assets/offline/worker.js' ) . $extensions;
	}

	/**
	 * Normalize a PWA configuration array.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $config PWA configuration.
	 * @return array Normalized configuration.
	 */
	private static function normalize_config( $app_path, $config ) {
		$config = is_array( $config ) ? $config : [];

		$app_url             = self::app_url( $app_path, '' );
		$manifest_path       = isset( $config['manifest_path'] ) ? trim( (string) $config['manifest_path'], '/' ) : self::MANIFEST_REQUEST;
		$service_worker_path = isset( $config['service_worker_path'] ) ? trim( (string) $config['service_worker_path'], '/' ) : self::SERVICE_WORKER_REQUEST;
		$manifest_url        = self::app_url( $app_path, $manifest_path );
		$service_worker_url  = self::app_url( $app_path, $service_worker_path );
		$name                = isset( $config['name'] ) ? (string) $config['name'] : ucwords( str_replace( [ '-', '_' ], ' ', $app_path ) );
		$short_name          = isset( $config['short_name'] ) ? (string) $config['short_name'] : $name;
		$theme_color         = isset( $config['theme_color'] ) ? (string) $config['theme_color'] : '#2271b1';
		$background_color    = isset( $config['background_color'] ) ? (string) $config['background_color'] : '#ffffff';
		$scope_url           = isset( $config['scope'] ) ? self::normalize_url( $config['scope'] ) : $app_url;
		$offline_url         = isset( $config['offline_url'] ) ? self::normalize_url( $config['offline_url'] ) : ( ! empty( $config['offline_shell'] ) ? self::app_url( $app_path, 'offline/' ) : $app_url );
		$precache            = isset( $config['precache'] ) && is_array( $config['precache'] ) ? $config['precache'] : [];
		$cache_policy        = $config['cache_policy'] ?? ( ! empty( $config['offline_shell'] ) ? 'shell' : 'scope' );
		if ( ! in_array( $cache_policy, [ 'scope', 'shell' ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown PWA cache policy.' );
		}
		if ( 'shell' === $cache_policy && empty( $config['offline_url'] ) && empty( $config['offline_shell'] ) ) {
			throw new \InvalidArgumentException( 'Shell caching requires an explicit offline_url.' );
		}
		if ( ! empty( $config['visited_pages'] ) && ( 'shell' !== $cache_policy || empty( $config['cache_identity'] ) ) ) {
			throw new \InvalidArgumentException( 'Visited pages require shell caching and a non-empty cache_identity.' );
		}
		if ( ! empty( $config['offline_shell'] ) ) {
			if ( 'shell' !== $cache_policy ) {
				throw new \InvalidArgumentException( 'An app shell requires shell caching.' );
			}
			$shell = $config['offline_shell'];
			if ( ! is_array( $shell ) || empty( $shell['content'] ) || ! is_file( $shell['content'] ) || empty( $shell['bootstrap_url'] ) || ! isset( $shell['offline_data']['userId'] ) ) {
				throw new \InvalidArgumentException( 'An app shell requires content, bootstrap_url, and offline_data.userId.' );
			}
			if ( isset( $config['cache_identity'] ) && (string) $config['cache_identity'] !== (string) $shell['offline_data']['userId'] ) {
				throw new \InvalidArgumentException( 'Shell bootstrap and cache identities must match.' );
			}
			$config['cache_identity'] = (string) $shell['offline_data']['userId'];
			$shell_scope_path         = self::url_path( $app_url );
			$offline_path             = self::url_path( $offline_url );
			if ( 0 !== strpos( $offline_path, $shell_scope_path ) || strlen( $offline_path ) <= strlen( $shell_scope_path ) ) {
				throw new \InvalidArgumentException( 'An app shell needs a dedicated fallback path inside its app scope.' );
			}
			foreach ( [ PHP_URL_HOST, PHP_URL_SCHEME, PHP_URL_PORT ] as $component ) {
				$value = wp_parse_url( $offline_url, $component );
				if ( null !== $value && $value !== wp_parse_url( $app_url, $component ) ) {
					throw new \InvalidArgumentException( 'An app shell fallback must have the app origin.' );
				}
			}
		}
		$worker_scripts = $config['worker_scripts'] ?? [];
		$worker_data    = $config['worker_data'] ?? [];
		if ( ! is_array( $worker_scripts ) || ! is_array( $worker_data ) ) {
			throw new \InvalidArgumentException( 'Worker scripts and data must be arrays.' );
		}
		$worker_sources = [];
		foreach ( $worker_scripts as $file ) {
			$path = is_string( $file ) ? realpath( $file ) : false;
			if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new \InvalidArgumentException( 'Worker scripts must be readable local files.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read app-registered local worker code, never a remote URL.
			$source = file_get_contents( $path );
			if ( false === $source ) {
				throw new \InvalidArgumentException( 'Could not read worker script.' );
			}
			$worker_sources[] = $source;
		}
		$worker_signature = $worker_sources || $worker_data ? wp_json_encode( [ $worker_sources, $worker_data ] ) : '';
		if ( false === $worker_signature ) {
			throw new \InvalidArgumentException( 'Worker scripts and data must be JSON serializable.' );
		}
		$runtime_assets  = self::runtime_assets( $config );
		$precache        = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'normalize_url' ], array_merge( 'shell' === $cache_policy ? [ $offline_url ] : [ $app_url, $offline_url ], $precache, array_values( $runtime_assets ) ) ) ) ) );
		$cache_prefix    = isset( $config['cache_prefix'] ) ? (string) $config['cache_prefix'] : 'wp-app-' . sanitize_key( $app_path ) . '-';
		$scope_path      = self::url_path( $scope_url );
		$cacheable_paths = isset( $config['cacheable_paths'] ) && is_array( $config['cacheable_paths'] ) ? $config['cacheable_paths'] : [];
		$cacheable_paths = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'normalize_path' ], array_merge( [ $scope_path ], $cacheable_paths ) ) ) ) );

		$manifest = [
			'name'             => $name,
			'short_name'       => $short_name,
			'start_url'        => isset( $config['start_url'] ) ? self::normalize_url( $config['start_url'] ) : $app_url,
			'scope'            => $scope_url,
			'display'          => isset( $config['display'] ) ? (string) $config['display'] : 'standalone',
			'background_color' => $background_color,
			'theme_color'      => $theme_color,
		];

		if ( isset( $config['description'] ) ) {
			$manifest['description'] = (string) $config['description'];
		}

		if ( isset( $config['orientation'] ) ) {
			$manifest['orientation'] = (string) $config['orientation'];
		}

		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) ) {
			$manifest['icons'] = array_values( $config['icons'] );
		}

		return [
			'cache_policy'                     => $cache_policy,
			'outbox'                           => ! empty( $config['outbox'] ),
			'local_data'                       => ! empty( $config['local_data'] ),
			'offline_shell'                    => $config['offline_shell'] ?? [],
			'offline_shell_path'               => ! empty( $config['offline_shell'] ) ? trim( substr( self::url_path( $offline_url ), strlen( self::url_path( $app_url ) ) ), '/' ) : '',
			'runtime_assets'                   => $runtime_assets,
			'worker_scripts'                   => $worker_scripts,
			'worker_sources'                   => $worker_sources,
			'worker_data'                      => $worker_data,
			'visited_pages'                    => ! empty( $config['visited_pages'] ),
			'cache_identity'                   => isset( $config['cache_identity'] ) ? (string) $config['cache_identity'] : '',
			'app_path'                         => $app_path,
			'manifest_path'                    => $manifest_path,
			'manifest_paths'                   => self::normalize_endpoint_paths( $manifest_path, $config['manifest_aliases'] ?? [] ),
			'manifest_url'                     => $manifest_url,
			'service_worker_path'              => $service_worker_path,
			'service_worker_paths'             => self::normalize_endpoint_paths( $service_worker_path, $config['service_worker_aliases'] ?? [] ),
			'service_worker_url'               => $service_worker_url,
			'service_worker_allowed'           => isset( $config['service_worker_allowed'] ) ? self::normalize_path( $config['service_worker_allowed'] ) : $scope_path,
			'scope_url'                        => $scope_url,
			'scope_path'                       => $scope_path,
			'offline_url'                      => $offline_url,
			'cache_name'                       => ( isset( $config['cache_name'] ) ? (string) $config['cache_name'] : $cache_prefix . 'v1' ) . ( $runtime_assets || $worker_sources ? '-' . self::runtime_version() : '' )
				. ( $worker_signature ? '-' . substr( hash( 'sha256', $worker_signature ), 0, 12 ) : '' )
				. ( ! empty( $config['cache_identity'] ) ? '-' . md5( (string) $config['cache_identity'] ) : '' ),
			'cache_prefix'                     => $cache_prefix,
			'cacheable_paths'                  => $cacheable_paths,
			'cacheable_search_params'          => isset( $config['cacheable_search_params'] ) && is_array( $config['cacheable_search_params'] ) ? array_values( $config['cacheable_search_params'] ) : [],
			'cache_message_type'               => isset( $config['cache_message_type'] ) ? (string) $config['cache_message_type'] : 'wp-app-cache-url',
			'cache_status_message_type'        => isset( $config['cache_status_message_type'] ) ? (string) $config['cache_status_message_type'] : 'wp-app-cache-status',
			'version_message_type'             => isset( $config['version_message_type'] ) ? (string) $config['version_message_type'] : 'wp-app-version',
			'sync_tag'                         => isset( $config['sync_tag'] ) ? (string) $config['sync_tag'] : 'wp-app-sync',
			'sync_message_type'                => isset( $config['sync_message_type'] ) ? (string) $config['sync_message_type'] : 'wp-app-sync',
			'client_cache_urls'                => isset( $config['client_cache_urls'] ) && is_array( $config['client_cache_urls'] ) ? array_values( array_filter( array_map( [ __CLASS__, 'normalize_url' ], $config['client_cache_urls'] ) ) ) : [],
			'client_cache_selector'            => isset( $config['client_cache_selector'] ) ? (string) $config['client_cache_selector'] : '[data-wp-app-cache-url]',
			'client_cache_url_attribute'       => isset( $config['client_cache_url_attribute'] ) ? (string) $config['client_cache_url_attribute'] : 'data-wp-app-cache-url',
			'client_cache_available_attribute' => isset( $config['client_cache_available_attribute'] ) ? (string) $config['client_cache_available_attribute'] : 'data-wp-app-cache-available',
			'client_cache_status_event'        => isset( $config['client_cache_status_event'] ) ? (string) $config['client_cache_status_event'] : 'wp-app-pwa-cache-status',
			'client_version_event'             => isset( $config['client_version_event'] ) ? (string) $config['client_version_event'] : 'wp-app-pwa-version',
			'client_sync_event'                => isset( $config['client_sync_event'] ) ? (string) $config['client_sync_event'] : 'wp-app-pwa-sync',
			'head_tags'                        => ! isset( $config['head_tags'] ) || (bool) $config['head_tags'],
			'register_service_worker'          => ! isset( $config['register_service_worker'] ) || (bool) $config['register_service_worker'],
			'precache'                         => $precache,
			'manifest'                         => $manifest,
		];
	}

	/**
	 * Get all endpoint paths for rewrite registration.
	 *
	 * @param array $config Normalized PWA config.
	 * @return array Endpoint paths.
	 */
	private static function get_endpoint_paths( $config ) {
		return array_values( array_unique( array_merge( $config['manifest_paths'], $config['service_worker_paths'], ! empty( $config['offline_shell_path'] ) ? [ $config['offline_shell_path'] ] : [] ) ) );
	}

	/**
	 * Normalize endpoint paths.
	 *
	 * @param string $primary Primary endpoint path.
	 * @param array  $aliases Alias endpoint paths.
	 * @return array Normalized endpoint paths.
	 */
	private static function normalize_endpoint_paths( $primary, $aliases ) {
		$aliases = is_array( $aliases ) ? $aliases : [];
		$paths   = array_merge( [ $primary ], $aliases );

		return array_values( array_unique( array_filter( array_map( [ __CLASS__, 'normalize_endpoint_path' ], $paths ) ) ) );
	}

	/**
	 * Normalize an endpoint path.
	 *
	 * @param mixed $path Endpoint path.
	 * @return string Endpoint path.
	 */
	private static function normalize_endpoint_path( $path ) {
		return is_scalar( $path ) ? trim( (string) $path, '/' ) : '';
	}

	/**
	 * Build an absolute URL under an app path.
	 *
	 * @param string $app_path App URL path.
	 * @param string $path Relative path under the app.
	 * @return string App URL.
	 */
	private static function app_url( $app_path, $path ) {
		$relative = '/' . trim( $app_path, '/' ) . '/';
		$path     = trim( (string) $path, '/' );

		if ( '' !== $path ) {
			$relative .= $path;
		}

		return function_exists( 'home_url' ) ? home_url( $relative ) : $relative;
	}

	/**
	 * Normalize a URL-like config value.
	 *
	 * @param mixed $url URL value.
	 * @return string URL string.
	 */
	private static function normalize_url( $url ) {
		return is_scalar( $url ) ? (string) $url : '';
	}

	/**
	 * Normalize a URL path.
	 *
	 * @param mixed $path URL or path value.
	 * @return string Path with leading slash.
	 */
	private static function normalize_path( $path ) {
		if ( ! is_scalar( $path ) ) {
			return '/';
		}

		$path = (string) $path;
		if ( false !== strpos( $path, '://' ) ) {
			$path = self::url_path( $path );
		}

		$path = '/' . ltrim( $path, '/' );

		return '/' === $path ? '/' : rtrim( $path, '/' ) . '/';
	}

	/**
	 * Get a URL path.
	 *
	 * @param string $url URL.
	 * @return string URL path.
	 */
	private static function url_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		return self::normalize_path( $path );
	}

	/**
	 * Add query arguments to a URL.
	 *
	 * @param string $url URL.
	 * @param array  $args Query args.
	 * @return string URL with query args.
	 */
	private static function add_query_args( $url, $args ) {
		$args = array_filter(
			$args,
			function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);

		if ( empty( $args ) ) {
			return $url;
		}

		if ( function_exists( 'add_query_arg' ) ) {
			return add_query_arg( $args, $url );
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}

	/**
	 * Send a header if headers are still available.
	 *
	 * @param string $header Header line.
	 */
	private static function send_header( $header ) {
		if ( ! headers_sent() ) {
			header( $header );
		}
	}

	/**
	 * Sanitize a hook suffix.
	 *
	 * @param string $app_path App URL path.
	 * @return string Hook suffix.
	 */
	private static function sanitize_hook_suffix( $app_path ) {
		if ( function_exists( 'wp_app_sanitize_hook_suffix' ) ) {
			return wp_app_sanitize_hook_suffix( $app_path );
		}

		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $app_path );
		}

		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $app_path ) );
	}
}
