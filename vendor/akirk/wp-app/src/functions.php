<?php

/**
 * Global functions for WpApp framework
 */

if ( ! defined( 'WP_APP_VERSION' ) ) {
    define( 'WP_APP_VERSION', '1.2.5' );
}

if ( ! function_exists( 'wp_app_is_app_request' ) ) {
    /**
     * Determine whether the current request is being handled by WpApp.
     */
    function wp_app_is_app_request() {
        global $wp_query;

        if ( $wp_query && isset( $wp_query->query_vars['wp_app_request'], $wp_query->query_vars['wp_app_path'] ) ) {
            return true;
        }

        return function_exists( 'get_query_var' ) && null !== get_query_var( 'wp_app_path', null );
    }
}

if ( ! function_exists( 'wp_app_head' ) ) {
    /**
     * Generate HTML head content for app templates
     * Similar to wp_head() but clean and without theme/plugin interference
     */
    function wp_app_head() {
        if ( function_exists( 'wp_app_dequeue_theme_assets' ) ) {
            wp_app_dequeue_theme_assets();
        }

        if ( function_exists( 'wp_head' ) ) {
            wp_head();
        } else {
            // Basic meta tags (fallback when WordPress is not present)
            echo '<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">' . "\n";
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";

            // CSRF token for AJAX requests
            echo '<meta name="csrf-token" content="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">' . "\n";

            // Allow language attributes
            if ( function_exists( 'get_language_attributes' ) ) {
                echo '<meta name="language" content="' . esc_attr( get_language_attributes() ) . '">' . "\n";
            }
        }

        // Custom app head hook - allows components to add styles/scripts
        do_action( 'wp_app_head' );

        // Allow specific head content injection
        wp_app_do_scoped_action( 'wp_app_head_meta' );
        wp_app_do_scoped_action( 'wp_app_head_styles' );
        wp_app_do_scoped_action( 'wp_app_head_scripts' );
    }
}

if ( ! function_exists( 'wp_app_body_open' ) ) {
    /**
     * Generate body open content for app templates
     * Similar to wp_body_open() but for apps
     */
    function wp_app_body_open() {
        // Include WordPress admin bar if showing
        if ( is_admin_bar_showing() ) {
            wp_admin_bar_render();
        }

        // Custom app body open hook
        do_action( 'wp_app_body_open' );
    }
}

if ( ! function_exists( 'wp_app_body_close' ) ) {
    /**
     * Generate body close content for app templates
     */
    function wp_app_body_close() {
        if ( function_exists( 'wp_app_dequeue_theme_assets' ) ) {
            wp_app_dequeue_theme_assets();
        }

        if ( function_exists( 'wp_footer' ) ) {
            wp_footer();
        }

        // Custom app body close hook
        wp_app_do_scoped_action( 'wp_app_body_close' );
    }
}

if ( ! function_exists( 'wp_app_get_current_app_path' ) ) {
    /**
     * Get the URL path for the app currently being rendered.
     *
     * @return string
     */
    function wp_app_get_current_app_path() {
        global $wp_app_route;

        if ( isset( $wp_app_route['app_path'] ) && is_scalar( $wp_app_route['app_path'] ) ) {
            return trim( (string) $wp_app_route['app_path'], '/' );
        }

        if ( isset( $wp_app_route['params']['app_path'] ) && is_scalar( $wp_app_route['params']['app_path'] ) ) {
            return trim( (string) $wp_app_route['params']['app_path'], '/' );
        }

        return '';
    }
}

if ( ! function_exists( 'wp_app_sanitize_hook_suffix' ) ) {
    /**
     * Convert an app path into a safe hook-name suffix.
     *
     * @param string $app_path App URL path.
     * @return string
     */
    function wp_app_sanitize_hook_suffix( $app_path ) {
        if ( function_exists( 'sanitize_key' ) ) {
            return sanitize_key( $app_path );
        }

        return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $app_path ) );
    }
}

