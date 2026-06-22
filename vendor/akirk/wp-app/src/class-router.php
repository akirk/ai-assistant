<?php

namespace WpApp;

if ( class_exists( 'WpApp\Router' ) ) {
    return;
}

/**
 * Router class for handling URL pattern matching and template loading in WordPress
 */
class Router {
    private $routes              = [];
    private $template_directory  = '';
    private $url_path            = 'app';
    private $required_capability = null;

    public function __construct( $template_directory = '', $url_path = 'app' ) {
        $this->template_directory = $template_directory;
        $this->url_path           = trim( $url_path, '/' );

        $this->maybe_switch_to_user_locale_for_current_request();

        // Register this router with the global registry instead of adding hooks directly
        Registry::register_app( $this );
    }

    /**
     * Switch to the signed-in user's locale as soon as an app router is created
     * for the current request.
     *
     * Apps often translate labels while registering routes and menus on init,
     * before query vars are available and before templates are rendered.
     */
    private function maybe_switch_to_user_locale_for_current_request() {
        if (
            $this->url_path === ''
            || empty( $_SERVER['REQUEST_URI'] )
            || ! is_string( $_SERVER['REQUEST_URI'] )
            || ! is_user_logged_in()
            || ! function_exists( 'switch_to_user_locale' )
        ) {
            return;
        }

        $request_uri = function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : $_SERVER['REQUEST_URI'];
        $path        = wp_parse_url( $request_uri, PHP_URL_PATH );
        if ( ! is_string( $path ) ) {
            return;
        }

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
        $path      = trim( $path, '/' );

        if ( $home_path !== '' && strpos( $path, $home_path . '/' ) === 0 ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }

        if ( $path === $this->url_path || strpos( $path, $this->url_path . '/' ) === 0 ) {
            switch_to_user_locale( get_current_user_id() );
        }
    }

    /**
     * Add a route with URL pattern and optional template
     *
     * @param string $pattern URL pattern relative to app path (e.g. 'posts', 'user/(\d+)', 'posts/([^/]+)')
     * @param string $template Optional template file path (will auto-discover if not provided)
     * @param array $vars Query variables to map to URL segments (auto-detected if not provided)
     * @param string $capability Optional capability required for this specific route
     */
    public function add_route( $pattern, $template = '', $vars = [], $capability = null ) {
        // Remove leading slash from pattern
        $pattern = ltrim( $pattern, '/' );

        // Auto-detect vars from original pattern BEFORE conversion
        if ( empty( $vars ) ) {
            $vars = $this->extract_vars_from_pattern( $pattern );
        }

        // Convert shorthand {id} syntax to WordPress-style regex if used
        $pattern = $this->convert_shorthand_to_wordpress_regex( $pattern );

        // Auto-detect template if not provided
        if ( empty( $template ) ) {
            $template = $this->auto_discover_template( $pattern );
        }

        $this->routes[ $pattern ] = [
            'pattern'    => $pattern,
            'template'   => $template,
            'vars'       => $vars,
            'regex'      => $this->build_wordpress_regex( $pattern ),
            'capability' => $capability,
        ];
    }

    /**
     * Set the minimum capability required to access this app
     *
     * @param string $capability WordPress capability (e.g., 'read', 'edit_posts', 'manage_options')
     */
    public function set_required_capability( $capability ) {
        $this->required_capability = $capability;
    }

