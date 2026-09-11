<?php

namespace WpApp;

if ( class_exists( 'WpApp\Cli', false ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * WP-CLI commands for inspecting the apps installed on a site.
 *
 * Registered from functions.php, not here: PHP early-binds an unconditional
 * class declaration, so the guard above is already true on the first include
 * and anything after the class would never run.
 */
class Cli {

	/**
	 * Report assets that leak between apps, and duplicate element ids.
	 *
	 * Every app shares one page pipeline, so a hook registered globally, or an
	 * asset enqueued without a scope, ends up on every other app's pages. This
	 * fires each app's wp-app hooks in isolation and reports what came out that
	 * should not have.
	 *
	 * ## OPTIONS
	 *
	 * [--app=<path>]
	 * : Only check this app's URL path.
	 *
	 * [--format=<format>]
	 * : Render results as a table or as JSON.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp app doctor
	 *     wp app doctor --app=traveler
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function doctor( $args, $assoc_args ) {
		$only   = isset( $assoc_args['app'] ) ? trim( (string) $assoc_args['app'], '/' ) : '';
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$apps   = Registry::get_apps();

		if ( empty( $apps ) ) {
			\WP_CLI::warning( 'No apps are registered on this site.' );
			return;
		}

		$owners   = self::get_plugin_owners( $apps );
		$findings = [];
		$checked  = 0;

		foreach ( array_keys( $apps ) as $app_path ) {
			if ( '' !== $only && $only !== $app_path ) {
				continue;
			}

			++$checked;
			$findings = array_merge( $findings, self::check_app( $app_path, $owners ) );
		}

		$findings = self::collapse_site_wide( $findings, $checked );
		$findings = array_merge( $findings, self::check_global_hooks( $owners ) );

		if ( empty( $findings ) ) {
			\WP_CLI::success( 'No cross-app leaks or duplicate ids found.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $findings, [ 'app', 'problem', 'detail' ] );
		\WP_CLI::warning( sprintf( '%d problem(s) found.', count( $findings ) ) );
	}

	/**
	 * Report a site-wide problem once, rather than once per app.
	 *
	 * A leaked asset shows up on every app but the one that owns it, so the
	 * threshold is one less than the number checked.
	 *
	 * @param array $findings List of findings.
	 * @param int   $checked Number of apps checked.
	 * @return array List of findings.
	 */
	private static function collapse_site_wide( $findings, $checked ) {
		if ( $checked < 2 ) {
			return $findings;
		}

		$counts = [];
		foreach ( $findings as $finding ) {
			$key            = $finding['problem'] . '|' . $finding['detail'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		$collapsed = [];
		$emitted   = [];

		foreach ( $findings as $finding ) {
			$key = $finding['problem'] . '|' . $finding['detail'];

			if ( $counts[ $key ] < $checked - 1 ) {
				$collapsed[] = $finding;
				continue;
			}

			if ( isset( $emitted[ $key ] ) ) {
				continue;
			}

			$emitted[ $key ] = true;
			$finding['app']  = sprintf( '%d of %d apps', $counts[ $key ], $checked );
			$collapsed[]     = $finding;
		}

		return $collapsed;
	}

	/**
	 * Map each plugin directory to the app registered from it.
	 *
	 * @param array $apps Routers keyed by app URL path.
	 * @return array App URL paths keyed by plugin directory name.
	 */
	private static function get_plugin_owners( $apps ) {
		$owners = [];

		foreach ( $apps as $app_path => $router ) {
			if ( ! is_object( $router ) || ! method_exists( $router, 'get_template_directory' ) ) {
				continue;
			}

			$plugin = self::get_plugin_directory( $router->get_template_directory() );

			if ( '' !== $plugin ) {
				$owners[ $plugin ] = $app_path;
			}
		}

		return $owners;
	}

	/**
	 * Run one app's hooks and report what should not be in the output.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $owners App URL paths keyed by plugin directory name.
	 * @return array List of findings.
	 */
	private static function check_app( $app_path, $owners ) {
		global $wp_app_route, $wp_scripts, $wp_styles;

		// Firing the render hooks makes apps register their assets, which would
		// otherwise pile up across the apps checked after them.
		$saved_filter = self::copy_filters();
		$saved_route  = $wp_app_route;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Diagnostic renders one app at a time.
		$wp_app_route = [ 'app_path' => $app_path ];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Start each app with empty queues.
		$wp_scripts = null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Start each app with empty queues.
		$wp_styles = null;

		ob_start();
		do_action( 'wp_app_before_render', '', $wp_app_route );
		do_action( 'wp_app_head' );
		wp_app_do_scoped_action( 'wp_app_head_meta' );
		wp_app_do_scoped_action( 'wp_app_head_styles' );
		wp_app_do_scoped_action( 'wp_app_head_scripts' );
		do_action( 'wp_app_body_open' );
		wp_app_do_scoped_action( 'wp_app_body_close' );
		$output = ob_get_clean();

		$findings = array_merge(
			self::find_duplicate_ids( $app_path, $output ),
			self::find_foreign_assets( $app_path, self::get_urls( $output ), $owners, 'printed directly' ),
			self::find_foreign_assets( $app_path, self::get_enqueued_urls(), $owners, 'enqueued through WP_Scripts/WP_Styles' )
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the caller's state.
		$wp_app_route = $saved_route;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the caller's state.
		$GLOBALS['wp_filter'] = $saved_filter;

		return $findings;
	}

	/**
	 * A copy of the hook registry that can be restored afterwards.
	 *
	 * Cloning each WP_Hook is enough: its callbacks live in an array property,
	 * and PHP arrays copy by value.
	 *
	 * @return array
	 */
	private static function copy_filters() {
		$copy = [];

		foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
			$copy[ $name ] = is_object( $hook ) ? clone $hook : $hook;
		}

		return $copy;
	}

	/**
	 * An element id used more than once means something is registered per app
	 * that should be registered once for the site.
	 *
	 * @param string $app_path App URL path.
	 * @param string $output Captured markup.
	 * @return array List of findings.
	 */
	private static function find_duplicate_ids( $app_path, $output ) {
		if ( ! preg_match_all( '/\sid="([^"]+)"/', $output, $matches ) ) {
			return [];
		}

		$findings = [];

		foreach ( array_count_values( $matches[1] ) as $id => $count ) {
			if ( $count > 1 ) {
				$findings[] = [
					'app'     => $app_path,
					'problem' => 'duplicate id',
					'detail'  => sprintf( 'id="%s" appears %d times', $id, $count ),
				];
			}
		}

		return $findings;
	}

	/**
	 * Assets belonging to a plugin that registered a different app.
	 *
	 * @param string $app_path App URL path.
	 * @param array  $urls Asset URLs.
	 * @param array  $owners App URL paths keyed by plugin directory name.
	 * @param string $how How the asset reached the page.
	 * @return array List of findings.
	 */
	private static function find_foreign_assets( $app_path, $urls, $owners, $how ) {
		$findings = [];
		$seen     = [];

		foreach ( $urls as $url ) {
			$plugin = self::get_plugin_from_url( $url );

			if ( '' === $plugin || ! isset( $owners[ $plugin ] ) || $owners[ $plugin ] === $app_path ) {
				continue;
			}

			if ( isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;

			$findings[] = [
				'app'     => $app_path,
				'problem' => 'asset from another app',
				'detail'  => sprintf( '%s (%s, %s)', self::shorten( $url ), $owners[ $plugin ], $how ),
			];
		}

		return $findings;
	}

	/**
	 * App code on a hook every app fires is what usually causes the leaks above.
	 * Not a defect on its own - a callback that scopes its work correctly is
	 * fine - but it is where to look first.
	 *
	 * @param array $owners App URL paths keyed by plugin directory name.
	 * @return array List of findings.
	 */
	private static function check_global_hooks( $owners ) {
		global $wp_filter;

		$findings = [];

		foreach ( [ 'wp_app_head', 'wp_app_before_render', 'wp_app_body_open', 'wp_app_body_close' ] as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$file = self::get_callback_file( $callback['function'] );

					if ( null === $file ) {
						continue;
					}

					$plugin = self::get_plugin_directory( $file );

					if ( '' === $plugin || ! isset( $owners[ $plugin ] ) ) {
						continue;
					}

					$findings[] = [
						'app'     => $owners[ $plugin ],
						'problem' => 'runs on a global hook',
						'detail'  => sprintf( '%s fires for every app; check this acts only for its own (%s)', $hook, self::shorten( $file ) ),
					];
				}
			}
		}

		return $findings;
	}

	/**
	 * The file a hook callback is defined in.
	 *
	 * @param mixed $callback Hook callback.
	 * @return string|null Absolute path, or null when it cannot be determined.
	 */
	private static function get_callback_file( $callback ) {
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new \ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0], $callback[1] );
			} elseif ( $callback instanceof \Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$reflection = new \ReflectionFunction( $callback );
			} else {
				return null;
			}
		} catch ( \ReflectionException $e ) {
			return null;
		}

		$file = $reflection->getFileName();

		return false === $file ? null : $file;
	}

	/**
	 * Asset URLs appearing in markup.
	 *
	 * @param string $output Captured markup.
	 * @return array List of URLs.
	 */
	private static function get_urls( $output ) {
		if ( ! preg_match_all( '/(?:src|href)="([^"]+)"/', $output, $matches ) ) {
			return [];
		}

		return $matches[1];
	}

	/**
	 * Asset URLs sitting in the core enqueue queues.
	 *
	 * @return array List of URLs.
	 */
	private static function get_enqueued_urls() {
		$urls = [];

		foreach ( [ wp_scripts(), wp_styles() ] as $registry ) {
			foreach ( $registry->queue as $handle ) {
				if ( isset( $registry->registered[ $handle ]->src ) && is_string( $registry->registered[ $handle ]->src ) ) {
					$urls[] = $registry->registered[ $handle ]->src;
				}
			}
		}

		return $urls;
	}

	/**
	 * The plugin directory name a URL points into.
	 *
	 * @param string $url Asset URL.
	 * @return string Directory name, or '' when the URL is not a plugin asset.
	 */
	private static function get_plugin_from_url( $url ) {
		if ( ! preg_match( '#/plugins/([^/?]+)/#', (string) $url, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * The plugin directory name a path sits in.
	 *
	 * @param string $path Absolute path.
	 * @return string Directory name, or '' when the path is outside the plugins directory.
	 */
	private static function get_plugin_directory( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}

		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/';

		if ( 0 !== strpos( $path, $root ) ) {
			return '';
		}

		$relative = substr( $path, strlen( $root ) );
		$slash    = strpos( $relative, '/' );

		return false === $slash ? $relative : substr( $relative, 0, $slash );
	}

	/**
	 * Trim a URL or path down to the part worth reading in a table.
	 *
	 * @param string $value URL or path.
	 * @return string
	 */
	private static function shorten( $value ) {
		$position = strpos( (string) $value, '/plugins/' );

		return false === $position ? (string) $value : substr( (string) $value, $position + 9 );
	}
}