if ( ! function_exists( 'wp_app_normalize_asset_scope' ) ) {
    /**
     * Resolve an enqueue scope into an app path.
     *
     * @param string|array|null $scope Optional app slug, or array with an app key.
     * @return string
     */
    function wp_app_normalize_asset_scope( $scope = null ) {
        if ( is_array( $scope ) && isset( $scope['app'] ) ) {
            $scope = $scope['app'];
        }

        if ( null === $scope ) {
            $scope = wp_app_get_current_app_path();
        }

        if ( ! is_scalar( $scope ) ) {
            return '';
        }

        $scope = trim( (string) $scope, '/' );

        if ( '' === $scope || 'global' === $scope ) {
            return '';
        }

        return $scope;
    }
}

if ( ! function_exists( 'wp_app_get_scoped_hook_name' ) ) {
    /**
     * Build a global or app-scoped hook name.
     *
     * @param string            $hook  Base hook name.
     * @param string|array|null $scope Optional app slug, or array with an app key.
     * @return string
     */
    function wp_app_get_scoped_hook_name( $hook, $scope = null ) {
        $app_path = wp_app_normalize_asset_scope( $scope );

        if ( '' === $app_path ) {
            return $hook;
        }

        return $hook . '_' . wp_app_sanitize_hook_suffix( $app_path );
    }
}

if ( ! function_exists( 'wp_app_do_scoped_action' ) ) {
    /**
     * Run a global wp-app hook, followed by the current app's scoped hook.
     *
     * @param string $hook Base hook name.
     */
    function wp_app_do_scoped_action( $hook ) {
        do_action( $hook );

        $scoped_hook = wp_app_get_scoped_hook_name( $hook );
        if ( $scoped_hook !== $hook ) {
            do_action( $scoped_hook );
        }
    }
}



if ( ! function_exists( 'wp_app_title' ) ) {
    /**
     * Generate page title for app pages
     */
    function wp_app_title( $title = '', $separator = '-' ) {
        $site_name = get_bloginfo( 'name' );

        if ( empty( $title ) ) {
            // Try to get title from current route
            global $wp_app_route;
            if ( isset( $wp_app_route['pattern'] ) ) {
                $title = ucwords( str_replace( [ '-', '_', '/' ], ' ', $wp_app_route['pattern'] ) );
            } else {
                $title = 'App';
            }
        }

        if ( $site_name ) {
            return esc_html( $title . ' ' . $separator . ' ' . $site_name );
        }

        return esc_html( $title );
    }
}

if ( ! function_exists( 'wp_app_language_attributes' ) ) {
    /**
     * Get language attributes for HTML tag
     */
    function wp_app_language_attributes() {
        $attributes = [];

        if ( function_exists( 'is_rtl' ) && is_rtl() ) {
            $attributes[] = 'dir="rtl"';
        }

        if ( function_exists( 'get_bloginfo' ) ) {
            $lang = get_bloginfo( 'language' );
            if ( $lang ) {
                $attributes[] = 'lang="' . esc_attr( $lang ) . '"';
            }
        }

        return implode( ' ', $attributes );
    }
}

if ( ! function_exists( 'wp_app_enqueue_style' ) ) {
    /**
     * Enqueue a style for app pages
     */
    function wp_app_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $scope = null ) {
        add_action(
            wp_app_get_scoped_hook_name( 'wp_app_head_styles', $scope ),
            function () use ( $handle, $src, $deps, $ver ) {
				if ( $src ) {
					$url = $src;
					if ( $ver ) {
						$url .= '?ver=' . esc_attr( $ver );
					}
					echo '<link rel="stylesheet" id="' . esc_attr( $handle ) . '-css" href="' . esc_url( $url ) . '" type="text/css" media="all">' . "\n";
				}
			}
        );
    }
}

if ( ! function_exists( 'wp_app_enqueue_script' ) ) {
    /**
     * Enqueue a script for app pages
     */
    function wp_app_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $in_footer = true, $scope = null ) {
        $hook = wp_app_get_scoped_hook_name( $in_footer ? 'wp_app_body_close' : 'wp_app_head_scripts', $scope );

        add_action(
            $hook,
            function () use ( $handle, $src, $deps, $ver ) {
				if ( $src ) {
					$url = $src;
					if ( $ver ) {
						$url .= '?ver=' . esc_attr( $ver );
					}
					echo '<script id="' . esc_attr( $handle ) . '-js" src="' . esc_url( $url ) . '"></script>' . "\n";
				}
			}
        );
    }
}