    /**
     * Convert {id} shorthand syntax to WordPress-style regex patterns
     *
     * @param string $pattern URL pattern
     * @return string WordPress-style pattern
     */
    private function convert_shorthand_to_wordpress_regex( $pattern ) {
        // Convert common patterns using named capture groups:
        // {id} -> (?P<id>\d+) for numeric IDs
        // {slug} -> (?P<slug>[^/]+) for slugs
        // {user_id} -> (?P<user_id>\d+) for user IDs
        // {username}/{person} -> (?P<name>[\w\.\-]+) for usernames with dots, dashes, underscores
        // {*} -> (?P<name>[^/]+) for generic segments

        $pattern = preg_replace( '/\{(id|user_id|post_id|page_id)\}/', '(?P<$1>\\d+)', $pattern );
        $pattern = preg_replace( '/\{(username|person)\}/', '(?P<$1>[\\w\\.\\-]+)', $pattern );
        $pattern = preg_replace( '/\{(slug|name|title)\}/', '(?P<$1>[^/]+)', $pattern );
        $pattern = preg_replace( '/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern ); // Generic fallback

        return $pattern;
    }

    /**
     * Auto-discover template file based on pattern
     *
     * @param string $pattern URL pattern
     * @return string Template file path
     */
    private function auto_discover_template( $pattern ) {
        // Handle root/empty pattern
        if ( empty( $pattern ) ) {
            return 'index.php';
        }

        // Convert pattern to template name
        // 'posts' -> 'posts.php'
        // 'user/(\d+)' -> 'user.php'
        // 'posts/([^/]+)/edit' -> 'posts-edit.php'

        $template_name = $pattern;

        // Remove regex capture groups and convert to template name
        $template_name = preg_replace( '/\([^)]+\)/', '', $template_name );
        $template_name = preg_replace( '/\{[^}]+\}/', '', $template_name ); // Also handle {id} shorthand
        $template_name = trim( $template_name, '/' );
        $template_name = str_replace( '/', '-', $template_name );

        // Remove trailing dash if exists
        $template_name = rtrim( $template_name, '-' );

        // Default to index if empty after processing
        if ( empty( $template_name ) ) {
            $template_name = 'index';
        }

        return $template_name . '.php';
    }

    /**
     * Extract variable names from URL pattern
     *
     * @param string $pattern URL pattern
     * @return array Variable names
     */
    private function extract_vars_from_pattern( $pattern ) {
        preg_match_all( '/\{([^}]+)\}/', $pattern, $matches );
        return $matches[1] ?? [];
    }

    /**
     * Build WordPress-style regex for pattern matching
     *
     * @param string $pattern URL pattern with WordPress-style regex groups
     * @return string Complete regex pattern for matching
     */
    private function build_wordpress_regex( $pattern ) {
        // Escape forward slashes for regex
        $regex = str_replace( '/', '\\/', $pattern );
        return '/^' . $regex . '$/';
    }

    /**
     * Add query vars to the provided list (called by Registry)
     *
     * @param array $vars Existing query vars
     * @return array Updated query vars
     */
    public function add_query_vars_to_list( $vars ) {
        // Add all route variables as query vars so they can be accessed with get_query_var()
        foreach ( $this->routes as $route ) {
            foreach ( $route['vars'] as $var ) {
                if ( ! in_array( $var, $vars ) ) {
                    $vars[] = $var;
                }
            }
        }

        return $vars;
    }

    /**
     * Handle app request directly (called by Registry)
     *
     * @param string $request_path Request path to handle
     */
    public function handle_app_request_directly( $request_path ) {
        $switched_locale = false;

        if ( is_user_logged_in() && function_exists( 'switch_to_user_locale' ) ) {
            $switched_locale = switch_to_user_locale( get_current_user_id() );
        }

        try {
            $this->handle_app_request( $request_path );
        } finally {
            if ( $switched_locale && function_exists( 'restore_previous_locale' ) ) {
                restore_previous_locale();
            }
        }
    }


    /**
     * Handle internal app routing and 404s
     */
    private function handle_app_request( $request_path ) {
        // Check capability requirements first
        if ( ! $this->check_access_permission( $request_path ) ) {
            $this->handle_unauthorized_access( $request_path );
            return;
        }

        // Try to match the request path to our routes
        $matched_route = $this->match_route( $request_path );

        if ( $matched_route ) {
            // Check route-specific capability if set
            if ( ! $this->check_route_permission( $matched_route ) ) {
                $this->handle_unauthorized_access( $request_path );
                return;
            }

            if ( 0 === strpos( $matched_route['template'], '/' ) && file_exists( $matched_route['template'] ) ) {
                // If an absolute path is provided, use it directly
                $template_path = $matched_route['template'];
            } else {
                // Otherwise, assume it's relative to the template directory
                $template_path = $this->template_directory . '/' . $matched_route['template'];
            }

            // Security check: Ensure template exists and is within WordPress plugins directory
            $real_template_path = realpath( $template_path );
            $wp_content_dir     = realpath( WP_CONTENT_DIR );

            if ( ! $real_template_path ) {
                // Template file doesn't exist - this will be handled by the file_exists check below
            } elseif ( strpos( $real_template_path, $wp_content_dir ) !== 0 ) {
                // Template is outside WordPress content directory - potential security risk
                error_log( 'WP-App Security Warning: Template path outside WordPress content directory: ' . $matched_route['template'] );
                $this->handle_app_404(
                    $request_path,
                    'security_violation',
                    [
						'attempted_template' => $matched_route['template'],
						'reason'             => 'Template must be within WordPress content directory',
					]
                );
                return;
            }

            if ( file_exists( $template_path ) ) {
                $this->render_app_template( $template_path, $matched_route );
                return;
            }

            // Route found but template missing
            $this->handle_app_404(
                $request_path,
                'template_missing',
                [
					'matched_route' => $matched_route,
					'template_path' => $template_path,
				]
            );
            return;
        }

        // No route matched
        $this->handle_app_404( $request_path, 'route_not_found' );
    }

    /**
     * Match request path against our routes
     */
    private function match_route( $request_path ) {
        $request_path = trim( $request_path, '/' );

        foreach ( $this->routes as $route ) {
            if ( preg_match( $route['regex'], $request_path, $matches ) ) {
                // Extract named capture groups
                $params = [];
                global $wp_query;

                // Extract named matches from the regex result
                foreach ( $route['vars'] as $var_name ) {
                    if ( isset( $matches[ $var_name ] ) ) {
                        $params[ $var_name ] = $matches[ $var_name ];

                        // Set as WordPress query var so it can be accessed with get_query_var()
                        if ( $wp_query ) {
                            $wp_query->set( $var_name, $matches[ $var_name ] );
                        }
                    }
                }

                return array_merge( $route, [ 'params' => $params ] );
            }
        }

        return null;
    }

    /**
     * Handle app 404 errors
     */
    private function handle_app_404( $request_path, $error_type = 'route_not_found', $additional_data = [] ) {
        // Set 404 status
        status_header( 404 );

        // Look for a custom 404 template first
        $custom_404_template = $this->template_directory . '/404.php';

        if ( file_exists( $custom_404_template ) ) {
            $template_to_use = $custom_404_template;
        } else {
            // Use default vendor 404 template
            $template_to_use = __DIR__ . '/templates/404.php';
        }

        $this->render_app_template(
            $template_to_use,
            [
				'pattern'  => '404',
				'template' => '404.php',
				'params'   => array_merge(
                    [
						'app_path'     => $this->url_path,
						'request_path' => $request_path,
						'error_type'   => $error_type,
					],
                    $additional_data
                ),
			]
        );
    }

    /**
     * Render app template without WordPress theme interference
     */
    private function render_app_template( $template_path, $route_data = [] ) {
        // Set up minimal WordPress environment
        global $wp_query, $wp, $app, $wp_app_route;

        // Make route data available in templates
        $app          = null; // App instance no longer available globally to avoid conflicts
        $wp_app_route = array_merge(
            [
                'app_path' => $this->url_path,
            ],
            $route_data
        );

        // Load only essential WordPress functionality
        do_action( 'wp_app_before_render', $template_path, $wp_app_route );

        // Include the template
        include $template_path;

        do_action( 'wp_app_after_render', $template_path, $wp_app_route );
    }

    /**
     * Get current route parameters
     */
    public function get_route_params() {
        global $wp_app_route;

        if ( isset( $wp_app_route['params'] ) ) {
            return $wp_app_route['params'];
        }

        return [];
    }

    /**
     * Check if current request is an app request for this specific router
     */
    public function is_app_request() {
        global $wp_query;

        // Make sure we have a valid query and that wp_app_request is actually set
        if ( ! $wp_query || ! isset( $wp_query->query_vars['wp_app_request'] ) || ! isset( $wp_query->query_vars['wp_app_path'] ) ) {
            return false;
        }

        $app_path = get_query_var( 'wp_app_path' );

        // Check if this request is for this specific app
        return $app_path === $this->url_path;
    }

    /**
     * Get the URL path
     */
    public function get_url_path() {
        return $this->url_path;
    }

    /**
     * Get the app path (deprecated - use get_url_path)
     */
    public function get_app_path() {
        return $this->get_url_path();
    }


    /**
     * Check if user has permission to access the app
     *
     * @param string $request_path Current request path
     * @return bool True if access allowed
     */
    private function check_access_permission( $request_path ) {
        // No capability required - allow access
        if ( empty( $this->required_capability ) ) {
            return true;
        }

        // Check if current user has required capability
        return current_user_can( $this->required_capability );
    }

    /**
     * Check if user has permission to access specific route
     *
     * @param array $route Matched route data
     * @return bool True if access allowed
     */
    private function check_route_permission( $route ) {
        // No route-specific capability required - allow access
        if ( empty( $route['capability'] ) ) {
            return true;
        }

        // Check if current user has required capability
        return current_user_can( $route['capability'] );
    }

    /**
     * Handle unauthorized access (redirect to login or show 403)
     *
     * @param string $request_path Current request path
     */
    private function handle_unauthorized_access( $request_path ) {
        // If user is not logged in, redirect to login
        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( home_url( '/' . $this->url_path . '/' . ltrim( $request_path, '/' ) ) );
            wp_redirect( $login_url );
            exit;
        }

        // User is logged in but doesn't have required capability - show 403
        status_header( 403 );

        // Look for a custom 403 template first
        $custom_403_template = $this->template_directory . '/403.php';

        if ( file_exists( $custom_403_template ) ) {
            $template_to_use = $custom_403_template;
        } else {
            // Use default vendor 403 template
            $template_to_use = __DIR__ . '/templates/403.php';
        }

        $this->render_app_template(
            $template_to_use,
            [
				'pattern'  => '403',
				'template' => '403.php',
				'params'   => [
					'app_path'            => $this->url_path,
					'request_path'        => $request_path,
					'required_capability' => $this->required_capability,
					'user_capabilities'   => wp_get_current_user()->allcaps ?? [],
				],
			]
        );

        exit;
    }

    /**
     * Flush rewrite rules (call after adding routes)
     */
    public function flush_rules() {
        flush_rewrite_rules();
    }
}
