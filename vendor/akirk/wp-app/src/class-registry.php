<?php

namespace WpApp;

if ( class_exists( 'WpApp\Registry' ) ) {
    return;
}

/**
 * Global registry for managing multiple WpApp instances
 */
class Registry {
    private static $apps              = [];
    private static $hooks_initialized = false;
    private static $app_capabilities  = [];

    /**
     * Register a WpApp instance
     *
     * @param Router $router Router instance
     */
    public static function register_app( $router ) {
        $url_path                = $router->get_url_path();
        self::$apps[ $url_path ] = $router;
        self::maybe_initialize_hooks();

        // If init already fired, register this app's rewrite rules immediately
        // since add_all_rewrite_rules won't run again.
        if ( self::$hooks_initialized && ( did_action( 'init' ) || doing_action( 'init' ) ) ) {
            self::add_rewrite_rules_for_app( $url_path );
        }
    }

    /**
     * Register app capability requirement
     *
     * @param string $url_path URL path for the app
     * @param string|null $capability Required capability
     */
    public static function register_app_capability( $url_path, $capability ) {
        self::$app_capabilities[ $url_path ] = $capability;
    }

    /**
     * Check if user can access specific app
     *
     * @param string $url_path URL path for the app
     * @return bool True if user can access
     */
    public static function can_user_access_app( $url_path ) {
        if ( ! isset( self::$app_capabilities[ $url_path ] ) ) {
            return true; // No capability required
        }

        $capability = self::$app_capabilities[ $url_path ];
        if ( ! $capability ) {
            return true; // No capability required
        }

        return current_user_can( $capability );
    }

    /**
     * Initialize WordPress hooks once for all apps
     */
    private static function maybe_initialize_hooks() {
        if ( self::$hooks_initialized ) {
            return;
        }

        // If init has already fired (or is currently firing), adding an init
        // hook won't work — the action iterator has already passed priority 10.
        // Register rewrite rules directly in that case.
        if ( did_action( 'init' ) || doing_action( 'init' ) ) {
            self::add_all_rewrite_rules();
        } else {
            add_action( 'init', [ __CLASS__, 'add_all_rewrite_rules' ], 99 );
        }

        add_filter( 'template_include', [ __CLASS__, 'handle_template_include' ] );
        add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );

        self::$hooks_initialized = true;
    }

    /**
     * Add rewrite rules for all registered apps
     */
    public static function add_all_rewrite_rules() {
        foreach ( self::$apps as $url_path => $router ) {
            self::add_rewrite_rules_for_app( $url_path );
        }
    }

    /**
     * Add rewrite rules for a specific app
     *
     * @param string $url_path App URL path
     */
    private static function add_rewrite_rules_for_app( $url_path ) {
        $escaped_url_path = preg_quote( $url_path, '/' );

        // Root rewrite rule
        $root_rewrite_rule = '^' . $escaped_url_path . '/?$';
        $root_query_string = 'index.php?wp_app_request=&wp_app_path=' . $url_path;

        // Sub rewrite rule
        $sub_rewrite_rule = '^' . $escaped_url_path . '/([^.]+)/?$';
        $sub_query_string = 'index.php?wp_app_request=$matches[1]&wp_app_path=' . $url_path;

        add_rewrite_rule( $root_rewrite_rule, $root_query_string, 'top' );
        add_rewrite_rule( $sub_rewrite_rule, $sub_query_string, 'top' );
    }

    /**
     * Add query variables for all apps
     */
    public static function add_query_vars( $vars ) {
        $vars[] = 'wp_app_request';
        $vars[] = 'wp_app_path';

        // Add all route variables from all apps
        foreach ( self::$apps as $router ) {
            $vars = $router->add_query_vars_to_list( $vars );
        }

        return $vars;
    }

    /**
     * Handle template inclusion for any app request
     */
    public static function handle_template_include( $template ) {
        global $wp_query;

        // Check if this is an app request
        if ( ! $wp_query || ! isset( $wp_query->query_vars['wp_app_request'] ) || ! isset( $wp_query->query_vars['wp_app_path'] ) ) {
            return $template;
        }

        $app_path     = get_query_var( 'wp_app_path' );
        $request_path = get_query_var( 'wp_app_request' );

        // Find the router for this app path
        if ( isset( self::$apps[ $app_path ] ) ) {
            $router = self::$apps[ $app_path ];
            $router->handle_app_request_directly( $request_path );
            exit;
        }

        return $template;
    }

    /**
     * Get all registered apps
     *
     * @return array Array of registered routers keyed by URL path
     */
    public static function get_apps() {
        return self::$apps;
    }

    /**
     * Get all app capabilities
     *
     * @return array Array of app capabilities keyed by URL path
     */
    public static function get_app_capabilities() {
        return self::$app_capabilities;
    }
}