if ( ! function_exists( 'wp_app_add_inline_style' ) ) {
    /**
     * Add inline CSS for app pages
     */
    function wp_app_add_inline_style( $handle, $css, $scope = null ) {
        add_action(
            wp_app_get_scoped_hook_name( 'wp_app_head_styles', $scope ),
            function () use ( $handle, $css ) {
				echo '<style id="' . esc_attr( $handle ) . '-inline-css">' . "\n";
				echo $css . "\n";
				echo '</style>' . "\n";
			}
        );
    }
}

if ( ! function_exists( 'wp_app_add_inline_script' ) ) {
    /**
     * Add inline JavaScript for app pages
     */
    function wp_app_add_inline_script( $handle, $js, $in_footer = true, $scope = null ) {
        $hook = wp_app_get_scoped_hook_name( $in_footer ? 'wp_app_body_close' : 'wp_app_head_scripts', $scope );

        add_action(
            $hook,
            function () use ( $handle, $js ) {
				echo '<script id="' . esc_attr( $handle ) . '-inline-js">' . "\n";
				echo $js . "\n";
				echo '</script>' . "\n";
			}
        );
    }
}

if ( ! function_exists( 'wp_app_sanitize_css_color' ) ) {
    /**
     * Sanitize a CSS color value for inline custom properties.
     */
    function wp_app_sanitize_css_color( $color, $fallback ) {
        if ( function_exists( 'sanitize_hex_color' ) ) {
            $sanitized = sanitize_hex_color( $color );

            if ( $sanitized ) {
                return $sanitized;
            }
        } elseif ( is_string( $color ) && preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ) {
            return $color;
        }

        return $fallback;
    }
}

if ( ! function_exists( 'wp_app_darken_css_color' ) ) {
    /**
     * Darken a sanitized hex color by mixing it toward black.
     */
    function wp_app_darken_css_color( $color, $percentage ) {
        if ( ! is_string( $color ) || ! preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
            return $color;
        }

        $hex = ltrim( $color, '#' );

        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $percentage = max( 0, min( 100, (int) $percentage ) );
        $factor     = ( 100 - $percentage ) / 100;
        $channels   = [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];

        foreach ( $channels as $index => $channel ) {
            $channels[ $index ] = max( 0, min( 255, (int) round( $channel * $factor ) ) );
        }

        return sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );
    }
}

if ( ! function_exists( 'wp_app_normalize_admin_scheme_colors' ) ) {
    /**
     * Normalize an admin color palette into background, subtle, primary, and accent colors.
     */
    function wp_app_normalize_admin_scheme_colors( $colors, $fallback ) {
        $colors = is_array( $colors ) ? array_values( $colors ) : [];

        if ( 3 === count( $colors ) ) {
            $background = wp_app_sanitize_css_color( $colors[0], $fallback[0] );

            return [
                $background,
                $background,
                wp_app_sanitize_css_color( $colors[1], $fallback[2] ),
                wp_app_sanitize_css_color( $colors[2], $fallback[3] ),
            ];
        }

        $colors = array_pad( $colors, 4, end( $colors ) );

        return [
            wp_app_sanitize_css_color( $colors[0], $fallback[0] ),
            wp_app_sanitize_css_color( $colors[1], $fallback[1] ),
            wp_app_sanitize_css_color( $colors[2], $fallback[2] ),
            wp_app_sanitize_css_color( $colors[3], $fallback[3] ),
        ];
    }
}

