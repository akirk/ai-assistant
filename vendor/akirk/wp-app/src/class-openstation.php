<?php

namespace WpApp;

if ( class_exists( 'WpApp\Openstation' ) ) {
    return;
}

/**
 * OpenStation (formerly Desktop Mode) integration.
 *
 * Registers every wp-app as a desktop icon in the OpenStation shell and
 * renders app pages without the masterbar when they are loaded inside an
 * OpenStation window.
 *
 * @see https://wordpress.org/plugins/desktop-mode/
 */
class Openstation {
    private static $hooks_initialized = false;

    /**
     * Hook the integration once for all apps.
     */
    public static function init() {
        if ( self::$hooks_initialized ) {
            return;
        }
        self::$hooks_initialized = true;

        // Apps register on plugins_loaded / init; the shell's own registries
        // are ready by init 10, so register right after them.
        if ( did_action( 'init' ) || doing_action( 'init' ) ) {
            self::register_icons();
        } else {
            add_action( 'init', [ __CLASS__, 'register_icons' ], 20 );
        }

        add_filter( 'openstation_dock_items', [ __CLASS__, 'add_dock_items' ] );
        add_filter( 'desktop_mode_dock_items', [ __CLASS__, 'add_dock_items' ] );
        add_filter( 'openstation_dock_placement', [ __CLASS__, 'hide_owned_post_type_menus' ], 10, 2 );
        add_filter( 'desktop_mode_dock_placement', [ __CLASS__, 'hide_owned_post_type_menus' ], 10, 2 );
        add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
        add_action( 'wp_app_before_render', [ __CLASS__, 'enqueue_iframe_bridge' ] );
    }

    /**
     * Reset hook state (used by tests).
     */
    public static function reset() {
        self::$hooks_initialized = false;
    }

    /**
     * Function prefix of the active shell plugin.
     *
     * @return string 'openstation', 'desktop_mode', or '' when neither is active.
     */
    public static function get_prefix() {
        if ( function_exists( 'openstation_register_icon' ) ) {
            return 'openstation';
        }
        if ( function_exists( 'desktop_mode_register_icon' ) ) {
            return 'desktop_mode';
        }
        return '';
    }

    /**
     * Whether the shell plugin is active.
     */
    public static function is_available() {
        return '' !== self::get_prefix();
    }

    /**
     * Query flag the shell uses to request a page without admin chrome.
     */
    public static function get_chromeless_flag() {
        return 'desktop_mode' === self::get_prefix() ? 'desktop_mode_chromeless' : 'openstation_chromeless';
    }

    /**
     * Whether the current request is rendered inside an OpenStation window.
     *
     * Delegates to the shell so its safeguards apply: the flag only counts
     * for users who enabled OpenStation, and same-origin iframe loads that
     * lost the flag are still detected via Sec-Fetch headers.
     */
    public static function is_chromeless_request() {
        if ( function_exists( 'openstation_is_chromeless_request' ) ) {
            return (bool) openstation_is_chromeless_request();
        }
        if ( function_exists( 'desktop_mode_is_chromeless_request' ) ) {
            return (bool) desktop_mode_is_chromeless_request();
        }
        return false;
    }

    /**
     * Register one desktop icon per app the current user can access.
     */
    public static function register_icons() {
        $prefix = self::get_prefix();
        if ( '' === $prefix ) {
            return;
        }

        $register_icon = $prefix . '_register_icon';
        $capabilities  = Registry::get_app_capabilities();
        $position      = 10;

        foreach ( Registry::get_app_metadata() as $app_path => $metadata ) {
            if ( isset( $metadata['launcher'] ) && false === $metadata['launcher'] ) {
                continue;
            }
            if ( ! Registry::can_user_access_app( $app_path ) ) {
                continue;
            }

            $args             = self::get_icon_args( $app_path, $metadata );
            $args['position'] = $position;
            $position        += 10;

            if ( ! empty( $capabilities[ $app_path ] ) && is_string( $capabilities[ $app_path ] ) ) {
                $args['capabilities'] = [ $capabilities[ $app_path ] ];
            }

            call_user_func( $register_icon, self::get_icon_id( $app_path ), $args );
        }
    }

