<?php

/**
 * Select which plugin's bundled copy of wp-app may load.
 *
 * Each current wp-app copy runs this file before any declarations. When an
 * active provider has been selected, copies in other plugin directories mark
 * their own Composer files as loaded and stand down. The selected copy will
 * then be the first one allowed to declare the shared classes and functions.
 */

$wp_app_package_files = [
    'class-openstation.php',
    'class-registry.php',
    'class-settings.php',
    'class-pwa.php',
    'class-router.php',
    'class-masterbar.php',
    'class-themes.php',
    'class-wpapp.php',
    'class-client-encrypted-fields.php',
    'BaseStorage.php',
    'abstract-baseapp.php',
    'functions.php',
    'rest/class-access.php',
];

$wp_app_selected_provider  = '';
$wp_app_selected_directory = '';
$wp_app_current_provider   = '';
$wp_app_provider_ready     = false;

if ( function_exists( 'get_option' ) ) {
    $wp_app_settings = get_option( 'wp_app_masterbar_settings', [] );

    if ( is_array( $wp_app_settings ) && isset( $wp_app_settings['provider'] ) && is_string( $wp_app_settings['provider'] ) ) {
        $wp_app_candidate = str_replace( '\\', '/', trim( $wp_app_settings['provider'] ) );

        if ( false === strpos( $wp_app_candidate, '..' ) && preg_match( '#^(?:[a-z0-9._-]+/)*[a-z0-9._-]+\.php$#i', $wp_app_candidate ) ) {
            $wp_app_selected_provider  = $wp_app_candidate;
            $wp_app_selected_directory = dirname( $wp_app_candidate );
        }
    }
}

if ( '' !== $wp_app_selected_provider && defined( 'WP_PLUGIN_DIR' ) ) {
    $wp_app_plugin_root = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/';
    $wp_app_package_dir = str_replace( '\\', '/', dirname( __DIR__ ) );

    if ( 0 === strpos( $wp_app_package_dir, $wp_app_plugin_root ) ) {
        $wp_app_relative_path    = substr( $wp_app_package_dir, strlen( $wp_app_plugin_root ) );
        $wp_app_path_parts       = explode( '/', $wp_app_relative_path );
        $wp_app_current_provider = (string) reset( $wp_app_path_parts );
        $wp_app_package_suffix   = implode( '/', array_slice( $wp_app_path_parts, 1 ) );
        $wp_app_provider_ready   = is_readable( $wp_app_plugin_root . $wp_app_selected_directory . '/' . $wp_app_package_suffix . '/src/bootstrap.php' );
    }

    $wp_app_active_plugins  = (array) get_option( 'active_plugins', [] );
    $wp_app_network_plugins = function_exists( 'get_site_option' ) ? (array) get_site_option( 'active_sitewide_plugins', [] ) : [];
    $wp_app_active_plugins  = array_map(
        static function ( $plugin ) {
            return str_replace( '\\', '/', (string) $plugin );
        },
        array_merge( $wp_app_active_plugins, array_keys( $wp_app_network_plugins ) )
    );

    // Ignore a stale selection when its plugin is no longer active.
    if ( $wp_app_provider_ready && in_array( $wp_app_selected_provider, $wp_app_active_plugins, true ) && '' !== $wp_app_current_provider && $wp_app_selected_directory !== $wp_app_current_provider ) {
        foreach ( $wp_app_package_files as $wp_app_file ) {
            $GLOBALS['__composer_autoload_files'][ md5( 'akirk/wp-app:src/' . $wp_app_file ) ] = true;
        }
    }
}

unset(
    $wp_app_active_plugins,
    $wp_app_candidate,
    $wp_app_current_provider,
    $wp_app_file,
    $wp_app_network_plugins,
    $wp_app_package_dir,
    $wp_app_package_files,
    $wp_app_package_suffix,
    $wp_app_path_parts,
    $wp_app_plugin_root,
    $wp_app_provider_ready,
    $wp_app_relative_path,
    $wp_app_selected_directory,
    $wp_app_selected_provider,
    $wp_app_settings
);