if ( ! function_exists( 'wp_app_get_admin_color_scheme' ) ) {
    /**
     * Get the current user's WordPress admin color scheme as normalized tokens.
     *
     * @param int $user_id Optional user ID. Defaults to the current user.
     * @return array Normalized admin color scheme data.
     */
    function wp_app_get_admin_color_scheme( $user_id = 0 ) {
        $fallback = [
            'slug'        => 'fresh',
            'name'        => 'Default',
            'colors'      => [ '#23282d', '#32373c', '#0073aa', '#00a0d2' ],
            'icon_colors' => [
                'base'    => '#a7aaad',
                'focus'   => '#72aee6',
                'current' => '#fff',
            ],
        ];

        if ( ! function_exists( 'get_user_option' ) ) {
            return $fallback;
        }

        $user_id = $user_id ? $user_id : ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        $slug    = get_user_option( 'admin_color', $user_id );

        if ( ! $slug ) {
            $slug = 'fresh';
        }

        global $_wp_admin_css_colors;

        if ( empty( $_wp_admin_css_colors ) && defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';

            if ( function_exists( 'register_admin_color_schemes' ) ) {
                register_admin_color_schemes();
            }
        }

        if ( empty( $_wp_admin_css_colors[ $slug ] ) ) {
            $fallback['slug'] = $slug;
            return function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_admin_color_scheme', $fallback, $user_id, $slug ) : $fallback;
        }

        $scheme      = $_wp_admin_css_colors[ $slug ];
        $colors      = isset( $scheme->colors ) && is_array( $scheme->colors ) ? array_values( $scheme->colors ) : $fallback['colors'];
        $icon_colors = isset( $scheme->icon_colors ) && is_array( $scheme->icon_colors ) ? $scheme->icon_colors : $fallback['icon_colors'];
        $colors      = wp_app_normalize_admin_scheme_colors( $colors, $fallback['colors'] );

        $admin_color_scheme = [
            'slug'        => $slug,
            'name'        => isset( $scheme->name ) ? $scheme->name : $fallback['name'],
            'colors'      => [
                $colors[0],
                $colors[1],
                $colors[2],
                $colors[3],
            ],
            'icon_colors' => [
                'base'    => wp_app_sanitize_css_color( isset( $icon_colors['base'] ) ? $icon_colors['base'] : '', $fallback['icon_colors']['base'] ),
                'focus'   => wp_app_sanitize_css_color( isset( $icon_colors['focus'] ) ? $icon_colors['focus'] : '', $fallback['icon_colors']['focus'] ),
                'current' => wp_app_sanitize_css_color( isset( $icon_colors['current'] ) ? $icon_colors['current'] : '', $fallback['icon_colors']['current'] ),
            ],
        ];

        return function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_admin_color_scheme', $admin_color_scheme, $user_id, $slug ) : $admin_color_scheme;
    }
}

if ( ! function_exists( 'wp_app_get_admin_color_scheme_css' ) ) {
    /**
     * Get CSS custom properties for the current user's admin color scheme.
     *
     * @param string $selector CSS selector for the variables.
     * @param int    $user_id Optional user ID. Defaults to the current user.
     * @return string CSS custom properties block.
     */
    function wp_app_get_admin_color_scheme_css( $selector = ':root, body.wp-app-body', $user_id = 0 ) {
        $scheme   = wp_app_get_admin_color_scheme( $user_id );
        $selector = trim( preg_replace( '/[^a-zA-Z0-9\-_#\.\:\[\]=~\*"\'\(\), >\+]/', '', $selector ) );

        if ( '' === $selector ) {
            $selector = ':root, body.wp-app-body';
        }

        $mode = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_color_mode', 'auto', $user_id, $scheme ) : 'auto';

        if ( ! in_array( $mode, [ 'auto', 'light', 'dark' ], true ) ) {
            $mode = 'auto';
        }

        if ( 'dark' === $mode ) {
            return wp_app_get_color_scheme_css_block( $selector, wp_app_get_color_scheme_variables( $scheme, 'dark' ) );
        }

        $css = wp_app_get_color_scheme_css_block( $selector, wp_app_get_color_scheme_variables( $scheme, 'light' ) );

        if ( 'auto' === $mode ) {
            $css .= "@media (prefers-color-scheme: dark) {\n";
            $css .= wp_app_get_color_scheme_css_block( $selector, wp_app_get_color_scheme_variables( $scheme, 'dark' ), "\t" );
            $css .= "}\n";
        }

        return $css;
    }
}

