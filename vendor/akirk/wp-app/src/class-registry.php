<?php

namespace WpApp;

// Load the rest of this copy of wp-app from here rather than trusting the
// Composer files autoload. Several plugins can bundle wp-app; the first
// autoloader to run wins, and its generated file list may predate files
// added in a newer version (a vendor copy updated without dump-autoload,
// or an older plugin whose list is simply shorter). Every file guards its
// own declarations, so this is idempotent and keeps all classes and
// functions coming from the same copy.
//
// This runs before the class guard below on purpose: PHP declares this
// file's class at compile time, so the guard is already true for the
// first copy loaded and would skip the loader.
//
// Composer's files autoload will try to `require` these same files again
// right after this one, and a second compile of a file whose class is
// already declared is a fatal error under opcache (the class_exists guard
// never runs). Marking each file as loaded in Composer's registry, keyed
// the way Composer keys it (md5 of "package:path"), makes it skip them.
foreach ( [
    'class-openstation.php',
    'class-settings.php',
    'class-pwa.php',
    'class-router.php',
    'class-masterbar.php',
    'class-wpapp.php',
    'class-client-encrypted-fields.php',
    'BaseStorage.php',
    'abstract-baseapp.php',
    'functions.php',
    'rest/class-access.php',
] as $wp_app_file ) {
    $GLOBALS['__composer_autoload_files'][ md5( 'akirk/wp-app:src/' . $wp_app_file ) ] = true;
    require_once __DIR__ . '/' . $wp_app_file;
}
unset( $wp_app_file );

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
    private static $app_metadata      = [];

    /**
     * Register a WpApp instance
     *
     * @param Router $router Router instance
     */
    public static function register_app( $router ) {
        $url_path                = $router->get_url_path();
        self::$apps[ $url_path ] = $router;

        self::register_app_metadata(
            $url_path,
            [
                'name'     => ucwords( str_replace( [ '-', '_' ], ' ', $url_path ) ),
                'url'      => function_exists( 'home_url' ) ? home_url( '/' . $url_path . '/' ) : '/' . $url_path . '/',
                'icon_url' => null,
                'dashicon' => null,
            ]
        );
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

        if ( class_exists( __NAMESPACE__ . '\Settings' ) ) {
            Settings::init();
        }

        Openstation::init();

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

        if ( class_exists( __NAMESPACE__ . '\Pwa' ) ) {
            Pwa::add_rewrite_rules_for_app( $url_path );
        }
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

        if ( class_exists( __NAMESPACE__ . '\Pwa' ) && Pwa::maybe_handle_app_request( $app_path, $request_path ) ) {
            exit;
        }

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
     * Register display metadata for an app.
     *
     * @param string $url_path URL path for the app.
     * @param array  $metadata App metadata.
     */
    public static function register_app_metadata( $url_path, $metadata ) {
        $existing = isset( self::$app_metadata[ $url_path ] ) ? self::$app_metadata[ $url_path ] : [];

        self::$app_metadata[ $url_path ] = array_merge(
            [
                'name'     => ucwords( str_replace( [ '-', '_' ], ' ', $url_path ) ),
                'url'      => function_exists( 'home_url' ) ? home_url( '/' . $url_path . '/' ) : '/' . $url_path . '/',
                'icon_url' => null,
                'dashicon' => null,
            ],
            $existing,
            $metadata
        );
    }

    /**
     * Get metadata for all registered apps.
     *
     * @return array Array of app metadata keyed by URL path.
     */
    public static function get_app_metadata() {
        return self::$app_metadata;
    }

    /**
     * Get all app capabilities
     *
     * @return array Array of app capabilities keyed by URL path
     */
    public static function get_app_capabilities() {
        return self::$app_capabilities;
    }

    /**
     * Clear all registered apps and registry state.
     */
    public static function reset() {
        self::$apps              = [];
        self::$hooks_initialized = false;
        self::$app_capabilities  = [];
        self::$app_metadata      = [];

        if ( class_exists( __NAMESPACE__ . '\Openstation' ) ) {
            Openstation::reset();
        }
    }
}
