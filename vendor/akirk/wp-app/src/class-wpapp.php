<?php

namespace WpApp;

if ( class_exists( 'WpApp\WpApp' ) ) {
    return;
}

/**
 * Main WpApp class that coordinates all components
 */
class WpApp {
    private $router;
    private $masterbar;
    private $template_directory;
    private $initialized         = false;
    private $required_capability = null;
    private $custom_roles        = [];
    private $app_name            = null;
    private $app_name_textdomain = null;
    private $my_apps             = true;
    private $my_apps_icon        = null;

    public function __construct( $template_directory = '', $url_path = 'app', $config = [] ) {
        // Handle legacy parameter style
        if ( is_array( $url_path ) ) {
            $config   = $url_path;
            $url_path = $config['url_path'] ?? 'app';
        }

        $this->template_directory = $template_directory;
        $this->router             = new Router( $template_directory, $url_path );
        $this->masterbar          = new Masterbar( $url_path, $this );

        // Apply configuration
        $this->apply_config( $config );

        // Ensure functions are loaded
        $this->load_functions();
    }

    /**
     * Apply configuration array
     */
    private function apply_config( $config ) {

        // Masterbar configuration
        if ( isset( $config['show_masterbar_for_anonymous'] ) ) {
            $this->masterbar->show_for_anonymous( $config['show_masterbar_for_anonymous'] );
        }

        if ( isset( $config['show_wp_logo'] ) ) {
            $this->masterbar->show_wp_logo( $config['show_wp_logo'] );
        }

        if ( isset( $config['show_site_name'] ) ) {
            $this->masterbar->show_site_name( $config['show_site_name'] );
        }

        if ( isset( $config['admin_bar_app_link'] ) ) {
            $this->masterbar->admin_bar_app_link( $config['admin_bar_app_link'] );
        }

        // Access control configuration
        if ( isset( $config['require_capability'] ) ) {
            $this->require_capability( $config['require_capability'] );
        }

        if ( isset( $config['minimal_capability'] ) ) {
            $this->require_capability( $config['minimal_capability'] );
        }

        if ( isset( $config['require_login'] ) && $config['require_login'] ) {
            $this->require_capability( 'read' );
        }

        // Clear admin bar if requested
        if ( isset( $config['clear_admin_bar'] ) && $config['clear_admin_bar'] ) {
            $this->clear_admin_bar();
        }

        // Custom app name
        if ( isset( $config['app_name'] ) ) {
            $this->app_name = $config['app_name'];
        }

        if ( isset( $config['app_name_textdomain'] ) ) {
            $this->app_name_textdomain = $config['app_name_textdomain'];
        }

        // My Apps plugin integration
        if ( isset( $config['my_apps'] ) ) {
            $this->my_apps = $config['my_apps'];
        }

        if ( isset( $config['my_apps_icon'] ) ) {
            $this->my_apps_icon = $config['my_apps_icon'];
        }
    }

    /**
     * Load WpApp functions if they don't exist
     */
    private function load_functions() {
        if ( ! function_exists( 'wp_app_head' ) ) {
            require_once __DIR__ . '/functions.php';
        }

        // Don't load polyfills here - they will be loaded later if needed
        // This prevents them from overriding WordPress functions that load after this
    }

    /**
     * Initialize the WpApp (call this in your plugin/theme activation)
     */
    public function init() {
        if ( $this->initialized ) {
            return;
        }

        // Disable emoji to img conversion (renders huge SVGs otherwise)
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );

        // Set up defaults automatically
        $this->setup_defaults();

        // Enable masterbar by default
        $this->masterbar->auto_render();

        // Hook into WordPress activation
        add_action( 'wp_loaded', [ $this, 'on_wp_loaded' ] );

        // Register with My Apps plugin if enabled
        if ( $this->my_apps !== false ) {
            add_filter( 'my_apps_plugins', [ $this, 'register_my_apps' ] );
        }