if ( ! function_exists( 'wp_app_get_color_scheme_variables' ) ) {
    /**
     * Get CSS custom property values for a light or dark app color scheme.
     *
     * @param array  $scheme WordPress admin color scheme data.
     * @param string $mode   App color mode. Accepts 'light' or 'dark'.
     * @return array CSS custom properties and values.
     */
    function wp_app_get_color_scheme_variables( $scheme, $mode = 'light' ) {
        $variables = [
            '--wp-app-admin-color-background'   => $scheme['colors'][0],
            '--wp-app-admin-color-subtle'       => $scheme['colors'][1],
            '--wp-app-admin-color-primary'      => $scheme['colors'][2],
            '--wp-app-admin-color-accent'       => $scheme['colors'][3],
            '--wp-app-admin-icon-color-base'    => $scheme['icon_colors']['base'],
            '--wp-app-admin-icon-color-focus'   => $scheme['icon_colors']['focus'],
            '--wp-app-admin-icon-color-current' => $scheme['icon_colors']['current'],
            '--wp-app-color-primary'            => 'var(--wp-app-admin-color-primary)',
            '--wp-app-color-primary-hover'      => wp_app_darken_css_color( $scheme['colors'][2], 10 ),
            '--wp-app-color-accent'             => 'var(--wp-app-admin-color-accent)',
            '--wp-app-color-error'              => 'var(--wp-app-admin-color-accent)',
            '--wp-app-color-on-primary'         => 'var(--wp-app-admin-icon-color-current)',
            '--wp-app-color-link'               => 'var(--wp-app-admin-color-primary)',
            '--wp-app-color-link-hover'         => wp_app_darken_css_color( $scheme['colors'][2], 10 ),
            '--wp-app-color-focus'              => 'var(--wp-app-admin-color-accent)',
            '--wp-app-color-secondary'          => 'var(--wp-app-color-surface-alt)',
            '--wp-app-color-secondary-hover'    => 'var(--wp-app-color-border)',
            '--wp-app-color-secondary-text'     => 'var(--wp-app-color-text)',
            '--wp-app-masterbar-background'     => 'var(--wp-app-admin-color-background)',
            '--wp-app-masterbar-highlight'      => 'var(--wp-app-admin-color-accent)',
            '--wp-app-masterbar-text'           => 'var(--wp-app-admin-icon-color-current)',
        ];

        if ( 'dark' === $mode ) {
            return array_merge(
                $variables,
                [
                    '--wp-app-color-scheme'      => 'dark',
                    '--wp-app-color-background'  => '#101517',
                    '--wp-app-color-surface'     => '#1d2327',
                    '--wp-app-color-surface-alt' => '#2c3338',
                    '--wp-app-color-text'        => '#f0f0f1',
                    '--wp-app-color-muted'       => '#a7aaad',
                    '--wp-app-color-border'      => '#3c434a',
                ]
            );
        }

        return array_merge(
            $variables,
            [
                '--wp-app-color-scheme'      => 'light',
                '--wp-app-color-background'  => '#f6f7f7',
                '--wp-app-color-surface'     => '#fff',
                '--wp-app-color-surface-alt' => '#f0f0f1',
                '--wp-app-color-text'        => '#1d2327',
                '--wp-app-color-muted'       => '#646970',
                '--wp-app-color-border'      => '#dcdcde',
            ]
        );
    }
}

if ( ! function_exists( 'wp_app_get_color_scheme_css_block' ) ) {
    /**
     * Render CSS custom properties in a selector block.
     *
     * @param string $selector  CSS selector.
     * @param array  $variables CSS custom properties and values.
     * @param string $indent    Optional block indentation.
     * @return string CSS block.
     */
    function wp_app_get_color_scheme_css_block( $selector, $variables, $indent = '' ) {
        $css = $indent . $selector . " {\n";

        foreach ( $variables as $property => $value ) {
            $css .= $indent . "\t" . $property . ': ' . $value . ";\n";
        }

        $css .= $indent . "}\n";

        return $css;
    }
}

