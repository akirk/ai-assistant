add_action( 'plugins_loaded', function() {
    // See https://github.com/akirk/wp-app for documentation.
    $app = new \WpApp\WpApp( __DIR__ . '/templates', '{{url-path}}', [
        // Access control
        // 'require_login'      => true,
        // 'require_capability' => 'read',

        // Masterbar
        // 'show_masterbar_for_anonymous' => true,
        // 'show_wp_logo'                 => true,
        // 'show_site_name'               => true,
        // 'admin_bar_app_link'           => true,
        // 'clear_admin_bar'              => false,

        // App identity
        // $plugin_data = get_file_data( __FILE__, [ 'name' => 'Plugin Name' ] );
        // 'app_name'            => $plugin_data['name'],
        // 'app_name_textdomain' => '{{slug}}',
        // 'my_apps'             => true,
        // 'my_apps_icon'        => null,
    ] );
    $app->init();
} );

register_activation_hook( __FILE__, 'flush_rewrite_rules' );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
