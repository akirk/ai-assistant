<?php

namespace WpApp;

if ( class_exists( 'WpApp\Settings' ) ) {
    return;
}

/**
 * Admin settings for global WpApp behavior.
 */
class Settings {
    const OPTION                     = 'wp_app_masterbar_settings';
    const MINIMUM_SWITCHABLE_VERSION = '2.0.0';

    private static $hooks_initialized = false;

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        if ( self::$hooks_initialized ) {
            return;
        }

        add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_menu', [ __CLASS__, 'add_legacy_settings_page_alias' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_settings_assets' ] );

        self::$hooks_initialized = true;
    }

    /**
     * Add the wp-admin settings page.
     */
    public static function add_settings_page() {
        add_options_page(
            __( 'WP Apps' ),
            __( 'WP Apps' ),
            'manage_options',
            'wp-apps',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    /**
     * Keep the previous settings page slug working.
     */
    public static function add_legacy_settings_page_alias() {
        add_submenu_page(
            null,
            __( 'WP Apps' ),
            __( 'WP Apps' ),
            'manage_options',
            'wp-app-masterbar',
            [ __CLASS__, 'redirect_legacy_settings_page' ]
        );
    }

    /**
     * Redirect old settings page URL to the current slug.
     */
    public static function redirect_legacy_settings_page() {
        wp_safe_redirect( admin_url( 'options-general.php?page=wp-apps' ) );
        exit;
    }

    /**
     * Register the stored option.
     */
    public static function register_settings() {
        register_setting(
            'wp_app_masterbar',
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
                'default'           => self::get_default_settings(),
            ]
        );
    }

    /**
     * Enqueue assets used by the WP Apps settings screen.
     *
     * @param string $hook_suffix Current admin screen hook.
     */
    public static function enqueue_settings_assets( $hook_suffix = '' ) {
        $is_settings_page = function_exists( 'get_current_screen' ) && get_current_screen() && 'settings_page_wp-apps' === get_current_screen()->id;

        if ( ! $is_settings_page && 'settings_page_wp-apps' !== $hook_suffix ) {
            return;
        }

        if ( function_exists( 'wp_enqueue_script' ) ) {
            wp_enqueue_script( 'jquery-ui-autocomplete' );
            wp_enqueue_script( 'wp-app-settings', wp_app_get_asset_url( 'wp-app-settings.js' ), [ 'jquery-ui-autocomplete' ], WP_APP_VERSION, true );
        }

        if ( function_exists( 'wp_enqueue_style' ) ) {
            wp_enqueue_style( 'wp-app-settings', wp_app_get_asset_url( 'wp-app-settings.css' ), [], WP_APP_VERSION );
        }
    }

    /**
     * Get the default settings.
     */
    public static function get_default_settings() {
        return [
            'only_show_active_app'              => true,
            'show_inactive_apps_in_overflow'    => true,
            'sort_overflow_menu_alphabetically' => false,
            'anonymous_access'                  => 'auto',
            'provider'                          => '',
            'app_order'                         => [],
            'launcher_order'                    => [],
            'desktop_order'                     => [],
            'apps'                              => [],
        ];
    }

    /**
     * Get defaults for a single app.
     */
    public static function get_default_app_settings() {
        return [
            'title'                => '',
            'icon'                 => '',
            'icon_background'      => '',
            'icon_color'           => '',
            'show_icon'            => true,
            'generate_letter_icon' => true,
            'show_text'            => true,
            'always_show'          => self::is_only_registered_app(),
            'default_theme'        => '',
        ];
    }

    /**
     * Whether the site has exactly one registered app.
     *
     * A lone app is shown in the masterbar by default instead of being collapsed
     * into an overflow menu that would hold a single entry. Saving the settings
     * page stores the resulting value, so this only applies until an app has
     * settings of its own.
     *
     * @return bool True when exactly one app is registered.
     */
    public static function is_only_registered_app() {
        return 1 === count( self::get_registered_apps() );
    }

    /**
     * Get normalized settings.
     */
    public static function get_settings() {
        $settings = get_option( self::OPTION, [] );

        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        return array_merge( self::get_default_settings(), $settings );
    }

    /**
     * Resolve the site's login redirect behavior at request time.
     *
     * A modified login URL is a strong indicator of login hiding. Compare with
     * the stored site URL: site_url() itself can be rewritten by login hiders.
     * Ignore scheme changes such as WordPress forcing HTTPS for login.
     *
     * @return string '404' or 'redirect'.
     */
    public static function get_anonymous_access() {
        $settings = self::get_settings();
        if ( in_array( $settings['anonymous_access'], [ '404', 'redirect' ], true ) ) {
            return $settings['anonymous_access'];
        }

        return self::is_login_url_modified() ? '404' : 'redirect';
    }

    /**
     * Whether the effective login URL differs from the normal WordPress URL.
     */
    public static function is_login_url_modified() {
        $default_url = rtrim( get_option( 'siteurl', home_url() ), '/' ) . '/wp-login.php';
        $default     = wp_parse_url( $default_url );
        $actual      = wp_parse_url( wp_login_url() );
        if ( ! is_array( $actual ) || ! is_array( $default ) ) {
            return true;
        }
        unset( $default['scheme'], $actual['scheme'] );

        ksort( $default );
        ksort( $actual );

        return $default !== $actual;
    }

    /**
     * Get normalized settings for an app path.
     *
     * @param string $app_path App URL path.
     * @return array App settings.
     */
    public static function get_app_settings( $app_path ) {
        $settings = self::get_settings();
        $apps     = isset( $settings['apps'] ) && is_array( $settings['apps'] ) ? $settings['apps'] : [];
        $app      = isset( $apps[ $app_path ] ) && is_array( $apps[ $app_path ] ) ? $apps[ $app_path ] : [];

        $normalized                     = array_merge( self::get_default_app_settings(), $app );
        $normalized['show_in_launcher'] = array_key_exists( 'show_in_launcher', $app ) ? ! empty( $app['show_in_launcher'] ) : ! empty( $settings['show_inactive_apps_in_overflow'] );
        $normalized['pin_to_desktop']   = array_key_exists( 'pin_to_desktop', $app ) ? ! empty( $app['pin_to_desktop'] ) : ( ( empty( $settings['only_show_active_app'] ) || ! empty( $normalized['always_show'] ) ) && ( ! empty( $normalized['show_icon'] ) || ! empty( $normalized['show_text'] ) ) );
        return $normalized;
    }

    /**
     * Whether an app should appear outside its active app context.
     *
     * @param string $app_path App URL path.
     * @return bool True when the app should be shown globally.
     */
    public static function should_show_global_app_link( $app_path ) {
        $app_settings = self::get_app_settings( $app_path );
        return $app_settings['pin_to_desktop'];
    }

    /** Get accessible apps in the independent launcher or desktop order. */
    public static function get_navigation_apps( $placement ) {
        $apps    = self::get_registered_apps();
        $order   = self::get_navigation_order( $placement );
        $ordered = [];
        foreach ( array_merge( (array) $order, array_keys( $apps ) ) as $path ) {
            if ( ! isset( $apps[ $path ] ) || isset( $ordered[ $path ] ) || ! self::can_customize_navigation( $path ) ) {
                continue;
            }
            $app = self::get_app_settings( $path );
            if ( ! empty( $app[ 'desktop' === $placement ? 'pin_to_desktop' : 'show_in_launcher' ] ) ) {
                $ordered[ $path ] = $apps[ $path ];
            }
        }
        return $ordered;
    }

    /** Initialize independent orders from the previous shared order. */
    public static function get_navigation_order( $placement ) {
        $settings = self::get_settings();
        $apps     = self::get_registered_apps();
        $key      = 'desktop' === $placement ? 'desktop_order' : 'launcher_order';
        if ( empty( $settings[ $key ] ) && 'launcher' === $placement && ! empty( $settings['sort_overflow_menu_alphabetically'] ) ) {
            uksort(
                $apps,
                static function ( $a, $b ) use ( $apps ) {
					$a_settings = self::get_app_settings( $a );
					$b_settings = self::get_app_settings( $b );
					return strnatcasecmp( '' !== $a_settings['title'] ? $a_settings['title'] : ( $apps[ $a ]['name'] ?? $a ), '' !== $b_settings['title'] ? $b_settings['title'] : ( $apps[ $b ]['name'] ?? $b ) );
				}
            );
            return array_keys( $apps );
        }
        $order = ! empty( $settings[ $key ] ) ? $settings[ $key ] : $settings['app_order'];
        return array_values( array_intersect( array_unique( array_merge( (array) $order, array_keys( $apps ) ) ), array_keys( $apps ) ) );
    }

    /** App navigation is independent of access to the app itself. */
    public static function can_customize_navigation( $path ) {
        $masterbar = self::get_masterbar_for_app( $path );
        return Registry::can_user_access_app( $path ) && ( ! $masterbar || $masterbar->is_admin_bar_app_link_enabled() );
    }

    /**
     * Whether inactive app links should be collected under the overflow menu on app pages.
     *
     * @return bool True when inactive apps should be shown in overflow.
     */
    public static function should_show_inactive_apps_in_overflow() {
        $settings = self::get_settings();
        return ! empty( $settings['show_inactive_apps_in_overflow'] );
    }

    /**
     * Whether the overflow menu should ignore saved order and sort app links alphabetically.
     *
     * @return bool True when overflow app links should be sorted alphabetically.
     */
    public static function should_sort_overflow_menu_alphabetically() {
        $settings = self::get_settings();
        return ! empty( $settings['sort_overflow_menu_alphabetically'] );
    }

    /**
     * Get WpApp apps registered with this library.
     *
     * @return array App metadata keyed by app path.
     */
    public static function get_registered_apps() {
        $apps = class_exists( __NAMESPACE__ . '\Registry' ) ? Registry::get_app_metadata() : [];

        return self::sort_registered_apps( $apps );
    }

    /**
     * Get plugins known to bundle wp-app.
     *
     * @param array|null $apps Registered app metadata, or null to fetch it.
     * @return array<string, array{label:string}> Providers keyed by plugin basename.
     */
    public static function get_wp_app_providers( $apps = null ) {
        $apps      = is_array( $apps ) ? $apps : self::get_registered_apps();
        $providers = [];

        foreach ( $apps as $metadata ) {
            if ( empty( $metadata['wp_app_package'] ) || ! is_array( $metadata['wp_app_package'] ) ) {
                continue;
            }

            $package  = $metadata['wp_app_package'];
            $expected = isset( $package['expected'] ) ? trim( (string) $package['expected'] ) : '';
            $source   = isset( $package['expected_source'] ) ? (string) $package['expected_source'] : '';

            if ( '' !== $expected && '' !== $source ) {
                self::add_wp_app_provider( $providers, $source, self::get_bundled_wp_app_version( $source ), false );
            }

            if ( ! empty( $package['loaded'] ) && is_array( $package['loaded'] ) ) {
                $loaded_path    = isset( $package['loaded']['path'] ) ? (string) $package['loaded']['path'] : '';
                $loaded_version = isset( $package['loaded']['version'] ) ? trim( (string) $package['loaded']['version'] ) : '';

                self::add_wp_app_provider( $providers, $loaded_path, $loaded_version, true );
            }
        }

        uasort(
            $providers,
            static function ( $first, $second ) {
                return strcasecmp( $first['label'], $second['label'] );
            }
        );

        return $providers;
    }

    /**
     * Describe the wp-app copy loaded by the first plugin.
     *
     * @param array $apps Registered app metadata.
     * @return array{slug:string,version:string}
     */
    private static function get_loaded_wp_app_provider( $apps ) {
        foreach ( $apps as $metadata ) {
            if ( empty( $metadata['wp_app_package']['loaded'] ) || ! is_array( $metadata['wp_app_package']['loaded'] ) ) {
                continue;
            }

            $loaded  = $metadata['wp_app_package']['loaded'];
            $path    = isset( $loaded['path'] ) ? (string) $loaded['path'] : '';
            $version = isset( $loaded['version'] ) ? (string) $loaded['version'] : '';
            $slug    = self::get_wp_content_plugin_slug( $path );

            if ( '' === $slug ) {
                $slug = self::get_inferred_wp_app_provider_slug( $apps );
            }

            return [
                'slug'    => '' !== $slug ? $slug : self::get_symlinked_wp_app_label( $path ),
                'version' => '' !== $version ? $version : ( defined( 'WP_APP_VERSION' ) ? WP_APP_VERSION : __( 'unknown' ) ),
            ];
        }

        return [
            'slug'    => __( 'unknown source' ),
            'version' => defined( 'WP_APP_VERSION' ) ? WP_APP_VERSION : __( 'unknown' ),
        ];
    }

    /**
     * Infer the loaded provider from the selected provider or plugin load order.
     *
     * @param array $apps Registered app metadata.
     * @return string Plugin folder slug, or an empty string.
     */
    private static function get_inferred_wp_app_provider_slug( $apps ) {
        $providers = self::get_wp_app_providers( $apps );
        $settings  = self::get_settings();
        $selected  = isset( $settings['provider'] ) ? self::sanitize_provider_plugin_file( $settings['provider'] ) : '';

        if ( '' !== $selected && isset( $providers[ $selected ] ) ) {
            return self::get_wp_content_plugin_slug( self::get_plugin_root_path() . '/' . $selected );
        }

        $active_plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', [] ) : [];

        foreach ( $active_plugins as $plugin_file ) {
            $plugin_file = self::sanitize_provider_plugin_file( $plugin_file );

            if ( '' !== $plugin_file && isset( $providers[ $plugin_file ] ) ) {
                return self::get_wp_content_plugin_slug( self::get_plugin_root_path() . '/' . $plugin_file );
            }
        }

        return '';
    }

    /**
     * Describe a loaded wp-app package reached through a Composer symlink.
     *
     * @param string $path Loaded wp-app package path.
     * @return string Human-readable source label.
     */
    private static function get_symlinked_wp_app_label( $path ) {
        $path = rtrim( self::normalize_filesystem_path( $path ), '/' );
        $name = basename( $path );

        if ( '' === $name || '.' === $name ) {
            return __( 'unknown source' );
        }

        /* translators: %s: Directory name of a symlinked wp-app package. */
        return sprintf( __( '%s (symlinked)' ), $name );
    }

    /**
     * Sanitize a plugin basename relative to WP_PLUGIN_DIR.
     *
     * @param mixed $plugin_file Raw provider plugin basename.
     * @return string Sanitized plugin basename.
     */
    public static function sanitize_provider_plugin_file( $plugin_file ) {
        if ( ! is_string( $plugin_file ) ) {
            return '';
        }

        $plugin_file = str_replace( '\\', '/', trim( $plugin_file ) );

        if ( false !== strpos( $plugin_file, '..' ) || ! preg_match( '#^(?:[a-z0-9._-]+/)*[a-z0-9._-]+\.php$#i', $plugin_file ) ) {
            return '';
        }

        return $plugin_file;
    }

    /**
     * Add one provider inferred from package diagnostics.
     *
     * @param array  $providers Providers collected so far.
     * @param string $path      Path inside the provider plugin.
     * @param string $version   Version or requirement displayed in the label.
     * @param bool   $is_loaded Whether this is the currently loaded copy.
     */
    private static function add_wp_app_provider( &$providers, $path, $version, $is_loaded ) {
        if ( '' === $version || ( ! self::is_development_version( $version ) && version_compare( $version, self::MINIMUM_SWITCHABLE_VERSION, '<' ) ) ) {
            return;
        }

        $plugin      = self::get_plugin_metadata_from_path( $path );
        $plugin_file = isset( $plugin['file'] ) ? self::sanitize_provider_plugin_file( $plugin['file'] ) : '';

        if ( '' === $plugin_file ) {
            return;
        }

        $label = self::format_plugin_label( $plugin );

        if ( '' !== $version ) {
            if ( $is_loaded ) {
                /* translators: %s: The loaded wp-app package version. */
                $label .= sprintf( __( ' — wp-app %s (active)' ), $version );
            } else {
                /* translators: %s: The bundled wp-app package version. */
                $label .= sprintf( __( ' — wp-app %s' ), $version );
            }
        }

        if ( ! isset( $providers[ $plugin_file ] ) || $is_loaded ) {
            $providers[ $plugin_file ] = [ 'label' => $label ];
        }
    }

    /**
     * Whether a Composer package version identifies a development branch.
     *
     * @param string $version Composer package version.
     * @return bool True for versions such as dev-main and 2.0.x-dev.
     */
    private static function is_development_version( $version ) {
        return 1 === preg_match( '/^(?:dev-.+|.+-dev)$/i', $version );
    }

    /**
     * Detect the wp-app version installed in a plugin's Composer dependencies.
     *
     * @param string $source_path Path to the consuming plugin's composer.json.
     * @return string Installed version, or an empty string when unavailable.
     */
    private static function get_bundled_wp_app_version( $source_path ) {
        $plugin = self::get_plugin_metadata_from_path( $source_path );

        if ( empty( $plugin['slug'] ) ) {
            return '';
        }

        $plugin_root = self::get_plugin_root_path();

        if ( '' === $plugin_root ) {
            return '';
        }

        $plugin_dir = rtrim( $plugin_root, '/' ) . '/' . $plugin['slug'];

        foreach ( [ $plugin_dir . '/composer.lock', $plugin_dir . '/vendor/composer/installed.json' ] as $metadata_file ) {
            if ( ! is_readable( $metadata_file ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local Composer metadata.
            $metadata = json_decode( file_get_contents( $metadata_file ), true );

            if ( ! is_array( $metadata ) ) {
                continue;
            }

            $packages = isset( $metadata['packages'] ) && is_array( $metadata['packages'] ) ? $metadata['packages'] : $metadata;

            if ( isset( $metadata['packages-dev'] ) && is_array( $metadata['packages-dev'] ) ) {
                $packages = array_merge( $packages, $metadata['packages-dev'] );
            }

            foreach ( $packages as $package ) {
                if ( is_array( $package ) && isset( $package['name'], $package['version'] ) && 'akirk/wp-app' === $package['name'] ) {
                    return ltrim( (string) $package['version'], 'v' );
                }
            }
        }

        return '';
    }

    /**
     * Sanitize stored settings.
     *
     * @param mixed $value Raw option value.
     * @return array Sanitized settings.
     */
    public static function sanitize_settings( $value ) {
        $value = is_array( $value ) ? $value : [];
        $apps  = [];
        $order = [];

        if ( isset( $value['app_order'] ) && is_array( $value['app_order'] ) ) {
            foreach ( $value['app_order'] as $app_path ) {
                $app_path = self::sanitize_app_path( $app_path );

                if ( '' === $app_path || in_array( $app_path, $order, true ) ) {
                    continue;
                }

                $order[] = $app_path;
            }
        }

        if ( isset( $value['apps'] ) && is_array( $value['apps'] ) ) {
            foreach ( $value['apps'] as $app_path => $app_settings ) {
                $app_path = self::sanitize_app_path( $app_path );

                if ( '' === $app_path || ! is_array( $app_settings ) ) {
                    continue;
                }

                $apps[ $app_path ] = [
                    'title'                => isset( $app_settings['title'] ) ? sanitize_text_field( $app_settings['title'] ) : '',
                    'icon'                 => isset( $app_settings['icon'] ) ? sanitize_text_field( $app_settings['icon'] ) : '',
                    'icon_background'      => isset( $app_settings['icon_background'] ) ? WpApp::sanitize_icon_css_value( $app_settings['icon_background'] ) : '',
                    'icon_color'           => isset( $app_settings['icon_color'] ) ? WpApp::sanitize_icon_css_value( $app_settings['icon_color'] ) : '',
                    'show_icon'            => ! empty( $app_settings['show_icon'] ),
                    'generate_letter_icon' => ! empty( $app_settings['generate_letter_icon'] ),
                    'show_text'            => ! empty( $app_settings['show_text'] ),
                    'always_show'          => ! empty( $app_settings['always_show'] ),
                    'default_theme'        => isset( $app_settings['default_theme'] ) ? sanitize_key( $app_settings['default_theme'] ) : '',
                ];
                foreach ( [ 'show_in_launcher', 'pin_to_desktop' ] as $field ) {
                    if ( array_key_exists( $field, $app_settings ) ) {
                        $apps[ $app_path ][ $field ] = ! empty( $app_settings[ $field ] );
                    }
                }
            }
        }

        return [
            'only_show_active_app'              => ! empty( $value['only_show_active_app'] ),
            'show_inactive_apps_in_overflow'    => ! empty( $value['show_inactive_apps_in_overflow'] ),
            'sort_overflow_menu_alphabetically' => ! empty( $value['sort_overflow_menu_alphabetically'] ),
            'anonymous_access'                  => isset( $value['anonymous_access'] ) && in_array( $value['anonymous_access'], [ 'auto', '404', 'redirect' ], true ) ? $value['anonymous_access'] : 'auto',
            'provider'                          => isset( $value['provider'] ) ? self::sanitize_provider_plugin_file( $value['provider'] ) : '',
            'app_order'                         => $order,
            'launcher_order'                    => self::sanitize_navigation_order( $value['launcher_order'] ?? [] ),
            'desktop_order'                     => self::sanitize_navigation_order( $value['desktop_order'] ?? [] ),
            'apps'                              => $apps,
        ];
    }

    private static function sanitize_navigation_order( $value ) {
        $order = [];
        foreach ( is_array( $value ) ? $value : [] as $path ) {
            $path = self::sanitize_app_path( $path );
            if ( '' !== $path && ! in_array( $path, $order, true ) ) {
                $order[] = $path;
            }
        }
        return $order;
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $settings = self::get_settings();
        $apps     = self::get_registered_apps();
        $config   = [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'wp-app-admin-bar-refresh' ),
            'dashicons'     => self::get_dashicon_options(),
            'saved'         => __( 'Saved' ),
            'saving'        => __( 'Saving…' ),
            'failed'        => __( 'Save failed. Your changes are still on this page. Try Save Changes again.' ),
            'launcher'      => __( 'Launcher' ),
            'hidden'        => __( 'Hidden from launcher' ),
            'pinned'        => __( 'Pinned to desktop' ),
            'emptyLauncher' => __( 'No apps in the launcher. Enable Show in launcher in an app’s options.' ),
            'emptyDesktop'  => __( 'No pinned icons. Pin an app using the menu below.' ),
            'moveEarlier'   => __( 'Move earlier' ),
            'moveLater'     => __( 'Move later' ),
        ];
        ?>
        <div class="wrap wp-app-navigation-settings" data-wp-app-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
            <h1><?php echo esc_html__( 'WP Apps' ); ?></h1>
            <form method="post" action="options.php" data-wp-app-settings-form>
                <?php settings_fields( 'wp_app_masterbar' ); ?>
                <p class="wp-app-settings-save-status" data-wp-app-save-status role="status" aria-live="polite" hidden></p>
                <?php foreach ( [ 'only_show_active_app', 'show_inactive_apps_in_overflow', 'sort_overflow_menu_alphabetically' ] as $legacy ) : ?>
                    <input type="hidden" name="<?php echo esc_attr( self::OPTION . '[' . $legacy . ']' ); ?>" value="<?php echo empty( $settings[ $legacy ] ) ? '0' : '1'; ?>">
                <?php endforeach; ?>
                <details class="wp-app-settings-section">
                    <summary><h2><?php echo esc_html__( 'Apps' ); ?></h2></summary>
                    <p><?php echo esc_html__( 'Edit app names, icons, and visibility here or select an app in either preview. Names and icons are shared everywhere. Hiding an app changes navigation, not access to its URL.' ); ?></p>
                    <div class="wp-app-settings-apps" data-wp-app-settings-list>
                        <?php foreach ( $apps as $app_path => $metadata ) : ?>
                            <?php
                            $app_settings  = self::get_app_settings( $app_path );
                            $app_name      = isset( $metadata['name'] ) ? $metadata['name'] : $app_path;
                            $title         = '' !== trim( $app_settings['title'] ) ? trim( $app_settings['title'] ) : $app_name;
                            $can_customize = self::can_customize_navigation( $app_path );
                            $themes        = isset( $metadata['themes'] ) && is_array( $metadata['themes'] ) ? $metadata['themes'] : [];
                            ?>
                            <details class="wp-app-settings-app" data-app-path="<?php echo esc_attr( $app_path ); ?>"
                                data-default-title="<?php echo esc_attr( $app_name ); ?>"
                                data-default-icon="<?php echo esc_attr( self::get_effective_app_icon_value( [], $metadata ) ); ?>"
                                data-default-background="<?php echo esc_attr( $metadata['icon_background'] ?? '' ); ?>"
                                data-default-color="<?php echo esc_attr( $metadata['icon_color'] ?? '' ); ?>"
                                data-navigation-enabled="<?php echo $can_customize ? '1' : '0'; ?>">
                                <summary>
                                    <span class="wp-app-settings-app-icon"><?php echo Masterbar::get_app_link_icon_html_for_app( $app_path, $metadata, $title ); ?></span>
                                    <span><strong data-app-name><?php echo esc_html( $app_name ); ?></strong><small data-app-visibility><?php echo esc_html( $app_settings['show_in_launcher'] ? __( 'Launcher' ) : __( 'Hidden from launcher' ) ); ?><?php echo $app_settings['pin_to_desktop'] ? ' · ' . esc_html__( 'Pinned to desktop' ) : ''; ?></small></span>
                                </summary>
                                <div class="wp-app-settings-editor" data-app-editor>
                                    <?php if ( ! $can_customize ) : ?>
                                        <p><?php echo esc_html__( 'This app has disabled its navigation entry or is not available to your account.' ); ?></p>
                                    <?php endif; ?>
                                    <?php foreach ( [ 'show_icon', 'show_text', 'generate_letter_icon', 'always_show' ] as $legacy ) : ?>
                                        <input type="hidden" name="<?php echo esc_attr( self::get_field_name( $app_path, $legacy ) ); ?>" value="<?php echo empty( $app_settings[ $legacy ] ) ? '0' : '1'; ?>">
                                    <?php endforeach; ?>
                                    <?php
                                    $fields = [
                                        'title'           => [ __( 'Name' ), $app_settings['title'], $app_name ],
                                        'icon'            => [ __( 'Icon' ), self::get_effective_app_icon_value( $app_settings, $metadata ), __( 'e.g. dashicons-admin-site or https://example.org/icon.svg' ) ],
                                        'icon_background' => [ __( 'Icon background' ), self::get_effective_app_setting_value( $app_settings, $metadata, 'icon_background' ), __( 'e.g. #2271b1 or linear-gradient(135deg, #f7971e, #ffd200)' ) ],
                                        'icon_color'      => [ __( 'Icon color' ), self::get_effective_app_setting_value( $app_settings, $metadata, 'icon_color' ), __( 'e.g. #fff' ) ],
                                    ];
                                    ?>
                                    <?php foreach ( $fields as $field => $definition ) : ?>
                                        <p class="wp-app-settings-field">
                                            <label for="<?php echo esc_attr( self::get_field_id( $app_path, $field ) ); ?>"><?php echo esc_html( $definition[0] ); ?></label>
                                            <input class="regular-text" type="text" id="<?php echo esc_attr( self::get_field_id( $app_path, $field ) ); ?>" name="<?php echo esc_attr( self::get_field_name( $app_path, $field ) ); ?>" data-wp-app-setting="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $definition[1] ); ?>" placeholder="<?php echo esc_attr( $definition[2] ); ?>"<?php echo 'icon' === $field ? ' data-wp-app-dashicon-autocomplete="1"' : ''; ?>>
                                        </p>
                                    <?php endforeach; ?>
                                    <p class="description"><?php echo esc_html__( 'Use an image URL, emoji, letter, or Dashicon class for the icon.' ); ?></p>
                                    <div class="wp-app-settings-visibility">
                                        <?php if ( $can_customize ) : ?>
                                            <?php self::render_checkbox( $app_path, 'show_in_launcher', $app_settings['show_in_launcher'], __( 'Show in launcher' ) ); ?>
                                            <?php self::render_checkbox( $app_path, 'pin_to_desktop', $app_settings['pin_to_desktop'], __( 'Pin to desktop top bar' ) ); ?>
                                        <?php else : ?>
                                            <?php foreach ( [ 'show_in_launcher', 'pin_to_desktop' ] as $field ) : ?>
                                                <input type="hidden" name="<?php echo esc_attr( self::get_field_name( $app_path, $field ) ); ?>" value="<?php echo empty( $app_settings[ $field ] ) ? '0' : '1'; ?>">
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( count( $themes ) > 1 ) : ?>
                                        <p class="wp-app-settings-field">
                                            <label for="<?php echo esc_attr( self::get_field_id( $app_path, 'default_theme' ) ); ?>"><?php echo esc_html__( 'Default theme' ); ?></label>
                                            <select id="<?php echo esc_attr( self::get_field_id( $app_path, 'default_theme' ) ); ?>" name="<?php echo esc_attr( self::get_field_name( $app_path, 'default_theme' ) ); ?>">
                                                <?php foreach ( $themes as $slug => $theme ) : ?>
                                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $app_settings['default_theme'], $slug ); ?>><?php echo esc_html( $theme['name'] ?? $slug ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </p>
                                    <?php else : ?>
                                        <input type="hidden" name="<?php echo esc_attr( self::get_field_name( $app_path, 'default_theme' ) ); ?>" value="<?php echo esc_attr( $app_settings['default_theme'] ); ?>">
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        <?php
                        if ( empty( $apps ) ) :
							?>
                            <p><?php echo esc_html__( 'No WpApp apps are currently registered.' ); ?></p><?php endif; ?>
                    </div>
                </details>
                <details class="wp-app-settings-section" open>
                    <summary><h2><?php echo esc_html__( 'Launcher' ); ?></h2></summary>
                    <p><?php echo esc_html__( 'The same launcher appears on desktop and mobile. Select a tile to edit it. Drag tiles to reorder, or select a tile and use the move buttons. Pinned apps stay in the launcher unless you hide them here.' ); ?></p>
                    <?php self::render_navigation_preview( 'launcher' ); ?>
                </details>
                <details class="wp-app-settings-section" open>
                    <summary><h2><?php echo esc_html__( 'Desktop top bar' ); ?></h2></summary>
                    <p><?php echo esc_html__( 'Pinned apps appear as icons with their names in tooltips. Select an icon to edit or unpin it. This order is independent of the launcher.' ); ?></p>
                    <?php self::render_navigation_preview( 'desktop' ); ?>
                    <p><label for="wp-app-add-pin"><?php echo esc_html__( 'Add another app' ); ?></label>
                        <select id="wp-app-add-pin" data-add-pin><option value=""><?php echo esc_html__( 'Choose an app…' ); ?></option>
                            <?php foreach ( $apps as $path => $metadata ) : ?>
                                <?php
                                if ( ! self::can_customize_navigation( $path ) ) {
									continue; }
								?>
                                <option value="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $metadata['name'] ?? $path ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </details>
                <dialog class="wp-app-settings-popover" aria-labelledby="wp-app-editor-heading" data-app-dialog>
                    <div class="wp-app-settings-popover-header"><h2 id="wp-app-editor-heading"></h2><button type="button" class="button" data-close-editor><?php echo esc_html__( 'Done' ); ?></button></div>
                    <p data-editor-status role="status" aria-live="polite" hidden></p>
                    <div data-editor-slot></div>
                    <div class="wp-app-settings-move-controls">
                        <button type="button" class="button" data-move="-1"><?php echo esc_html__( 'Move earlier' ); ?></button>
                        <button type="button" class="button" data-move="1"><?php echo esc_html__( 'Move later' ); ?></button>
                    </div>
                </dialog>
                <table class="form-table" role="presentation">
                    <?php if ( self::is_login_url_modified() || in_array( $settings['anonymous_access'], [ '404', 'redirect' ], true ) ) : ?>
                    <tr>
                        <th scope="row"><label for="wp-app-anonymous-access"><?php echo esc_html__( 'Login redirects' ); ?></label></th>
                        <td>
                            <select id="wp-app-anonymous-access" name="<?php echo esc_attr( self::OPTION ); ?>[anonymous_access]" aria-describedby="wp-app-anonymous-access-description">
                                <option value="auto" <?php selected( $settings['anonymous_access'], 'auto' ); ?>><?php echo esc_html__( 'Automatic (404 when the login URL is modified)' ); ?></option>
                                <option value="404" <?php selected( $settings['anonymous_access'], '404' ); ?>><?php echo esc_html__( 'Return 404' ); ?></option>
                                <option value="redirect" <?php selected( $settings['anonymous_access'], 'redirect' ); ?>><?php echo esc_html__( 'Redirect to login' ); ?></option>
                            </select>
                            <p class="description" id="wp-app-anonymous-access-description"><?php echo esc_html__( 'Applies to all WP Apps on this site. Automatic treats a modified login URL as an indication that it should stay hidden. Choose Redirect to login to allow redirects to a custom login or SSO page. With 404 responses, visitors must sign in before opening an app. Public apps remain public.' ); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php $providers = self::get_wp_app_providers( $apps ); ?>
                    <?php $loaded_provider = self::get_loaded_wp_app_provider( $apps ); ?>
                    <tr>
                        <th scope="row">
                            <label for="wp-app-provider"><?php echo esc_html__( 'WP Apps library' ); ?></label>
                        </th>
                        <td>
                            <span><?php echo esc_html__( 'Load from' ); ?></span>
                            <select id="wp-app-provider" name="<?php echo esc_attr( self::OPTION ); ?>[provider]">
                                <option value=""><?php echo esc_html( sprintf( /* translators: 1: Plugin folder slug. 2: Loaded wp-app version. */ __( 'First plugin (%1$s, wp-app %2$s)' ), $loaded_provider['slug'], $loaded_provider['version'] ) ); ?></option>
                                <?php foreach ( $providers as $slug => $provider ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['provider'], $slug ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( count( $providers ) > 1 ) : ?>
                                <p class="description"><?php echo esc_html__( 'Choose which active plugin supplies the shared wp-app framework. The change takes effect on the next request.' ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>


                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private static function render_navigation_preview( $placement ) {
        $order_key = 'desktop' === $placement ? 'desktop_order' : 'launcher_order';
        $order     = self::get_navigation_order( $placement );
        ?>
        <div data-order-inputs="<?php echo esc_attr( $placement ); ?>">
            <?php foreach ( $order as $path ) : ?>
                <input type="hidden" name="<?php echo esc_attr( self::OPTION . '[' . $order_key . '][]' ); ?>" value="<?php echo esc_attr( $path ); ?>">
            <?php endforeach; ?>
        </div>
        <div class="wp-app-settings-navigation-preview wp-app-settings-preview-<?php echo esc_attr( $placement ); ?>" data-navigation-preview="<?php echo esc_attr( $placement ); ?>">
            <?php foreach ( self::get_navigation_apps( $placement ) as $path => $metadata ) : ?>
                <?php
                $app  = self::get_app_settings( $path );
                $name = '' !== trim( $app['title'] ) ? trim( $app['title'] ) : ( $metadata['name'] ?? $path );
                ?>
                <button type="button" class="wp-app-settings-tile" data-edit-app="<?php echo esc_attr( $path ); ?>" title="<?php echo esc_attr( $name ); ?>" aria-label="<?php echo esc_attr( $name ); ?>" draggable="true">
                    <?php echo Masterbar::get_app_link_icon_html_for_app( $path, $metadata, $name ); ?>
                    <?php
                    if ( 'launcher' === $placement ) :
						?>
                        <span><?php echo esc_html( $name ); ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="description" data-empty-preview="<?php echo esc_attr( $placement ); ?>" hidden></p>
        <?php
    }

    /**
     * Render a checkbox field for one app setting.
     */
    private static function render_checkbox( $app_path, $field, $checked, $label ) {
        $name = self::get_field_name( $app_path, $field );
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" data-wp-app-setting="<?php echo esc_attr( $field ); ?>" <?php checked( $checked ); ?>>
            <?php echo esc_html( $label ); ?>
        </label>
        <?php
    }

    /**
     * Preserve stored app settings for informational cards.
     */
    private static function render_hidden_app_settings( $app_path, $app_settings ) {
        foreach ( self::get_default_app_settings() as $field => $default ) {
            $value = isset( $app_settings[ $field ] ) ? $app_settings[ $field ] : $default;

            if ( is_bool( $default ) ) {
                $value = ! empty( $value ) ? '1' : '0';
            }
            ?>
            <input type="hidden" name="<?php echo esc_attr( self::get_field_name( $app_path, $field ) ); ?>" value="<?php echo esc_attr( $value ); ?>">
            <?php
        }
    }

    /**
     * Get reusable Dashicon suggestions for icon fields.
     */
    private static function get_dashicon_options() {
        return [
            'dashicons-admin-appearance',
            'dashicons-admin-comments',
            'dashicons-admin-generic',
            'dashicons-admin-home',
            'dashicons-admin-links',
            'dashicons-admin-media',
            'dashicons-admin-network',
            'dashicons-admin-page',
            'dashicons-admin-plugins',
            'dashicons-admin-post',
            'dashicons-admin-settings',
            'dashicons-admin-site',
            'dashicons-admin-tools',
            'dashicons-admin-users',
            'dashicons-airplane',
            'dashicons-analytics',
            'dashicons-archive',
            'dashicons-art',
            'dashicons-awards',
            'dashicons-bank',
            'dashicons-book',
            'dashicons-businessman',
            'dashicons-businessperson',
            'dashicons-calendar',
            'dashicons-camera',
            'dashicons-car',
            'dashicons-cart',
            'dashicons-category',
            'dashicons-chart-area',
            'dashicons-chart-bar',
            'dashicons-chart-line',
            'dashicons-chart-pie',
            'dashicons-clipboard',
            'dashicons-clock',
            'dashicons-cloud',
            'dashicons-coffee',
            'dashicons-controls-play',
            'dashicons-dashboard',
            'dashicons-database',
            'dashicons-desktop',
            'dashicons-editor-code',
            'dashicons-editor-help',
            'dashicons-editor-ol',
            'dashicons-editor-spellcheck',
            'dashicons-editor-table',
            'dashicons-editor-ul',
            'dashicons-email',
            'dashicons-excerpt-view',
            'dashicons-feedback',
            'dashicons-filter',
            'dashicons-food',
            'dashicons-format-aside',
            'dashicons-format-audio',
            'dashicons-format-chat',
            'dashicons-format-gallery',
            'dashicons-format-image',
            'dashicons-format-quote',
            'dashicons-format-status',
            'dashicons-format-video',
            'dashicons-forms',
            'dashicons-games',
            'dashicons-groups',
            'dashicons-hammer',
            'dashicons-heart',
            'dashicons-id',
            'dashicons-images-alt2',
            'dashicons-index-card',
            'dashicons-info',
            'dashicons-laptop',
            'dashicons-lightbulb',
            'dashicons-location',
            'dashicons-location-alt',
            'dashicons-lock',
            'dashicons-megaphone',
            'dashicons-money-alt',
            'dashicons-networking',
            'dashicons-palmtree',
            'dashicons-paperclip',
            'dashicons-performance',
            'dashicons-pets',
            'dashicons-portfolio',
            'dashicons-products',
            'dashicons-randomize',
            'dashicons-rest-api',
            'dashicons-rss',
            'dashicons-schedule',
            'dashicons-search',
            'dashicons-share',
            'dashicons-shield',
            'dashicons-smartphone',
            'dashicons-sos',
            'dashicons-star-filled',
            'dashicons-store',
            'dashicons-superhero',
            'dashicons-tag',
            'dashicons-tickets',
            'dashicons-universal-access',
            'dashicons-update',
            'dashicons-vault',
            'dashicons-video-alt3',
            'dashicons-welcome-learn-more',
            'dashicons-welcome-write-blog',
            'dashicons-wordpress',
        ];
    }

    /**
     * Get an editable setting value, falling back to app metadata defaults.
     */
    private static function get_effective_app_setting_value( $app_settings, $metadata, $field ) {
        if ( isset( $app_settings[ $field ] ) && '' !== trim( (string) $app_settings[ $field ] ) ) {
            return $app_settings[ $field ];
        }

        if ( isset( $metadata[ $field ] ) && '' !== trim( (string) $metadata[ $field ] ) ) {
            return $metadata[ $field ];
        }

        return '';
    }

    /**
     * Get the editable icon value, falling back to app metadata defaults.
     */
    private static function get_effective_app_icon_value( $app_settings, $metadata ) {
        if ( isset( $app_settings['icon'] ) && '' !== trim( (string) $app_settings['icon'] ) ) {
            return $app_settings['icon'];
        }

        if ( isset( $metadata['dashicon'] ) && '' !== trim( (string) $metadata['dashicon'] ) ) {
            return $metadata['dashicon'];
        }

        if ( isset( $metadata['icon_url'] ) && '' !== trim( (string) $metadata['icon_url'] ) ) {
            return $metadata['icon_url'];
        }

        return '';
    }

    /**
     * Render the saved-state preview for one app.
     */
    private static function render_preview( $app_settings, $metadata, $app_name, $app_path, $force_show_text = false ) {
        $title      = '' !== trim( $app_settings['title'] ) ? trim( $app_settings['title'] ) : $app_name;
        $menu_items = self::get_preview_menu_items( $app_path );
        $show_text  = $force_show_text || ! empty( $app_settings['show_text'] );
        $is_hidden  = ! self::app_settings_have_visible_preview_content( $app_settings, $metadata ) && ! $force_show_text;
        ?>
        <div class="wp-app-masterbar-preview-wrap<?php echo $is_hidden ? ' is-hidden' : ''; ?>">
            <div id="wpadminbar" class="nojq">
                <div class="quicklinks" id="wp-toolbar" role="navigation" aria-label="<?php echo esc_attr__( 'Toolbar' ); ?>">
                    <ul role="menu" id="wp-admin-bar-root-default" class="ab-top-menu">
                        <li role="group" id="<?php echo esc_attr( self::get_admin_bar_node_id( $app_path ) ); ?>" class="<?php echo ! empty( $menu_items ) ? 'menupop ' : ''; ?>wp-app-admin-link">
                            <a class="ab-item" role="menuitem" href="<?php echo esc_url( isset( $metadata['url'] ) ? $metadata['url'] : '#' ); ?>"<?php echo ! empty( $menu_items ) ? ' aria-expanded="false"' : ''; ?>>
                                <span class="wp-app-link-title">
                                    <?php self::render_preview_icon( $app_path, $app_settings, $metadata, $title ); ?>
                                    <span class="wp-app-link-text" <?php echo $show_text ? '' : 'hidden'; ?>><?php echo esc_html( $title ); ?></span>
                                </span>
                            </a>
                            <?php if ( ! empty( $menu_items ) ) : ?>
                                <div class="ab-sub-wrapper">
                                    <ul role="menu" id="<?php echo esc_attr( self::get_admin_bar_node_id( $app_path ) . '-default' ); ?>" class="ab-submenu">
                                        <?php foreach ( $menu_items as $item ) : ?>
                                            <li role="group">
                                                <?php if ( ! empty( $item['href'] ) ) : ?>
                                                    <a class="ab-item" role="menuitem" href="<?php echo esc_url( $item['href'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
                                                <?php else : ?>
                                                    <span class="ab-item"><?php echo esc_html( $item['title'] ); ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render why this app does not appear as a global masterbar entry.
     */
    private static function render_visibility_note_if_needed( $app_path, $status ) {
        if ( in_array( $status['state'], [ 'shown', 'overflow' ], true ) ) {
            return;
        }
        ?>
        <p class="description wp-app-masterbar-visibility-note">
            <strong><?php echo esc_html( $status['label'] ); ?></strong>
            <?php echo esc_html( $status['message'] ); ?>
            <code><?php echo esc_html( $app_path ); ?></code>
        </p>
        <?php
    }

    /**
     * Render package diagnostics for one registered app.
     *
     * @param array $metadata App metadata.
     */
    private static function render_package_metadata( $metadata ) {
        if ( empty( $metadata['wp_app_package'] ) || ! is_array( $metadata['wp_app_package'] ) ) {
            return;
        }

        $package         = $metadata['wp_app_package'];
        $loaded          = isset( $package['loaded'] ) && is_array( $package['loaded'] ) ? $package['loaded'] : [];
        $has_expected    = isset( $package['expected'] ) && '' !== (string) $package['expected'];
        $expected        = $has_expected ? (string) $package['expected'] : __( 'Not detected' );
        $loaded_version  = isset( $loaded['version'] ) && '' !== (string) $loaded['version'] ? (string) $loaded['version'] : __( 'the loaded copy' );
        $loaded_raw_path = isset( $loaded['path'] ) ? (string) $loaded['path'] : '';
        $source_raw_path = isset( $package['expected_source'] ) ? (string) $package['expected_source'] : '';
        $loaded_path     = '' !== $loaded_raw_path ? self::format_filesystem_path( $loaded_raw_path ) : __( 'Unknown' );
        $expected_source = '' !== $source_raw_path ? self::format_filesystem_path( $source_raw_path ) : '';
        $expected_plugin = self::get_wp_content_plugin_slug( $expected_source );
        $loaded_plugin   = self::get_wp_content_plugin_slug( $loaded_path );
        $using_own_copy  = '' !== $expected_plugin && $expected_plugin === $loaded_plugin;
        $using_other     = '' !== $expected_plugin && '' !== $loaded_plugin && $expected_plugin !== $loaded_plugin;
        $app_plugin      = self::get_plugin_metadata_from_path( $source_raw_path );
        $loaded_provider = self::get_plugin_metadata_from_path( $loaded_raw_path );
        $app_label       = self::format_plugin_label( $app_plugin );
        $provider_label  = self::format_plugin_label( $loaded_provider );
        ?>
        <details class="wp-app-settings-package">
            <summary>
                <?php
                if ( $using_own_copy ) {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Plugin name and version. 2: Loaded wp-app version. */
                            __( '%1$s is using its bundled wp-app %2$s' ),
                            $app_label,
                            $loaded_version
                        )
                    );
                } elseif ( $using_other && '' !== $provider_label ) {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Plugin name and version. 2: Loaded wp-app version. 3: Plugin name and version providing wp-app. */
                            __( '%1$s is using wp-app %2$s from %3$s' ),
                            $app_label,
                            $loaded_version,
                            $provider_label
                        )
                    );
                } else {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Plugin name and version. 2: Loaded wp-app version. */
                            __( '%1$s is using wp-app %2$s' ),
                            $app_label,
                            $loaded_version
                        )
                    );
                }
                ?>
            </summary>
            <p class="description wp-app-settings-package-summary">
                <?php
                if ( $using_own_copy ) {
                    echo esc_html__( 'The active wp-app copy comes from this plugin.' );
                } elseif ( $using_other && '' !== $provider_label ) {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Plugin name and version. 2: Plugin name and version providing wp-app. */
                            __( '%1$s includes wp-app, but the copy from %2$s was loaded first.' ),
                            $app_label,
                            $provider_label
                        )
                    );
                } elseif ( $has_expected ) {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Expected wp-app version constraint. 2: Loaded wp-app version. */
                            __( 'This app asks for %1$s and is using wp-app %2$s.' ),
                            $expected,
                            $loaded_version
                        )
                    );
                } else {
                    echo esc_html(
                        sprintf(
                            /* translators: 1: Plugin name and version. 2: Loaded wp-app version. */
                            __( '%1$s is using wp-app %2$s.' ),
                            $app_label,
                            $loaded_version
                        )
                    );
                }
                ?>
            </p>
            <dl>
                <dt><?php echo esc_html__( 'App plugin' ); ?></dt>
                <dd>
                    <?php echo esc_html( $app_label ); ?>
                    <?php if ( $has_expected ) : ?>
                        <span class="description"><?php echo esc_html__( 'requires wp-app' ); ?></span>
                        <code><?php echo esc_html( $expected ); ?></code>
                    <?php else : ?>
                        <span class="description"><?php echo esc_html__( 'wp-app requirement not detected' ); ?></span>
                    <?php endif; ?>
                    <?php if ( '' !== $expected_source ) : ?>
                        <?php /* translators: %s: Path to the composer.json file that declares the wp-app package requirement. */ ?>
                        <span class="description"><?php echo esc_html( sprintf( __( 'from %s' ), $expected_source ) ); ?></span>
                    <?php endif; ?>
                </dd>
                <dt><?php echo esc_html__( 'Active wp-app' ); ?></dt>
                <dd>
                    <code><?php echo esc_html( $loaded_version ); ?></code>
                    <?php if ( '' !== $provider_label ) : ?>
                        <?php /* translators: %s: Plugin name and version providing the loaded wp-app package. */ ?>
                        <span class="description"><?php echo esc_html( sprintf( __( 'from %s' ), $provider_label ) ); ?></span>
                    <?php endif; ?>
                    <?php /* translators: %s: Filesystem path to the loaded wp-app package. */ ?>
                    <span class="description"><?php echo esc_html( sprintf( __( 'from %s' ), $loaded_path ) ); ?></span>
                </dd>
                <dt><?php echo esc_html__( 'Launchers' ); ?></dt>
                <dd>
                    <?php foreach ( self::get_launcher_status() as $launcher ) : ?>
                        <?php echo esc_html( $launcher['label'] ); ?>:
                        <code><?php echo esc_html( $launcher['status'] ); ?></code>
                        <br>
                    <?php endforeach; ?>
                </dd>
            </dl>
        </details>
        <?php
    }

    /**
     * Status of each launcher integration for the loaded wp-app copy.
     *
     * @return array<int, array{label:string,status:string}>
     */
    public static function get_launcher_status() {
        $my_apps_active = class_exists( 'My_Apps\\My_Apps' ) || function_exists( 'my_apps_get_apps' );

        if ( ! Openstation::is_available() ) {
            $openstation_status = __( 'plugin not active' );
        } elseif ( 'openstation' === Openstation::get_prefix() ) {
            $openstation_status = __( 'active, apps registered as desktop icons' );
        } else {
            $openstation_status = __( 'active (legacy Desktop Mode API), apps registered as desktop icons' );
        }

        return [
            [
                'label'  => 'My Apps',
                'status' => $my_apps_active ? __( 'active, apps registered via my_apps_plugins' ) : __( 'plugin not active' ),
            ],
            [
                'label'  => 'OpenStation',
                'status' => $openstation_status,
            ],
        ];
    }

    /**
     * Format a filesystem path for admin display.
     *
     * @param string $path Filesystem path.
     * @return string Display path.
     */
    private static function format_filesystem_path( $path ) {
        $path = self::normalize_filesystem_path( $path );

        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $content_dir = rtrim( self::normalize_filesystem_path( WP_CONTENT_DIR ), '/' );

            if ( 0 === strpos( $path, $content_dir . '/' ) ) {
                return 'wp-content/' . substr( $path, strlen( $content_dir ) + 1 );
            }
        }

        return $path;
    }

    /**
     * Normalize a path for display without resolving symlinks.
     *
     * @param string $path Filesystem path.
     * @return string Normalized path.
     */
    private static function normalize_filesystem_path( $path ) {
        $path     = str_replace( '\\', '/', (string) $path );
        $prefix   = '';
        $segments = [];

        if ( preg_match( '#^[a-zA-Z]:/#', $path, $matches ) ) {
            $prefix = $matches[0];
            $path   = substr( $path, strlen( $prefix ) );
        } elseif ( 0 === strpos( $path, '/' ) ) {
            $prefix = '/';
            $path   = ltrim( $path, '/' );
        }

        foreach ( explode( '/', $path ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }

            if ( '..' === $segment ) {
                if ( ! empty( $segments ) && '..' !== end( $segments ) ) {
                    array_pop( $segments );
                    continue;
                }

                if ( '' === $prefix ) {
                    $segments[] = $segment;
                }

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode( '/', $segments );
    }

    /**
     * Get a plugin folder slug from a formatted wp-content path.
     *
     * @param string $path Formatted filesystem path.
     * @return string Plugin folder slug, or an empty string.
     */
    private static function get_wp_content_plugin_slug( $path ) {
        $path = self::normalize_filesystem_path( $path );

        $plugins_dir = self::get_plugin_root_path();

        if ( '' !== $plugins_dir ) {
            $plugins_dir = rtrim( $plugins_dir, '/' ) . '/';

            if ( 0 === strpos( $path, $plugins_dir ) ) {
                $relative = substr( $path, strlen( $plugins_dir ) );
                $parts    = explode( '/', $relative );

                return isset( $parts[0] ) ? $parts[0] : '';
            }
        }

        if ( preg_match( '#(?:^|/)wp-content/plugins/([^/]+)#', $path, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get a friendly plugin folder name from a formatted wp-content path.
     *
     * @param string $path Formatted filesystem path.
     * @return string Plugin folder name, or an empty string.
     */
    private static function get_wp_content_plugin_name( $path ) {
        $slug = self::get_wp_content_plugin_slug( $path );

        if ( '' === $slug ) {
            return '';
        }

        return ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
    }

    /**
     * Get plugin metadata from a path inside a plugin folder.
     *
     * @param string $path Filesystem path.
     * @return array Plugin metadata.
     */
    private static function get_plugin_metadata_from_path( $path ) {
        $slug = self::get_wp_content_plugin_slug( $path );

        if ( '' === $slug ) {
            return [
                'name'    => __( 'This app' ),
                'version' => '',
                'slug'    => '',
                'file'    => '',
            ];
        }

        $metadata = [
            'name'    => self::get_wp_content_plugin_name( $path ),
            'version' => '',
            'slug'    => $slug,
            'file'    => '',
        ];

        $plugin_file = self::find_plugin_file( $slug );

        if ( '' === $plugin_file ) {
            return $metadata;
        }

        $plugin_root = rtrim( self::get_plugin_root_path(), '/' ) . '/';
        $plugin_file = self::normalize_filesystem_path( $plugin_file );

        if ( 0 === strpos( $plugin_file, $plugin_root ) ) {
            $metadata['file'] = substr( $plugin_file, strlen( $plugin_root ) );
        }

        $headers = self::read_plugin_headers( $plugin_file );

        if ( ! empty( $headers['Plugin Name'] ) ) {
            $metadata['name'] = $headers['Plugin Name'];
        }

        if ( ! empty( $headers['Version'] ) ) {
            $metadata['version'] = $headers['Version'];
        }

        return $metadata;
    }

    /**
     * Format plugin name and version for display.
     *
     * @param array $metadata Plugin metadata.
     * @return string Plugin label.
     */
    private static function format_plugin_label( $metadata ) {
        $name = isset( $metadata['name'] ) && '' !== (string) $metadata['name'] ? (string) $metadata['name'] : __( 'This app' );

        if ( empty( $metadata['version'] ) ) {
            return $name;
        }

        return $name . ' ' . $metadata['version'];
    }

    /**
     * Find the main plugin file for a plugin slug.
     *
     * @param string $slug Plugin folder slug.
     * @return string Plugin file path, or an empty string.
     */
    private static function find_plugin_file( $slug ) {
        $plugin_root = self::get_plugin_root_path();

        if ( '' === $plugin_root ) {
            return '';
        }

        $plugin_dir = rtrim( $plugin_root, '/' ) . '/' . $slug;

        if ( ! is_dir( $plugin_dir ) ) {
            return '';
        }

        self::load_wordpress_plugin_functions();

        if ( function_exists( 'get_plugins' ) ) {
            $plugins = get_plugins( '/' . $slug );

            if ( is_array( $plugins ) && ! empty( $plugins ) ) {
                if ( isset( $plugins[ $slug . '.php' ] ) ) {
                    return $plugin_dir . '/' . $slug . '.php';
                }

                $plugin_files = array_keys( $plugins );
                $plugin_file  = reset( $plugin_files );

                if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
                    return $plugin_dir . '/' . $plugin_file;
                }
            }
        }

        $slug_file = $plugin_dir . '/' . $slug . '.php';

        if ( is_readable( $slug_file ) && self::file_has_plugin_header( $slug_file ) ) {
            return $slug_file;
        }

        $files = glob( $plugin_dir . '/*.php' );

        if ( ! is_array( $files ) ) {
            return '';
        }

        foreach ( $files as $file ) {
            if ( self::file_has_plugin_header( $file ) ) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Get the normalized plugins directory path.
     *
     * @return string Plugin root path, or an empty string.
     */
    private static function get_plugin_root_path() {
        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            return rtrim( self::normalize_filesystem_path( WP_PLUGIN_DIR ), '/' );
        }

        if ( defined( 'WP_CONTENT_DIR' ) ) {
            return rtrim( self::normalize_filesystem_path( WP_CONTENT_DIR ), '/' ) . '/plugins';
        }

        return '';
    }

    /**
     * Whether a file contains a WordPress plugin header.
     *
     * @param string $file File path.
     * @return bool True when plugin header exists.
     */
    private static function file_has_plugin_header( $file ) {
        $headers = self::read_plugin_headers( $file );

        return ! empty( $headers['Plugin Name'] );
    }

    /**
     * Read the WordPress plugin name and version headers from a file.
     *
     * @param string $file File path.
     * @return array Plugin headers.
     */
    private static function read_plugin_headers( $file ) {
        if ( ! is_readable( $file ) ) {
            return [];
        }

        self::load_wordpress_plugin_functions();

        if ( function_exists( 'get_plugin_data' ) ) {
            $data = get_plugin_data( $file, false, false );

            return [
                'Plugin Name' => isset( $data['Name'] ) ? $data['Name'] : '',
                'Version'     => isset( $data['Version'] ) ? $data['Version'] : '',
            ];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin header file.
        $contents = file_get_contents( $file, false, null, 0, 8192 );

        if ( ! is_string( $contents ) ) {
            return [];
        }

        $headers = [];

        foreach ( [ 'Plugin Name', 'Version' ] as $header ) {
            if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $matches ) ) {
                $headers[ $header ] = trim( wp_strip_all_tags( $matches[1] ) );
            }
        }

        return $headers;
    }

    /**
     * Load WordPress' plugin metadata helpers when available.
     */
    private static function load_wordpress_plugin_functions() {
        if ( function_exists( 'get_plugin_data' ) && function_exists( 'get_plugins' ) ) {
            return;
        }

        if ( defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }

    /**
     * Whether the always-show setting can affect this app's global masterbar entry.
     */
    private static function should_render_always_show_setting( $status ) {
        return ! in_array( $status['state'], [ 'disabled', 'not_wpapp', 'access' ], true );
    }

    /**
     * Whether this settings card can customize a real masterbar entry.
     */
    private static function should_render_app_settings_controls( $status ) {
        return ! in_array( $status['state'], [ 'disabled', 'not_wpapp' ], true );
    }

    /**
     * Render the saved-state preview icon for one app.
     */
    private static function render_preview_icon( $app_path, $app_settings, $metadata, $title ) {
        $hidden = empty( $app_settings['show_icon'] );

        if ( class_exists( __NAMESPACE__ . '\Masterbar' ) && method_exists( __NAMESPACE__ . '\Masterbar', 'get_app_link_icon_html_for_app' ) ) {
            echo Masterbar::get_app_link_icon_html_for_app( $app_path, $metadata, $title, $hidden );
            return;
        }

        echo '<span class="wp-app-link-icon wp-app-link-icon-generated"' . ( $hidden ? ' hidden' : '' ) . '>';
        echo esc_html( strtoupper( substr( $title, 0, 1 ) ) );
        echo '</span>';
    }

    /**
     * Get current menu items for an app preview.
     */
    private static function get_preview_menu_items( $app_path ) {
        if ( ! class_exists( __NAMESPACE__ . '\Masterbar' ) || ! method_exists( __NAMESPACE__ . '\Masterbar', 'get_instance_for_app' ) ) {
            return [];
        }

        $masterbar = Masterbar::get_instance_for_app( $app_path );

        if ( ! $masterbar || ! method_exists( $masterbar, 'get_preview_menu_items' ) ) {
            return [];
        }

        $items = $masterbar->get_preview_menu_items();

        if ( ! is_array( $items ) ) {
            return [];
        }

        return array_filter(
            $items,
            function ( $item ) {
                return is_array( $item ) && isset( $item['title'] );
            }
        );
    }

    /**
     * Check if saved app settings produce visible preview content.
     */
    private static function app_settings_have_visible_preview_content( $app_settings, $metadata ) {
        if ( ! empty( $app_settings['show_text'] ) ) {
            return true;
        }

        if ( empty( $app_settings['show_icon'] ) ) {
            return false;
        }

        return ! empty( $app_settings['show_icon'] );
    }

    /**
     * Explain whether an app appears in the real masterbar.
     */
    public static function get_masterbar_visibility_status( $app_path, $app_settings = null, $metadata = null ) {
        $app_settings = is_array( $app_settings ) ? $app_settings : self::get_app_settings( $app_path );
        $metadata     = is_array( $metadata ) ? $metadata : [];
        $masterbar    = self::get_masterbar_for_app( $app_path );

        if ( ! $masterbar ) {
            return [
                'state'   => 'not_wpapp',
                'label'   => __( 'Not shown in the masterbar.' ),
                'message' => __( 'No WpApp masterbar instance is registered for' ),
            ];
        }

        if ( method_exists( $masterbar, 'is_admin_bar_app_link_enabled' ) && ! $masterbar->is_admin_bar_app_link_enabled() ) {
            return [
                'state'   => 'disabled',
                'label'   => __( 'Not shown in the masterbar.' ),
                'message' => __( 'This app disabled its automatic masterbar link, so there is no masterbar entry to customize for' ),
            ];
        }

        if ( class_exists( __NAMESPACE__ . '\Registry' ) && ! Registry::can_user_access_app( $app_path ) ) {
            return [
                'state'   => 'access',
                'label'   => __( 'Hidden for you.' ),
                'message' => __( 'The current user does not have access to' ),
            ];
        }

        if ( ! self::app_settings_have_visible_preview_content( $app_settings, $metadata ) ) {
            return [
                'state'   => 'empty',
                'label'   => __( 'Not shown in the masterbar.' ),
                'message' => __( 'Icon and text are both disabled for' ),
            ];
        }

        if ( ! self::should_show_global_app_link( $app_path ) ) {
            if ( self::should_show_inactive_apps_in_overflow() ) {
                return [
                    'state'   => 'overflow',
                    'label'   => __( 'Shown in the overflow menu.' ),
                    'message' => __( 'Inactive app pages collect this app under the Apps overflow menu for' ),
                ];
            }

            return [
                'state'   => 'active_only',
                'label'   => __( 'Shown only on its app pages.' ),
                'message' => __( 'The global setting hides inactive app links unless Always show this menu entry is enabled for' ),
            ];
        }

        return [
            'state'   => 'shown',
            'label'   => '',
            'message' => '',
        ];
    }

    /**
     * Sort registered apps by saved order, then append new apps alphabetically.
     */
    private static function sort_registered_apps( $apps ) {
        if ( ! is_array( $apps ) ) {
            return [];
        }

        $settings = self::get_settings();
        $order    = isset( $settings['app_order'] ) && is_array( $settings['app_order'] ) ? $settings['app_order'] : [];
        $sorted   = [];

        foreach ( $order as $app_path ) {
            if ( isset( $apps[ $app_path ] ) ) {
                $sorted[ $app_path ] = $apps[ $app_path ];
                unset( $apps[ $app_path ] );
            }
        }

        ksort( $apps );

        return array_merge( $sorted, $apps );
    }

    /**
     * Get a field name for one app setting.
     */
    private static function get_field_name( $app_path, $field ) {
        return self::OPTION . '[apps][' . $app_path . '][' . $field . ']';
    }

    /**
     * Get a stable field ID for one app setting.
     */
    private static function get_field_id( $app_path, $field ) {
        return self::OPTION . '_' . preg_replace( '/[^A-Za-z0-9_-]/', '_', $app_path ) . '_' . $field;
    }

    /**
     * Get the admin bar node ID used for an app link.
     */
    private static function get_admin_bar_node_id( $app_path ) {
        return 'wp-admin-bar-wp-app-link-' . str_replace( '-', '_', $app_path );
    }

    /**
     * Sanitize an app URL path while preserving path separators.
     */
    private static function sanitize_app_path( $app_path ) {
        $app_path = is_string( $app_path ) ? $app_path : '';
        $app_path = strtolower( trim( $app_path, " \t\n\r\0\x0B/" ) );
        return preg_replace( '/[^a-z0-9_\-\/]/', '', $app_path );
    }

    /**
     * Get the masterbar instance for an app path.
     */
    private static function get_masterbar_for_app( $app_path ) {
        if ( ! class_exists( __NAMESPACE__ . '\Masterbar' ) || ! method_exists( __NAMESPACE__ . '\Masterbar', 'get_instance_for_app' ) ) {
            return null;
        }

        return Masterbar::get_instance_for_app( $app_path );
    }
}