if ( ! function_exists( 'wp_app_get_default_color_styles' ) ) {
    /**
     * Get default app styles that consume the admin color scheme tokens.
     *
     * @return string CSS defaults for app pages.
     */
    function wp_app_get_default_color_styles() {
        return '
body.wp-app-body {
	background: var(--wp-app-color-background);
	color-scheme: var(--wp-app-color-scheme);
	color: var(--wp-app-color-text);
}

body.wp-app-body a {
	color: var(--wp-app-color-link);
}

body.wp-app-body a:hover,
body.wp-app-body a:focus {
	color: var(--wp-app-color-link-hover);
}

body.wp-app-body :focus-visible {
	outline: 2px solid var(--wp-app-color-focus);
	outline-offset: 2px;
}

body.wp-app-body ::selection {
	background: var(--wp-app-color-primary);
	color: var(--wp-app-color-on-primary);
}

body.wp-app-body .button-primary,
body.wp-app-body .button.button-primary,
body.wp-app-body button.button-primary,
body.wp-app-body input[type="submit"].button-primary {
	background: var(--wp-app-color-primary);
	border-color: var(--wp-app-color-primary);
	color: var(--wp-app-color-on-primary);
}

body.wp-app-body .button-primary:hover,
body.wp-app-body .button-primary:focus,
body.wp-app-body .button.button-primary:hover,
body.wp-app-body .button.button-primary:focus,
body.wp-app-body button.button-primary:hover,
body.wp-app-body button.button-primary:focus,
body.wp-app-body input[type="submit"].button-primary:hover,
body.wp-app-body input[type="submit"].button-primary:focus {
	background: var(--wp-app-color-primary-hover);
	border-color: var(--wp-app-color-primary-hover);
	color: var(--wp-app-color-on-primary);
}

body.wp-app-body .button:not(.button-primary),
body.wp-app-body .button-secondary {
	background: var(--wp-app-color-secondary);
	border-color: var(--wp-app-color-border);
	color: var(--wp-app-color-secondary-text);
}

body.wp-app-body .button:not(.button-primary):hover,
body.wp-app-body .button:not(.button-primary):focus,
body.wp-app-body .button-secondary:hover,
body.wp-app-body .button-secondary:focus {
	background: var(--wp-app-color-secondary-hover);
	color: var(--wp-app-color-secondary-text);
}
';
    }
}

if ( ! function_exists( 'wp_app_output_admin_color_scheme' ) ) {
    /**
     * Output CSS custom properties for the current user's admin color scheme.
     */
    function wp_app_output_admin_color_scheme() {
        $should_output = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_output_admin_color_scheme', true ) : true;

        if ( ! $should_output ) {
            return;
        }

        echo '<style id="wp-app-admin-color-scheme">' . "\n";
        echo wp_app_get_admin_color_scheme_css();

        $should_output_default_styles = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_output_default_color_styles', true ) : true;

        if ( $should_output_default_styles ) {
            echo wp_app_get_default_color_styles();
        }

        echo '</style>' . "\n";
    }
}