        \WpApp\Registry::register_app_metadata(
            $this->router->get_app_path(),
            [
                'name'     => is_string( $this->my_apps ) ? $this->my_apps : $this->get_app_name(),
                'url'      => home_url( '/' . $this->router->get_app_path() . '/' ),
                'icon_url' => $this->my_apps_icon,
            ]
        );

        $this->initialized = true;

        do_action( 'wp_app_initialized', $this );
    }

    /**
     * Set up sensible defaults automatically
     */
    private function setup_defaults() {
        // Always create a route for the app home page
        $this->route( '' ); // -> index.php

        // Note: Main app menu item is now automatically added by masterbar
    }

    /**
     * Get a friendly name for the app based on the path
     */
    public function get_app_name() {
        if ( $this->app_name !== null ) {
            if ( $this->app_name_textdomain && function_exists( 'translate' ) ) {
                // phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction, WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain -- App names can come from plugin headers and are translated lazily using the configured plugin textdomain.
                return translate( $this->app_name, $this->app_name_textdomain );
            }

            return $this->app_name;
        }

        $app_path = $this->router->get_app_path();

        // Convert app-path to "App Path"
        $name = str_replace( [ '-', '_' ], ' ', $app_path );
        $name = ucwords( $name );

        return $name;
    }

    /**
     * Called when WordPress is fully loaded
     */
    public function on_wp_loaded() {
        // Check for manual flush parameter
        if ( isset( $_GET['wp_app_flush'] ) && current_user_can( 'manage_options' ) ) {
            $this->router->flush_rules();

            // Show success message
            add_action(
                'wp_head',
                function () {
					echo '<script>console.log("WpApp: Rewrite rules flushed successfully!");</script>';
				}
            );

            // Redirect to clean URL
            wp_redirect( remove_query_arg( 'wp_app_flush' ) );
            exit;
        }

        // Flush rewrite rules if needed
        if ( get_option( 'wp_app_flush_rewrite_rules', false ) ) {
            global $wp_rewrite;
            $url_path = $this->router->get_url_path();
            $expected = '^' . preg_quote( $url_path, '/' ) . '/?$';
            if ( ! isset( $wp_rewrite->extra_rules_top[ $expected ] ) ) {
                // Registry::add_all_rewrite_rules hasn't run yet — defer flush to next request.
                return;
            }
            $this->router->flush_rules();
            delete_option( 'wp_app_flush_rewrite_rules' );
        }
    }

    /**
     * Register this app with the My Apps plugin
     *
     * Uses the 'my_apps_plugins' filter provided by the My Apps plugin.
     *
     * @see https://wordpress.org/plugins/my-apps/
     * @param array $apps Existing apps array
     * @return array Modified apps array
     */
    public function register_my_apps( $apps ) {
        $app_path = $this->router->get_app_path();

        $name = is_string( $this->my_apps ) ? $this->my_apps : $this->get_app_name();

        $apps[ $app_path ] = [
            'name'     => $name,
            'url'      => home_url( '/' . $app_path . '/' ),
            'icon_url' => $this->my_apps_icon,
        ];

        return $apps;
    }

    /**
     * Get the router instance
     */
    public function router() {
        return $this->router;
    }

    /**
     * Get the router instance (alias for router())
     */
    public function get_router() {
        return $this->router;
    }

    /**
     * Get the masterbar instance
     */
    public function masterbar() {
        return $this->masterbar;
    }

    /**
     * Add a route (convenience method)
     */
    public function route( $pattern, $template = '', $vars = [], $capability = null ) {
        $this->router->add_route( $pattern, $template, $vars, $capability );

        // Schedule rewrite rules flush
        update_option( 'wp_app_flush_rewrite_rules', true );
    }

    /**
     * Set the minimum capability required to access this app
     *
     * @param string $capability WordPress capability (e.g., 'read', 'edit_posts', 'manage_options')
     */
    public function require_capability( $capability ) {
        $this->required_capability = $capability;
        $this->router->set_required_capability( $capability );

        // Register capability with global registry
        $url_path = $this->router->get_url_path();
        \WpApp\Registry::register_app_capability( $url_path, $capability );
    }

    /**
     * Get the required capability for this app
     *
     * @return string|null Required capability or null if none set
     */
    public function get_required_capability() {
        return $this->required_capability;
    }

    /**
     * Register a custom role for this app
     *
     * @param string $role_key Role key (e.g., 'app_user', 'app_moderator')
     * @param string $display_name Human-readable role name
     * @param array $capabilities Array of capabilities for this role
     */
    public function add_role( $role_key, $display_name, $capabilities = [] ) {
        // Prefix role key with app name to avoid conflicts
        $prefixed_role_key = $this->get_prefixed_role_key( $role_key );

        // Store role information
        $this->custom_roles[ $role_key ] = [
            'key'          => $prefixed_role_key,
            'display_name' => $display_name,
            'capabilities' => $capabilities,
            'original_key' => $role_key,
        ];

        // Register the role with WordPress
        add_role( $prefixed_role_key, $display_name, $capabilities );

        // Add to user profile if we're in admin
        add_action( 'init', [ $this, 'setup_user_profile_integration' ] );
    }

    /**
     * Get prefixed role key to avoid conflicts
     *
     * @param string $role_key Original role key
     * @return string Prefixed role key
     */
    private function get_prefixed_role_key( $role_key ) {
        $app_slug = str_replace( [ '/', '-', ' ' ], '_', $this->router->get_url_path() );
        return $app_slug . '_' . $role_key;
    }

    /**
     * Set up user profile integration for custom roles
     */
    public function setup_user_profile_integration() {
        if ( ! is_admin() || empty( $this->custom_roles ) ) {
            return;
        }

        // Hook into user profile display
        add_action( 'show_user_profile', [ $this, 'display_custom_roles_in_profile' ] );
        add_action( 'edit_user_profile', [ $this, 'display_custom_roles_in_profile' ] );

        // Hook into user profile saving
        add_action( 'personal_options_update', [ $this, 'save_custom_roles_from_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_custom_roles_from_profile' ] );
    }

    /**
     * Display custom roles in user profile
     *
     * @param WP_User $user User object
     */
    public function display_custom_roles_in_profile( $user ) {
        if ( ! current_user_can( 'edit_users' ) || empty( $this->custom_roles ) ) {
            return;
        }

        $app_name = $this->get_app_name();
        ?>
        <h3><?php echo esc_html( $app_name . ' Roles' ); ?></h3>
        <table class="form-table">
            <tr>
                <th><label><?php echo esc_html( $app_name . ' Access' ); ?></label></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><span><?php echo esc_html( $app_name . ' roles' ); ?></span></legend>
                        <?php foreach ( $this->custom_roles as $role_data ) : ?>
                            <label>
                                <input type="checkbox"
                                        name="wp_app_roles[]"
                                        value="<?php echo esc_attr( $role_data['key'] ); ?>"
                                        <?php checked( in_array( $role_data['key'], $user->roles ) ); ?> />
                                <?php echo esc_html( $role_data['display_name'] ); ?>
                                <?php if ( ! empty( $role_data['capabilities'] ) ) : ?>
                                    <span class="description">(<?php echo esc_html( implode( ', ', array_keys( $role_data['capabilities'] ) ) ); ?>)</span>
                                <?php endif; ?>
                            </label><br>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php printf( 'Grant access to %s features by selecting appropriate roles.', esc_html( $app_name ) ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save custom roles from user profile
     *
     * @param int $user_id User ID being updated
     */
    public function save_custom_roles_from_profile( $user_id ) {
        if ( ! current_user_can( 'edit_users' ) || empty( $this->custom_roles ) ) {
            return;
        }

        $user           = new WP_User( $user_id );
        $selected_roles = isset( $_POST['wp_app_roles'] ) ? array_map( 'sanitize_text_field', $_POST['wp_app_roles'] ) : [];

        // Remove existing app roles
        foreach ( $this->custom_roles as $role_data ) {
            $user->remove_role( $role_data['key'] );
        }

        // Add selected roles
        foreach ( $selected_roles as $role_key ) {
            if ( $this->is_valid_app_role( $role_key ) ) {
                $user->add_role( $role_key );
            }
        }
    }

    /**
     * Check if a role key is a valid app role
     *
     * @param string $role_key Role key to validate
     * @return bool True if valid app role
     */
    private function is_valid_app_role( $role_key ) {
        foreach ( $this->custom_roles as $role_data ) {
            if ( $role_data['key'] === $role_key ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add masterbar menu item as submenu (convenience method)
     */
    public function add_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->masterbar->add_menu_item( $id, $title, $href, $args );
    }

    /**
     * Add top-level masterbar menu item (convenience method)
     */
    public function add_top_level_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->masterbar->add_top_level_menu_item( $id, $title, $href, $args );
    }

    /**
     * Add masterbar user menu item (convenience method)
     */
    public function add_user_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->masterbar->add_user_menu_item( $id, $title, $href, $args );
    }

    /**
     * Get current route parameters (convenience method)
     */
    public function get_route_params() {
        return $this->router->get_route_params();
    }

    /**
     * Get a route parameter value (WordPress-style)
     *
     * @param string $var Parameter name
     * @param mixed $default Default value if parameter doesn't exist
     * @return mixed Parameter value
     */
    public function get_route_var( $var, $default = '' ) {
        return get_query_var( $var, $default );
    }

    /**
     * Check if we're on an app request
     */
    public function is_app_request() {
        return $this->router->is_app_request();
    }

    /**
     * Set template directory
     */
    public function set_template_directory( $directory ) {
        $this->template_directory = $directory;
        $this->router             = new Router( $directory );
    }

    /**
     * Enable/disable masterbar
     */
    public function enable_masterbar( $enable = true ) {
        if ( $enable ) {
            $this->masterbar->auto_render();
        } else {
            remove_action( 'wp_app_body_open', [ $this->masterbar, 'echo_render' ] );
        }
    }

    /**
     * Configure which WordPress admin bar items to remove on app pages
     *
     * @param array $items_to_remove Array of admin bar item IDs to remove
     */
    public function remove_admin_bar_items( $items_to_remove ) {
        $this->masterbar->set_removed_admin_bar_items( $items_to_remove );
    }

    /**
     * Remove all WordPress admin bar items and show only app items
     */
    public function clear_admin_bar() {
        $this->masterbar->remove_all_wp_admin_bar_items();
    }

    /**
     * Clear all app menu items
     */
    public function clear_menu_items() {
        $this->masterbar->clear_all_menu_items();
    }

    /**
     * Set whether to show masterbar for anonymous/logged-out users
     *
     * @param bool $show True to show, false to hide for logged-out users
     */
    public function show_masterbar_for_anonymous( $show = true ) {
        $this->masterbar->show_for_anonymous( $show );
    }

    /**
     * Set whether to add the main app link to the admin bar
     *
     * Disable this if you already have a CPT or other mechanism that adds
     * its own admin bar entry and you don't want wp-app to add a duplicate.
     *
     * @param bool $add True to add, false to skip
     */
    public function admin_bar_app_link( $add = true ) {
        $this->masterbar->admin_bar_app_link( $add );
    }


    /**
     * Get app-specific configuration
     */
    public function get_config( $key = null, $default = null ) {
        $option_name = 'wp_app_config_' . str_replace( [ '/', '-' ], '_', $this->router->get_url_path() );
        $config      = get_option( $option_name, [] );

        if ( $key === null ) {
            return $config;
        }

        return isset( $config[ $key ] ) ? $config[ $key ] : $default;
    }

    /**
     * Set app-specific configuration
     */
    public function set_config( $key, $value = null ) {
        $option_name = 'wp_app_config_' . str_replace( [ '/', '-' ], '_', $this->router->get_url_path() );
        $config      = get_option( $option_name, [] );

        if ( is_array( $key ) ) {
            $config = array_merge( $config, $key );
        } else {
            $config[ $key ] = $value;
        }

        update_option( $option_name, $config );
    }
}