    /**
     * Add every accessible app to the dock. The app's masterbar menu items
     * become the window's tabs, the way an admin menu's submenu does.
     *
     * @param array[] $items Dock items.
     * @return array[]
     */
    public static function add_dock_items( $items ) {
        if ( ! is_array( $items ) ) {
            $items = [];
        }

        foreach ( Registry::get_app_metadata() as $app_path => $metadata ) {
            if ( isset( $metadata['launcher'] ) && false === $metadata['launcher'] ) {
                continue;
            }
            if ( ! Registry::can_user_access_app( $app_path ) ) {
                continue;
            }

            $items[] = self::get_dock_item( $app_path, $metadata );
        }

        return $items;
    }

    /**
     * Build the dock item for an app.
     *
     * @param string $app_path App URL path.
     * @param array  $metadata App metadata from the Registry.
     * @return array Dock item in the shape of openstation_dock_items entries.
     */
    public static function get_dock_item( $app_path, $metadata ) {
        $icon_args = self::get_icon_args( $app_path, $metadata );
        $icon      = isset( $icon_args['icon'] ) ? $icon_args['icon'] : 'data:image/svg+xml;base64,' . base64_encode( $icon_args['icon_svg'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SVG data URI for the dock.

        return [
            'id'         => 'wp-app-' . self::get_icon_id( $app_path ),
            'title'      => $icon_args['title'],
            'icon'       => $icon,
            'url'        => $icon_args['url'],
            'badge'      => null,
            'submenu'    => self::get_submenu( $app_path ),
            'selfLabel'  => $icon_args['title'],
            'multi'      => false,
            'placement'  => 'dock',
            'isCore'     => false,
            'pluginFile' => null,
            'pluginName' => null,
        ];
    }

    /**
     * Window tabs for an app: its masterbar menu items with a link.
     *
     * @param string $app_path App URL path.
     * @return array[] Entries of title and chromeless url.
     */
    public static function get_submenu( $app_path ) {
        $masterbar = class_exists( __NAMESPACE__ . '\\Masterbar' ) ? Masterbar::get_instance_for_app( $app_path ) : null;
        if ( ! $masterbar ) {
            return [];
        }

        $flag    = self::get_chromeless_flag();
        $submenu = [];
        foreach ( $masterbar->get_preview_menu_items() as $item ) {
            if ( empty( $item['href'] ) || empty( $item['title'] ) ) {
                continue;
            }
            $submenu[] = [
                'title' => (string) $item['title'],
                'url'   => add_query_arg( $flag, '1', (string) $item['href'] ),
            ];
        }

        return $submenu;
    }

    /**
     * Hide the admin menu of app-owned post types. A post type declared via
     * Access::protect_post_type() belongs to an app, and the app window is
     * where that content is managed, so the dock does not need a second
     * tile for it.
     *
     * @param string $placement 'dock' or 'hidden'.
     * @param string $menu_slug Admin menu slug, e.g. `edit.php?post_type=book`.
     * @return string
     */
    public static function hide_owned_post_type_menus( $placement, $menu_slug ) {
        if ( 0 !== strpos( (string) $menu_slug, 'edit.php?post_type=' ) ) {
            return $placement;
        }
        if ( ! class_exists( __NAMESPACE__ . '\\Rest\\Access' ) ) {
            return $placement;
        }

        $post_type = substr( (string) $menu_slug, strlen( 'edit.php?post_type=' ) );

        return in_array( $post_type, Rest\Access::get_protected_post_types(), true ) ? 'hidden' : $placement;
    }

    /**
     * Icon id for an app path; nested paths keep their segments distinct.
     *
     * @param string $app_path App URL path.
     * @return string
     */
    public static function get_icon_id( $app_path ) {
        return sanitize_key( str_replace( '/', '-', $app_path ) );
    }

    /**
     * Build the icon registration arguments for an app.
     *
     * @param string $app_path App URL path.
     * @param array  $metadata App metadata from the Registry.
     * @return array Arguments for openstation_register_icon().
     */
    public static function get_icon_args( $app_path, $metadata ) {
        $name = isset( $metadata['name'] ) && '' !== $metadata['name'] ? (string) $metadata['name'] : $app_path;

        $url = isset( $metadata['url'] ) ? (string) $metadata['url'] : home_url( '/' . $app_path . '/' );

        $args = [
            'title' => $name,
            'url'   => add_query_arg( self::get_chromeless_flag(), '1', $url ),
        ];

        if ( ! empty( $metadata['dashicon'] ) ) {
            $args['icon'] = (string) $metadata['dashicon'];
            foreach ( [ 'icon_background', 'icon_color' ] as $style_key ) {
                if ( ! empty( $metadata[ $style_key ] ) && is_string( $metadata[ $style_key ] ) ) {
                    $args[ $style_key ] = $metadata[ $style_key ];
                }
            }
        } elseif ( ! empty( $metadata['icon_url'] ) ) {
            $args['icon'] = (string) $metadata['icon_url'];
        } else {
            $args['icon_svg'] = self::build_letter_svg( $name );
        }

        return $args;
    }

    /**
     * Build a letter-badge SVG for apps without an icon.
     *
     * @param string $name App display name.
     * @return string SVG markup.
     */
    public static function build_letter_svg( $name ) {
        $name  = trim( wp_strip_all_tags( (string) $name ) );
        $words = preg_split( '/[\s_\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );

        if ( empty( $words ) ) {
            $letters = '?';
        } elseif ( count( $words ) >= 2 ) {
            $letters = mb_strtoupper( mb_substr( $words[0], 0, 1 ) . mb_substr( $words[1], 0, 1 ) );
        } else {
            $letters = mb_strtoupper( mb_substr( $words[0], 0, 1 ) );
        }

        // djb2 hash folded to 32 bits, for a stable hue per app name.
        $hash = 5381;
        $key  = strtolower( $name );
        $len  = strlen( $key );
        for ( $i = 0; $i < $len; $i++ ) {
            $hash = ( ( $hash * 33 ) + ord( $key[ $i ] ) ) & 0xFFFFFFFF;
        }
        $hue = $hash % 360;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' .
            '<rect width="100" height="100" rx="22" ry="22" fill="hsl(%d, 55%%, 45%%)"/>' .
            '<text x="50" y="50" fill="#fff" text-anchor="middle" dominant-baseline="central" ' .
            'font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" ' .
            'font-weight="600" font-size="%d">%s</text>' .
            '</svg>',
            $hue,
            mb_strlen( $letters ) > 1 ? 36 : 46,
            esc_html( $letters )
        );
    }

    /**
     * Mark chromeless app pages so styles can adapt.
     *
     * @param array $classes Body classes.
     * @return array
     */
    public static function add_body_class( $classes ) {
        if ( self::is_chromeless_request() ) {
            $classes[] = 'wp-app-chromeless';
        }
        return $classes;
    }

    /**
     * Load the shell's iframe bridge on chromeless app pages so the window
     * picks up title changes, theme colors, and link handling.
     */
    public static function enqueue_iframe_bridge() {
        if ( ! self::is_chromeless_request() || ! function_exists( 'wp_enqueue_script' ) ) {
            return;
        }
        if ( function_exists( 'wp_script_is' ) && wp_script_is( 'os-iframe-bridge', 'registered' ) ) {
            wp_enqueue_script( 'os-iframe-bridge' );
        }
    }
}