if ( ! function_exists( 'wp_app_asset_handle_matches' ) ) {
    /**
     * Check whether an asset handle matches a list of exact or prefix handles.
     */
    function wp_app_asset_handle_matches( $handle, $handles ) {
        foreach ( $handles as $match ) {
            if ( $handle === $match || strpos( $handle, $match ) === 0 ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'wp_app_get_asset_url_path' ) ) {
    /**
     * Get a comparable URL path for an asset source.
     */
    function wp_app_get_asset_url_path( $src ) {
        if ( ! is_string( $src ) || '' === $src ) {
            return '';
        }

        if ( 0 === strpos( $src, '//' ) ) {
            $src = 'https:' . $src;
        }

        $path = wp_parse_url( $src, PHP_URL_PATH );

        if ( ! $path ) {
            $path = strtok( $src, '?' );
        }

        return is_string( $path ) ? rtrim( $path, '/' ) : '';
    }
}

if ( ! function_exists( 'wp_app_is_theme_asset_src' ) ) {
    /**
     * Determine whether an asset source belongs to the active parent or child theme.
     */
    function wp_app_is_theme_asset_src( $src ) {
        $src_path = wp_app_get_asset_url_path( $src );

        if ( '' === $src_path ) {
            return false;
        }

        $theme_uris = [];

        if ( function_exists( 'get_stylesheet_directory_uri' ) ) {
            $theme_uris[] = get_stylesheet_directory_uri();
        }

        if ( function_exists( 'get_template_directory_uri' ) ) {
            $theme_uris[] = get_template_directory_uri();
        }

        /**
         * Filters theme asset URI bases that should be dequeued on app pages.
         *
         * @param array $theme_uris Theme asset URI bases.
         */
        $theme_uris = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_theme_asset_uris', array_filter( array_unique( $theme_uris ) ) ) : $theme_uris;

        foreach ( $theme_uris as $theme_uri ) {
            $theme_path = wp_app_get_asset_url_path( $theme_uri );

            if ( '' !== $theme_path && 0 === strpos( $src_path . '/', rtrim( $theme_path, '/' ) . '/' ) ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'wp_app_should_dequeue_asset' ) ) {
    /**
     * Determine whether an enqueued asset should be removed from an app page.
     */
    function wp_app_should_dequeue_asset( $handle, $registry, $type ) {
        $keep_handles = 'style' === $type
            ? [
				'admin-bar',
				'dashicons',
				'debug-bar',
				'query-monitor',
				'qm-',
			]
            : [
				'admin-bar',
				'query-monitor',
				'qm-',
			];

        /**
         * Filters asset handles that should always remain queued on app pages.
         *
         * Prefixes are supported, so `qm-` keeps all Query Monitor handles.
         *
         * @param array  $keep_handles Asset handles or prefixes to keep.
         * @param string $type         Asset type, either `style` or `script`.
         */
        $keep_handles = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_keep_asset_handles', $keep_handles, $type ) : $keep_handles;

        if ( wp_app_asset_handle_matches( $handle, $keep_handles ) ) {
            return false;
        }

        $dequeue_handles = 'style' === $type
            ? [
				'global-styles',
				'classic-theme-styles',
				'wp-block-library-theme',
			]
            : [];

        /**
         * Filters asset handles that should be removed from app pages.
         *
         * Prefixes are supported.
         *
         * @param array  $dequeue_handles Asset handles or prefixes to remove.
         * @param string $type            Asset type, either `style` or `script`.
         */
        $dequeue_handles = function_exists( 'apply_filters' ) ? apply_filters( 'wp_app_dequeue_asset_handles', $dequeue_handles, $type ) : $dequeue_handles;

        if ( wp_app_asset_handle_matches( $handle, $dequeue_handles ) ) {
            return true;
        }

        if ( ! isset( $registry->registered[ $handle ] ) || ! is_object( $registry->registered[ $handle ] ) ) {
            return false;
        }

        $src = isset( $registry->registered[ $handle ]->src ) ? $registry->registered[ $handle ]->src : '';

        return wp_app_is_theme_asset_src( $src );
    }
}

if ( ! function_exists( 'wp_app_dequeue_theme_assets' ) ) {
    /**
     * Remove theme styles and scripts from app pages
     */
    function wp_app_dequeue_theme_assets() {
        global $wp_styles, $wp_scripts;

        // Only run on app pages
        if ( ! wp_app_is_app_request() ) {
            return;
        }

        if ( $wp_styles ) {
            // Get all enqueued styles
            $enqueued_styles = $wp_styles->queue;

            foreach ( $enqueued_styles as $handle ) {
                if ( wp_app_should_dequeue_asset( $handle, $wp_styles, 'style' ) ) {
                    wp_dequeue_style( $handle );
                }
            }
        }

        // Also dequeue scripts we don't need (but keep admin bar and debugging tools)
        if ( $wp_scripts ) {
            $enqueued_scripts = $wp_scripts->queue;

            foreach ( $enqueued_scripts as $handle ) {
                if ( wp_app_should_dequeue_asset( $handle, $wp_scripts, 'script' ) ) {
                    wp_dequeue_script( $handle );
                }
            }
        }
    }
}

// Hook app styles and asset isolation when WordPress hooks are available.
if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_app_head', 'wp_app_output_admin_color_scheme', 5 );
	add_action( 'wp_enqueue_scripts', 'wp_app_dequeue_theme_assets', 999 );
	add_action( 'wp_head', 'wp_app_dequeue_theme_assets', 7 );
	add_action( 'wp_footer', 'wp_app_dequeue_theme_assets', 0 );
}

if ( ! function_exists( 'wp_app_get_route_var' ) ) {
    /**
     * Get a route parameter value (WordPress-style global function)
     *
     * @param string $var Parameter name
     * @param mixed $default Default value if parameter doesn't exist
     * @return mixed Parameter value
     */
    function wp_app_get_route_var( $var, $default = '' ) {
        return get_query_var( $var, $default );
    }
}
