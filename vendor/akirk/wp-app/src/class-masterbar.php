<?php

namespace WpApp;

require_once __DIR__ . '/class-settings.php';

if ( class_exists( 'WpApp\Masterbar' ) ) {
    return;
}

/**
 * WordPress-style Masterbar that mimics the WordPress admin bar
 */
class Masterbar {
	private $menu_items                                  = [];
	private $user_menu_items                             = [];
	private $show_wp_logo                                = true;
	private $show_site_name                              = true;
	private $disable_wp_admin_bar                        = true;
	private $only_on_app_routes                          = false;
	private $show_for_anonymous                          = true;
	private $admin_bar_app_link                          = true;
	private $app_url_path                                = null;
	private $wpapp                                       = null;
	private $custom_masterbar_rendered                   = false;
	private static $instances                            = [];
	private static $admin_bar_overflow_hooks_initialized = false;
	private static $admin_bar_overflow_styles_output     = false;
	private static $admin_bar_app_link_styles_output     = false;

    public function __construct( $app_url_path = null, $wpapp = null ) {
		$this->app_url_path = $app_url_path;
		$this->wpapp        = $wpapp;
		self::$instances[ $app_url_path ? $app_url_path : spl_object_hash( $this ) ] = $this;
        self::maybe_initialize_admin_bar_overflow_hooks();

        // Hook into our custom app head action to enqueue styles
        add_action( 'wp_app_head', [ $this, 'output_styles' ] );
        add_action( 'wp_app_head', [ $this, 'output_scripts' ] );

        // Control admin bar display
        add_filter( 'show_admin_bar', [ $this, 'should_show_admin_bar' ] );

        // Add active app items before cross-app links so active submenus stay accessible on mobile.
        add_action( 'admin_bar_menu', [ $this, 'add_wp_admin_bar_app_context_items' ], 998 );
        add_action( 'admin_bar_menu', [ $this, 'add_wp_admin_bar_admin_context_items' ], 999 );

        // Only show on app requests to avoid interfering with regular WordPress
        add_action( 'wp_app_before_render', [ $this, 'setup_for_app_request' ] );

        // Add custom masterbar for logged-out users if WordPress admin bar is not shown
        add_action( 'wp_app_body_open', [ $this, 'render_custom_masterbar_if_needed' ] );
    }

    /**
     * Add a menu item as submenu under the main app link
     *
     * @param string $id Menu item ID
     * @param string $title Menu item title
     * @param string $href Link URL
     * @param array $args Additional arguments
     */
    public function add_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->menu_items[ $id ] = array_merge(
            [
				'id'     => $id,
				'title'  => $title,
				'href'   => $href,
				'class'  => '',
				'target' => '',
				'parent' => 'wp-app-' . str_replace( '-', '_', $this->app_url_path ), // Default to submenu
			],
			$args
        );
    }

    /**
     * Add a top-level menu item (not as submenu)
     *
     * @param string $id Menu item ID
     * @param string $title Menu item title
     * @param string $href Link URL
     * @param array $args Additional arguments
     */
    public function add_top_level_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->menu_items[ $id ] = array_merge(
            [
				'id'     => $id,
				'title'  => $title,
				'href'   => $href,
				'class'  => '',
				'target' => '',
				'parent' => null, // Top-level item
			],
			$args
        );
    }

    /**
     * Add a user menu item
     *
     * @param string $id Menu item ID
     * @param string $title Menu item title
     * @param string $href Link URL
     * @param array $args Additional arguments
     */
    public function add_user_menu_item( $id, $title, $href = '', $args = [] ) {
        $this->user_menu_items[ $id ] = array_merge(
            [
				'id'     => $id,
				'title'  => $title,
				'href'   => $href,
				'class'  => '',
				'target' => '',
			],
			$args
        );
    }

    /**
     * Remove a menu item
     */
    public function remove_menu_item( $id ) {
        unset( $this->menu_items[ $id ] );
    }

    /**
     * Remove a user menu item
     */
    public function remove_user_menu_item( $id ) {
        unset( $this->user_menu_items[ $id ] );
    }

    /**
     * Clear all menu items
     */
    public function clear_all_menu_items() {
        $this->menu_items      = [];
        $this->user_menu_items = [];
    }

    /**
     * Get menu items for admin settings previews.
     *
     * @return array Menu items.
     */
    public function get_preview_menu_items() {
        return $this->menu_items;
    }

    /**
     * Check whether this app's automatic admin bar link is enabled.
     *
     * @return bool True if the automatic app link is enabled.
     */
    public function is_admin_bar_app_link_enabled() {
        return $this->admin_bar_app_link;
    }

    /**
     * Get a masterbar instance for an app path.
     *
     * @param string $app_path App URL path.
     * @return self|null Masterbar instance.
     */
    public static function get_instance_for_app( $app_path ) {
        return isset( self::$instances[ $app_path ] ) && self::$instances[ $app_path ] instanceof self ? self::$instances[ $app_path ] : null;
    }

    /**
     * Remove all WordPress admin bar items and show only app items
     */
    public function remove_all_wp_admin_bar_items() {
        add_action( 'admin_bar_menu', [ $this, 'clear_wp_admin_bar' ], 1 );
    }

    /**
     * Set whether to show masterbar for anonymous users
     *
     * @param bool $show True to show, false to hide for logged-out users
     */
    public function show_for_anonymous( $show = true ) {
        $this->show_for_anonymous = $show;
    }

    /**
     * Set whether to show WordPress logo
     */
    public function show_wp_logo( $show = true ) {
        $this->show_wp_logo = $show;
    }

    /**
     * Set whether to show site name
     */
    public function show_site_name( $show = true ) {
        $this->show_site_name = $show;
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
        $this->admin_bar_app_link = $add;
    }

    /**
     * Determine if admin bar should be shown
     */
    public function should_show_admin_bar( $show ) {
        // Only control admin bar display on app requests
        if ( ! $this->is_app_request() ) {
            return $show;
        }

        // For logged-in users, always show admin bar
        if ( is_user_logged_in() ) {
            return true;
        }

        // For anonymous users, show admin bar only if configured to do so
        return $this->show_for_anonymous;
    }

    /**
     * Render custom masterbar if needed (for anonymous users when admin bar is hidden)
     */
    public function render_custom_masterbar_if_needed() {
        // Only render on app requests
        if ( ! $this->is_app_request() ) {
            return;
        }

        if ( $this->custom_masterbar_rendered ) {
            return;
        }

        // If admin bar is showing or we shouldn't show for anonymous, don't render custom
        if ( is_admin_bar_showing() || ( ! is_user_logged_in() && ! $this->show_for_anonymous ) ) {
            return;
        }

        // Add body class for custom masterbar styling
        add_filter(
            'body_class',
            function ( $classes ) {
				$classes[] = 'wp-app-has-custom-masterbar';
				return $classes;
			}
        );

        // Render our custom masterbar
        $this->custom_masterbar_rendered = true;
        echo $this->render_custom_masterbar();
    }

    /**
     * Initialize mobile admin bar overflow hooks once for all WpApp instances.
     */
    private static function maybe_initialize_admin_bar_overflow_hooks() {
        if ( self::$admin_bar_overflow_hooks_initialized ) {
            return;
        }

        add_action( 'admin_bar_menu', [ __CLASS__, 'add_admin_bar_overflow_menu' ], 1000 );
        add_action( 'wp_head', [ __CLASS__, 'output_admin_bar_overflow_styles' ], 100 );
        add_action( 'admin_head', [ __CLASS__, 'output_admin_bar_overflow_styles' ], 100 );
        add_action( 'wp_app_head', [ __CLASS__, 'output_admin_bar_overflow_styles' ], 100 );
        add_action( 'wp_head', [ __CLASS__, 'output_admin_bar_app_link_styles' ], 99 );
        add_action( 'admin_head', [ __CLASS__, 'output_admin_bar_app_link_styles' ], 99 );
        add_action( 'wp_app_head', [ __CLASS__, 'output_admin_bar_app_link_styles' ], 99 );

        self::$admin_bar_overflow_hooks_initialized = true;
    }

    /**
     * Add a single mobile overflow menu for app links emitted outside app context.
     */
    public static function add_admin_bar_overflow_menu( $wp_admin_bar ) {
        $links     = self::get_admin_bar_overflow_links();
        $is_sticky = self::should_show_admin_bar_overflow_as_top_level();

        if ( empty( $links ) ) {
            return;
        }

        $wp_admin_bar->add_node(
            [
                'id'    => 'wp-app-admin-overflow',
                'title' => '<span class="ab-icon"></span><span class="screen-reader-text">' . esc_html__( 'Apps' ) . '</span>',
                'meta'  => [
                    'class' => 'wp-app-admin-overflow' . ( $is_sticky ? ' wp-app-admin-overflow-sticky' : '' ),
                    'title' => __( 'Apps' ),
                ],
            ]
        );

        foreach ( $links as $link ) {
            $wp_admin_bar->add_node(
                [
                    'id'     => $link['id'],
                    'parent' => 'wp-app-admin-overflow',
                    'title'  => $link['title'],
                    'href'   => $link['href'],
                    'meta'   => [
                        'class' => 'wp-app-admin-overflow-link',
                    ],
                ]
            );
        }

        if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
            $wp_admin_bar->add_node(
                [
                    'id'     => 'wp-app-admin-overflow-settings',
                    'parent' => 'wp-app-admin-overflow',
                    'title'  => __( 'WP Apps Settings' ),
                    'href'   => function_exists( 'admin_url' ) ? admin_url( 'options-general.php?page=wp-apps' ) : home_url( '/wp-admin/options-general.php?page=wp-apps' ),
                    'meta'   => [
                        'class' => 'wp-app-admin-overflow-settings',
                    ],
                ]
            );
        }
    }

    /**
     * Output CSS that collapses app admin links into the overflow menu on mobile.
     */
    public static function output_admin_bar_overflow_styles() {
        if ( self::$admin_bar_overflow_styles_output ) {
            return;
        }

        if ( function_exists( 'is_admin_bar_showing' ) && ! is_admin_bar_showing() ) {
            return;
        }

        if ( empty( self::get_admin_bar_overflow_links() ) ) {
            return;
        }

        self::$admin_bar_overflow_styles_output = true;

        echo '<style id="wp-app-admin-bar-overflow-styles">';
        echo self::get_admin_bar_overflow_styles();
        echo '</style>';
    }

    /**
     * Output shared CSS for app links in the real WordPress admin bar.
     */
    public static function output_admin_bar_app_link_styles() {
        if ( self::$admin_bar_app_link_styles_output ) {
            return;
        }

        if ( function_exists( 'is_admin_bar_showing' ) && ! is_admin_bar_showing() ) {
            return;
        }

        self::$admin_bar_app_link_styles_output = true;

        echo '<style id="wp-app-admin-bar-app-link-styles">';
        echo self::get_app_link_styles( '#wpadminbar' );
        echo '</style>';
    }

    /**
     * Get the app links that should be collapsed into the mobile overflow menu.
     */
    private static function get_admin_bar_overflow_links() {
        $links                          = [];
        $current_app_path               = self::get_current_app_url_path();
        $show_inactive_apps_in_overflow = \WpApp\Settings::should_show_inactive_apps_in_overflow();
        $registered_apps                = \WpApp\Settings::get_registered_apps();

        foreach ( $registered_apps as $app_path => $metadata ) {
            if ( ! is_string( $app_path ) || '' === $app_path || ! is_array( $metadata ) ) {
                continue;
            }

            if ( $current_app_path === $app_path ) {
                continue;
            }

            if ( class_exists( __NAMESPACE__ . '\Registry' ) && ! \WpApp\Registry::can_user_access_app( $app_path ) ) {
                continue;
            }

            $masterbar = self::get_instance_for_app( $app_path );

            if ( $masterbar && ! $masterbar->admin_bar_app_link ) {
                continue;
            }

            if ( $masterbar && ! $masterbar->should_show_app_link_content() ) {
                continue;
            }

            if ( ! $masterbar && ! self::should_show_app_link_content_for_app( $app_path, $metadata ) ) {
                continue;
            }

            $should_show_global_app_link = $masterbar ? $masterbar->should_show_global_app_link() : \WpApp\Settings::should_show_global_app_link( $app_path );

            if ( $should_show_global_app_link ) {
                continue;
            }

            if ( ! $show_inactive_apps_in_overflow ) {
                continue;
            }

            $links[ $app_path ] = self::get_admin_bar_overflow_link( $app_path, $metadata );
        }

        return apply_filters( 'wp_app_admin_bar_overflow_links', $links );
    }

    /**
     * Build one overflow link record.
     *
     * @param string $app_path App URL path.
     * @param array  $metadata App metadata.
     * @return array Overflow link.
     */
    private static function get_admin_bar_overflow_link( $app_path, $metadata ) {
        $id_base = preg_replace( '/[^A-Za-z0-9_-]/', '_', $app_path );

        return [
            'id'    => 'wp-app-admin-overflow-' . $id_base,
            'title' => self::get_app_link_title_for_app( $app_path, $metadata, null, true ),
            'href'  => isset( $metadata['url'] ) && $metadata['url'] ? $metadata['url'] : home_url( '/' . $app_path ),
        ];
    }

    /**
     * Check if the overflow menu should be visible outside the mobile breakpoint.
     */
    private static function should_show_admin_bar_overflow_as_top_level() {
        return \WpApp\Settings::should_show_inactive_apps_in_overflow();
    }

    /**
     * Get the app path for the current app request.
     */
    private static function get_current_app_url_path() {
        global $wp_query;

        if ( $wp_query && isset( $wp_query->query_vars['wp_app_path'] ) ) {
            $app_path = get_query_var( 'wp_app_path' );

            if ( is_string( $app_path ) && '' !== $app_path ) {
                return $app_path;
            }
        }

        foreach ( self::$instances as $masterbar ) {
            if ( $masterbar instanceof self && $masterbar->is_app_request() ) {
                return $masterbar->app_url_path;
            }
        }

        return null;
    }

    /**
     * Get mobile overflow CSS for WordPress admin bar app links.
     */
    private static function get_admin_bar_overflow_styles() {
        return '
            #wpadminbar {
                z-index: 100100;
            }

            #wpadminbar .ab-sub-wrapper {
                z-index: 100101;
            }

            #wpadminbar li#wp-admin-bar-wp-app-admin-overflow.wp-app-admin-overflow-sticky > .ab-item {
                color: #a7aaad;
                cursor: pointer;
                overflow: hidden;
                padding: 0;
                position: relative;
                text-indent: 100%;
                white-space: nowrap;
                width: 32px;
            }

            #wpadminbar li#wp-admin-bar-wp-app-admin-overflow.wp-app-admin-overflow-sticky > .ab-item .ab-icon {
                height: 32px;
                margin: 0;
                padding: 0;
                width: 32px;
            }

            #wpadminbar li#wp-admin-bar-wp-app-admin-overflow > .ab-item .ab-icon:before {
                content: "\f347";
                display: block;
                font: normal 20px/1 dashicons;
                height: 32px;
                line-height: 27px;
                text-align: center;
                text-indent: 0;
                top: 0;
                width: 32px;
            }

            #wpadminbar li#wp-admin-bar-wp-app-admin-overflow-settings {
                border-top: 1px solid rgba(255, 255, 255, 0.16);
                margin-top: 4px;
                padding-top: 4px;
            }

            @media screen and (max-width: 782px) {
                #wpadminbar li.wp-app-admin-link {
                    display: none !important;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow {
                    display: block !important;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow > .ab-item {
                    color: #a7aaad;
                    overflow: hidden;
                    padding: 0;
                    position: relative;
                    text-indent: 100%;
                    white-space: nowrap;
                    width: 52px;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow > .ab-item .ab-icon {
                    height: 46px;
                    margin: 0;
                    padding: 0;
                    width: 52px;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow > .ab-item .ab-icon:before {
                    font: normal 32px/1 dashicons;
                    height: 46px;
                    line-height: 46px;
                    width: 52px;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow .ab-sub-wrapper {
                    left: auto;
                    max-height: calc(100vh - 54px);
                    max-width: calc(100vw - 16px);
                    overflow-y: auto;
                    right: 0;
                    width: max-content;
                }

                #wpadminbar li#wp-admin-bar-wp-app-admin-overflow .ab-submenu .ab-item {
                    box-sizing: border-box;
                    display: block;
                    height: auto !important;
                    line-height: 22px !important;
                    max-width: calc(100vw - 16px);
                    min-height: 44px;
                    overflow: visible;
                    padding-bottom: 10px;
                    padding-top: 10px;
                    text-overflow: ellipsis;
                    white-space: normal;
                    word-break: break-word;
                }
            }
        ';
    }

    /**
     * Render custom masterbar for anonymous users
     */
    private function render_custom_masterbar() {
        $current_user = wp_get_current_user();
        $is_logged_in = is_user_logged_in();

        ob_start();
        ?>
        <div id="wp-app-custom-masterbar" class="wp-app-masterbar">
            <div class="wp-app-masterbar-inner">
                <div class="wp-app-masterbar-left">
                    <?php if ( $this->show_wp_logo ) : ?>
                        <div class="wp-app-masterbar-logo">
                            <a href="<?php echo esc_url( home_url() ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                                <span class="wp-app-wp-logo">WordPress</span>
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ( $this->show_site_name ) : ?>
                        <div class="wp-app-masterbar-site">
                            <a href="<?php echo esc_url( home_url() ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
                        </div>
                    <?php endif; ?>

                    <div class="wp-app-masterbar-menu">
                        <?php $this->render_menu_items(); ?>
                    </div>
                </div>

                <div class="wp-app-masterbar-right">
                    <?php if ( $is_logged_in ) : ?>
                        <div class="wp-app-masterbar-user">
                            <a href="#" class="wp-app-user-toggle">
                                <?php echo get_avatar( $current_user->ID, 24 ); ?>
                                <span class="wp-app-user-name"><?php echo esc_html( $current_user->display_name ); ?></span>
                            </a>
                            <div class="wp-app-user-menu">
                                <a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php _e( 'Edit Profile' ); ?></a>
                                <a href="<?php echo esc_url( admin_url() ); ?>"><?php _e( 'Dashboard' ); ?></a>
                                <?php $this->render_user_menu_items(); ?>
                                <a href="<?php echo esc_url( wp_logout_url() ); ?>"><?php _e( 'Log Out' ); ?></a>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="wp-app-masterbar-login">
                            <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php _e( 'Log In' ); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the fake masterbar (legacy method, kept for backwards compatibility)
     */
    public function render() {
        return $this->render_custom_masterbar();
    }


    /**
     * Render menu items (only if user can access this app)
     */
    private function render_menu_items() {
        // Only show menu items if user can access this app
        if ( ! $this->can_user_access_app() ) {
            return;
        }

        foreach ( $this->menu_items as $item ) {
            $class  = ! empty( $item['class'] ) ? ' class="' . esc_attr( $item['class'] ) . '"' : '';
            $target = ! empty( $item['target'] ) ? ' target="' . esc_attr( $item['target'] ) . '"' : '';

            echo '<div class="wp-app-masterbar-item">';
            if ( ! empty( $item['href'] ) ) {
                echo '<a href="' . esc_url( $item['href'] ) . '"' . $class . $target . '>' . esc_html( $item['title'] ) . '</a>';
            } else {
                echo '<span' . $class . '>' . esc_html( $item['title'] ) . '</span>';
            }
            echo '</div>';
        }
    }

    /**
     * Render user menu items
     */
    private function render_user_menu_items() {
        foreach ( $this->user_menu_items as $item ) {
            $class  = ! empty( $item['class'] ) ? ' class="' . esc_attr( $item['class'] ) . '"' : '';
            $target = ! empty( $item['target'] ) ? ' target="' . esc_attr( $item['target'] ) . '"' : '';

            if ( ! empty( $item['href'] ) ) {
                echo '<a href="' . esc_url( $item['href'] ) . '"' . $class . $target . '>' . esc_html( $item['title'] ) . '</a>';
            } else {
                echo '<span' . $class . '>' . esc_html( $item['title'] ) . '</span>';
            }
        }
    }

    /**
     * Output styles for the masterbar
     */
    public function output_styles() {
        echo '<style id="wp-app-masterbar-styles">';
        echo $this->get_default_styles();

        // Allow other plugins/themes to add masterbar styles
        do_action( 'wp_app_masterbar_styles' );

        echo '</style>';
    }

    /**
     * Output scripts for the masterbar
     */
    public function output_scripts() {
        echo '<script id="wp-app-masterbar-scripts">';
        echo $this->get_default_scripts();

        // Allow other plugins/themes to add masterbar scripts
        do_action( 'wp_app_masterbar_scripts' );

        echo '</script>';
    }

    /**
     * Get default CSS styles for the masterbar
     */
    private function get_default_styles() {
        return '
            /* App-specific admin bar styling */
            #wpadminbar {
                background: var(--wp-app-masterbar-background);
                z-index: 100100;
            }

            #wpadminbar .ab-sub-wrapper {
                z-index: 100101;
            }

            #wpadminbar .ab-top-menu > li.hover > .ab-item,
            #wpadminbar.nojq .quicklinks .ab-top-menu > li > .ab-item:focus,
            #wpadminbar:not(.mobile) .ab-top-menu > li:hover > .ab-item,
            #wpadminbar:not(.mobile) .ab-top-menu > li > .ab-item:focus {
                background: var(--wp-app-admin-color-subtle);
                color: var(--wp-app-masterbar-highlight);
            }

            #wpadminbar .menupop .ab-sub-wrapper,
            #wpadminbar .shortlink-input {
                background: var(--wp-app-masterbar-background);
            }

            #wpadminbar .quicklinks .menupop ul.ab-sub-secondary,
            #wpadminbar .quicklinks .menupop ul.ab-sub-secondary .ab-submenu {
                background: var(--wp-app-admin-color-subtle);
            }

            #wpadminbar .quicklinks .menupop ul li a:hover,
            #wpadminbar .quicklinks .menupop ul li a:focus,
            #wpadminbar .quicklinks .menupop.hover ul li a:hover,
            #wpadminbar .quicklinks .menupop.hover ul li a:focus {
                color: var(--wp-app-masterbar-highlight);
            }

            /* App menu items spacing and positioning */
            .wp-app-menu-item > .ab-item {
                margin-left: 15px;
            }

            .wp-app-menu-item:hover > .ab-item,
            .wp-app-menu-item.hover > .ab-item {
                color: var(--wp-app-masterbar-highlight) !important;
            }

            /* Custom app user menu items styling */
            .wp-app-user-menu-item > .ab-item {
                color: var(--wp-app-masterbar-highlight) !important;
            }

            /* App-specific body margin (WordPress admin bar is 32px) */
            body.wp-app-body {
                margin-top: 32px !important;
            }

            /* Responsive admin bar for mobile */
            @media screen and (max-width: 782px) {
                body.wp-app-body {
                    margin-top: 46px !important;
                }

                #wpadminbar {
                    position: fixed;
                }

                /* WordPress hides root-default items on mobile; keep app items visible */
                #wpadminbar li.wp-app-main-menu-item,
                #wpadminbar li.wp-app-menu-item {
                    display: block;
                }

                #wpadminbar li.wp-app-main-menu-item .ab-sub-wrapper,
                #wpadminbar li.wp-app-menu-item .ab-sub-wrapper {
                    left: auto;
                    max-height: calc(100vh - 54px);
                    max-width: calc(100vw - 16px);
                    overflow-y: auto;
                    right: 0;
                    width: max-content;
                }

                #wpadminbar li.wp-app-main-menu-item .ab-submenu .ab-item,
                #wpadminbar li.wp-app-menu-item .ab-submenu .ab-item {
                    box-sizing: border-box;
                    display: block;
                    height: auto !important;
                    line-height: 22px !important;
                    max-width: calc(100vw - 16px);
                    min-height: 44px;
                    overflow: visible;
                    padding-bottom: 10px;
                    padding-top: 10px;
                    text-overflow: ellipsis;
                    white-space: normal;
                    word-break: break-word;
                }

                /* WordPress zeroes padding on all root-default links at mobile; restore it */
                #wpadminbar li.wp-app-main-menu-item > a.ab-item,
                #wpadminbar li.wp-app-menu-item > a.ab-item {
                    margin-left: 0;
                    padding: 0 8px;
                }
            }

            /* Custom masterbar for anonymous users */
            .wp-app-masterbar {
                background: var(--wp-app-masterbar-background);
                height: 32px;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                z-index: 100100;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 13px;
                line-height: 32px;
                color: var(--wp-app-masterbar-text);
            }

            .wp-app-masterbar .wp-app-masterbar-inner {
                max-width: 1200px;
                margin: 0 auto;
                display: flex;
                justify-content: space-between;
                align-items: center;
                height: 100%;
                padding: 0 20px;
            }

            .wp-app-masterbar .wp-app-masterbar-left,
            .wp-app-masterbar .wp-app-masterbar-right {
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .wp-app-masterbar a {
                color: var(--wp-app-masterbar-text);
                text-decoration: none;
            }

            .wp-app-masterbar a:hover {
                color: var(--wp-app-masterbar-highlight);
            }

            .wp-app-masterbar .wp-app-masterbar-item {
                display: inline-block;
            }

            .wp-app-masterbar .wp-app-wp-logo {
                font-weight: 600;
            }

            .wp-app-masterbar .wp-app-masterbar-login {
                background: var(--wp-app-color-primary);
                padding: 4px 10px;
                border-radius: 3px;
                white-space: nowrap;
                font-size: 12px;
            }

            .wp-app-masterbar .wp-app-masterbar-login:hover {
                background: var(--wp-app-color-primary-hover);
            }

            .wp-app-masterbar .wp-app-masterbar-login a {
                color: var(--wp-app-color-on-primary) !important;
            }

            .wp-app-masterbar .wp-app-masterbar-login a:hover {
                color: var(--wp-app-color-on-primary) !important;
            }

            /* Body margin when custom masterbar is shown */
            body.wp-app-has-custom-masterbar {
                margin-top: 32px !important;
            }

            /* Default body margin for apps without masterbar */
            body.wp-app-body:not(.wp-app-has-custom-masterbar):not(.admin-bar) {
                margin: 40px 20px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                line-height: 1.6;
            }

            /* Responsive custom masterbar */
            @media screen and (max-width: 782px) {
                .wp-app-masterbar {
                    height: 46px;
                    line-height: 46px;
                    overflow: hidden;
                }

                body.wp-app-has-custom-masterbar {
                    margin-top: 46px !important;
                }

                .wp-app-masterbar .wp-app-masterbar-inner {
                    gap: 8px;
                    height: 100%;
                    max-width: none;
                    overflow-x: auto;
                    overflow-y: hidden;
                    padding: 0 8px;
                    scrollbar-width: none;
                }

                .wp-app-masterbar .wp-app-masterbar-inner::-webkit-scrollbar {
                    display: none;
                }

                .wp-app-masterbar .wp-app-masterbar-left {
                    flex: 1 1 auto;
                    gap: 8px;
                    min-width: 0;
                    overflow-x: auto;
                    overflow-y: hidden;
                    scrollbar-width: none;
                }

                .wp-app-masterbar .wp-app-masterbar-left::-webkit-scrollbar {
                    display: none;
                }

                .wp-app-masterbar .wp-app-masterbar-menu {
                    display: flex;
                    gap: 8px;
                    white-space: nowrap;
                }

                .wp-app-masterbar .wp-app-masterbar-right {
                    flex: 0 0 auto;
                    gap: 8px;
                }

                .wp-app-masterbar .wp-app-masterbar-site {
                    min-width: 0;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }
            }
        ';
    }

    /**
     * Get shared app-link title/icon CSS.
     *
     * @param string $selector Root selector.
     * @return string CSS.
     */
    public static function get_app_link_styles( $selector = '#wpadminbar' ) {
        $selector = preg_replace( '/[^a-zA-Z0-9\-_#\.\:\[\]=~\*"\'\(\), >\+]/', '', $selector );

        if ( '' === trim( $selector ) ) {
            $selector = '#wpadminbar';
        }

        return '
            ' . $selector . ' .wp-app-link-title {
                align-items: center;
                display: inline-flex;
                gap: 6px;
                height: 100%;
                line-height: inherit;
                vertical-align: baseline;
            }

            ' . $selector . ' .wp-app-link-icon {
                align-items: center;
                color: var(--wp-app-masterbar-text);
                display: inline-flex;
                font-size: 11px;
                font-weight: 600;
                height: 18px;
                justify-content: center;
                line-height: 18px;
                overflow: hidden;
                text-transform: uppercase;
                width: 18px;
            }

            ' . $selector . ' .wp-app-link-icon.wp-app-link-icon-generated {
                background: var(--wp-app-admin-color-subtle);
                border-radius: 3px;
            }

            ' . $selector . ' .wp-app-link-icon img {
                display: block;
                height: 18px;
                max-height: 18px;
                max-width: 18px;
                object-fit: contain;
                width: 18px;
            }

            ' . $selector . ' .wp-app-link-icon .dashicons {
                font-family: dashicons !important;
                font-size: 16px;
                font-style: normal;
                font-weight: 400;
                height: 16px;
                line-height: 16px;
                text-transform: none;
                width: 16px;
            }

            ' . $selector . ' .wp-app-link-icon .dashicons:before {
                font-family: dashicons !important;
            }
        ';
    }

    /**
     * Get default JavaScript for the masterbar
     */
    private function get_default_scripts() {
        return '
            // Simple dropdown toggle for user menu
            document.addEventListener("DOMContentLoaded", function() {
                const userToggle = document.querySelector(".wp-app-user-toggle");
                const userMenu = document.querySelector(".wp-app-user-menu");

                if (userToggle && userMenu) {
                    userToggle.addEventListener("click", function(e) {
                        e.preventDefault();
                        userMenu.style.display = userMenu.style.display === "block" ? "none" : "block";
                    });

                    // Close menu when clicking outside
                    document.addEventListener("click", function(e) {
                        if (!e.target.closest(".wp-app-masterbar-user")) {
                            userMenu.style.display = "none";
                        }
                    });
                }
            });
        ';
    }

    /**
     * Output masterbar automatically
     */
    public function auto_render() {
        // Use WordPress admin bar on app requests instead of custom rendering
        // The admin bar will be automatically shown and customized via hooks

        // Fallback: render custom masterbar if WordPress admin bar is disabled
        add_action( 'wp_app_body_open', [ $this, 'maybe_render_fallback' ] );
    }

    /**
     * Render fallback masterbar if WordPress admin bar is not shown
     */
    public function maybe_render_fallback() {
        if ( ! $this->is_app_request() || $this->custom_masterbar_rendered ) {
            return;
        }

        if ( is_admin_bar_showing() || ( ! is_user_logged_in() && ! $this->show_for_anonymous ) ) {
            return;
        }

        $this->custom_masterbar_rendered = true;
        echo $this->render();
    }

    /**
     * Setup admin bar for app requests
     */
    public function setup_for_app_request() {
        // Add app-specific CSS classes to body
        add_filter(
            'body_class',
            function ( $classes ) {
				$classes[] = 'wp-app-with-admin-bar';
				return $classes;
			}
        );

        // Enqueue WordPress admin bar styles - handled in wp_app_head() function now
    }

    /**
     * Add custom items to WordPress admin bar
     */
    public function add_wp_admin_bar_items( $wp_admin_bar ) {
        // Always add app link, but show different items based on context
        if ( $this->is_app_request() ) {
            $this->add_app_context_items( $wp_admin_bar );
        } else {
            $this->add_admin_context_items( $wp_admin_bar );
        }
    }

    /**
     * Add the active app's items before global app links.
     */
    public function add_wp_admin_bar_app_context_items( $wp_admin_bar ) {
        if ( $this->is_app_request() ) {
            $this->add_app_context_items( $wp_admin_bar );
        }
    }

    /**
     * Add app links outside their active app context.
     */
    public function add_wp_admin_bar_admin_context_items( $wp_admin_bar ) {
        if ( ! $this->is_app_request() ) {
            $this->add_admin_context_items( $wp_admin_bar );
        }
    }

    /**
     * Clear all WordPress admin bar items (called when remove_all_wp_admin_bar_items is used)
     */
    public function clear_wp_admin_bar( $wp_admin_bar ) {
        // Remove all WordPress default admin bar items
        $all_wp_items = [
            'wp-logo',              // WordPress logo
            'site-name',            // Site name
            'updates',              // Updates
            'comments',             // Comments
            'new-content',          // New content menu
            'edit',                 // Edit this page
            'search',               // Search
            'my-account',           // User account menu
            'customize',            // Customize
            'themes',               // Themes
            'widgets',              // Widgets
            'menus',                // Menus
            'background',           // Background
            'header',               // Header
            'site-editor',          // Site Editor
            'view-site',            // View Site
            'archive',              // Archive
            'dashboard',             // Dashboard
        ];

        foreach ( $all_wp_items as $item_id ) {
            $wp_admin_bar->remove_node( $item_id );
        }
    }

    /**
     * Add items when on app pages
     */
    private function add_app_context_items( $wp_admin_bar ) {

        // Remove WordPress default items that aren't needed in app context
        $items_to_remove = apply_filters(
            'wp_app_admin_bar_remove_items',
            [
				'new-content',          // "New" menu
				'comments',             // Comments
				'updates',              // Updates notification
				'site-editor',          // Site Editor (if using block theme)
			]
        );

        foreach ( $items_to_remove as $item_id ) {
            $wp_admin_bar->remove_node( $item_id );
        }

        // Only add items if user can access this app
        if ( $this->can_user_access_app() ) {
            // Add main app link first (unless disabled)
            if ( $this->admin_bar_app_link && $this->should_show_app_link_content() ) {
                $app_node_id = 'wp-app-' . str_replace( '-', '_', $this->app_url_path );
                $wp_admin_bar->add_node(
                    [
						'id'    => $app_node_id,
						'title' => $this->get_app_link_title(),
						'href'  => $this->get_app_home_url(),
						'meta'  => [
							'class' => 'wp-app-main-menu-item',
						],
					]
                );
            }

            // Add custom menu items (as submenus by default, or top-level if parent is null)
            foreach ( $this->menu_items as $item ) {
                $wp_admin_bar->add_node(
                    [
						'id'     => $item['id'],
						'parent' => $item['parent'],
						'title'  => $item['title'],
						'href'   => $item['href'],
						'meta'   => [
							'class'  => 'wp-app-menu-item ' . $item['class'],
							'target' => $item['target'],
						],
					]
                );
            }
        }

        // Add user menu items as submenu to existing user menu
        if ( is_user_logged_in() ) {
            foreach ( $this->user_menu_items as $item ) {
                $wp_admin_bar->add_node(
                    [
						'id'     => $item['id'],
						'parent' => 'user-actions',
						'title'  => $item['title'],
						'href'   => $item['href'],
						'meta'   => [
							'class'  => 'wp-app-user-menu-item ' . $item['class'],
							'target' => $item['target'],
						],
					]
                );
            }
        }

        // Allow other plugins to add items via action
        do_action( 'wp_app_admin_bar_menu', $wp_admin_bar );
    }

    /**
     * Add items when in regular WordPress admin/frontend
     */
    private function add_admin_context_items( $wp_admin_bar ) {
        // Only add link if user can access this app and the app link is enabled
        if ( $this->can_user_access_app() && $this->admin_bar_app_link && ! $this->should_show_app_link_in_overflow_only() && $this->should_show_global_app_link() && $this->should_show_app_link_content() ) {
            $app_node_id = $this->get_admin_bar_app_link_id();

            // Add a simple link to the app from regular WordPress admin
            $wp_admin_bar->add_node(
                [
					'id'    => $app_node_id,
					'title' => $this->get_app_link_title(),
					'href'  => $this->get_app_home_url(),
					'meta'  => [
						'class' => ( ! empty( $this->menu_items ) ? 'menupop ' : '' ) . 'wp-app-admin-link',
					],
				]
            );

            foreach ( $this->menu_items as $item ) {
                $wp_admin_bar->add_node(
                    [
						'id'     => $this->get_admin_context_menu_item_id( $item['id'] ),
						'parent' => $this->get_admin_context_menu_item_parent( $item['parent'], $app_node_id ),
						'title'  => $item['title'],
						'href'   => $item['href'],
						'meta'   => [
							'class'  => 'wp-app-menu-item ' . $item['class'],
							'target' => $item['target'],
						],
					]
                );
            }
        }
    }

    /**
     * Get the top-level admin bar node ID for the inactive global app link.
     */
    private function get_admin_bar_app_link_id() {
        return 'wp-app-link-' . str_replace( '-', '_', $this->app_url_path );
    }

    /**
     * Get an app-scoped admin context submenu node ID.
     *
     * @param string $item_id Menu item ID.
     * @return string Scoped admin bar node ID.
     */
    private function get_admin_context_menu_item_id( $item_id ) {
        return $this->get_admin_bar_app_link_id() . '-' . $item_id;
    }

    /**
     * Remap menu item parents for inactive global app dropdowns.
     *
     * @param string|null $parent Parent menu item ID.
     * @param string      $app_node_id Inactive global app link node ID.
     * @return string|null Remapped parent menu item ID.
     */
    private function get_admin_context_menu_item_parent( $parent, $app_node_id ) {
        $active_app_node_id = 'wp-app-' . str_replace( '-', '_', $this->app_url_path );

        if ( null === $parent || $active_app_node_id === $parent ) {
            return $app_node_id;
        }

        return $this->get_admin_context_menu_item_id( $parent );
    }

    /**
     * Get app name for display
     */
    private function get_app_name() {
        if ( $this->wpapp && method_exists( $this->wpapp, 'get_app_name' ) ) {
            return $this->wpapp->get_app_name();
        }

        // Fallback: Use this masterbar's specific app path to generate the name
        if ( $this->app_url_path ) {
            $name = str_replace( [ '-', '_' ], ' ', $this->app_url_path );
            return ucwords( $name );
        }

        return 'App';
    }

    /**
     * Get metadata for this app.
     */
    private function get_app_metadata() {
        $metadata = \WpApp\Settings::get_registered_apps();

        if ( isset( $metadata[ $this->app_url_path ] ) && is_array( $metadata[ $this->app_url_path ] ) ) {
            return $metadata[ $this->app_url_path ];
        }

        return [
            'name'     => $this->get_app_name(),
            'url'      => $this->get_app_home_url(),
            'icon_url' => null,
            'dashicon' => null,
        ];
    }

    /**
     * Get HTML title for app admin bar links.
     */
    private function get_app_link_title() {
        return self::get_app_link_title_for_app( $this->app_url_path, $this->get_app_metadata(), $this->get_app_display_name(), $this->is_app_request() );
    }

    /**
     * Get HTML title for a registered app admin bar link.
     *
     * @param string      $app_path App URL path.
     * @param array       $metadata App metadata.
     * @param string|null $fallback_name Optional fallback display name.
     * @param bool        $force_show_text Whether to force visible text regardless of app settings.
     * @return string Link title HTML.
     */
    private static function get_app_link_title_for_app( $app_path, $metadata, $fallback_name = null, $force_show_text = false ) {
        $settings = \WpApp\Settings::get_app_settings( $app_path );
        $app_name = self::get_app_display_name_for_app( $app_path, $metadata, $fallback_name );
        $title    = '<span class="wp-app-link-title">';

        if ( ! empty( $settings['show_icon'] ) ) {
            $icon_url = isset( $metadata['icon_url'] ) ? $metadata['icon_url'] : '';
            $dashicon = isset( $metadata['dashicon'] ) ? $metadata['dashicon'] : '';
            $icon     = isset( $settings['icon'] ) ? trim( $settings['icon'] ) : '';

            if ( '' !== $icon ) {
                $title .= self::get_app_icon_html( $icon );
            } elseif ( $dashicon ) {
                $title .= self::get_app_icon_html( $dashicon );
            } elseif ( $icon_url ) {
                $title .= self::get_app_image_icon_html( $icon_url );
            } else {
                $letter = strtoupper( substr( $app_name, 0, 1 ) );
                $title .= '<span class="wp-app-link-icon wp-app-link-icon-generated" aria-hidden="true">' . esc_html( $letter ) . '</span>';
            }
        }

        if ( $force_show_text || ! empty( $settings['show_text'] ) ) {
            $title .= '<span class="wp-app-link-text">' . esc_html( $app_name ) . '</span>';
        } else {
            $title .= '<span class="screen-reader-text">' . esc_html( $app_name ) . '</span>';
        }

        $title .= '</span>';

        return $title;
    }

    /**
     * Get display title, including admin override when configured.
     */
    private function get_app_display_name() {
        return self::get_app_display_name_for_app( $this->app_url_path, $this->get_app_metadata(), $this->get_app_name() );
    }

    /**
     * Get display title for an app path, including admin override when configured.
     *
     * @param string      $app_path App URL path.
     * @param array       $metadata App metadata.
     * @param string|null $fallback_name Optional fallback display name.
     * @return string App display name.
     */
    private static function get_app_display_name_for_app( $app_path, $metadata, $fallback_name = null ) {
        $settings = \WpApp\Settings::get_app_settings( $app_path );
        $title    = isset( $settings['title'] ) ? trim( $settings['title'] ) : '';

        if ( '' !== $title ) {
            return $title;
        }

        if ( null !== $fallback_name ) {
            return $fallback_name;
        }

        if ( isset( $metadata['name'] ) && is_string( $metadata['name'] ) && '' !== trim( $metadata['name'] ) ) {
            return trim( $metadata['name'] );
        }

        return ucwords( str_replace( [ '-', '_' ], ' ', $app_path ) );
    }

    /**
     * Get icon HTML for a text or Dashicon class override.
     */
    private static function get_app_icon_html( $icon ) {
        if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
            return '<span class="wp-app-link-icon wp-app-link-icon-dashicon" aria-hidden="true"><span class="dashicons ' . esc_attr( $icon ) . '"></span></span>';
        }

        return '<span class="wp-app-link-icon wp-app-link-icon-generated" aria-hidden="true">' . esc_html( $icon ) . '</span>';
    }

    /**
     * Get icon HTML for an image URL.
     *
     * @param string $icon_url Icon URL.
     * @return string Icon HTML.
     */
    private static function get_app_image_icon_html( $icon_url ) {
        return '<span class="wp-app-link-icon wp-app-link-icon-image"><img src="' . esc_url( $icon_url ) . '" alt="" decoding="async"></span>';
    }

    /**
     * Check if this is an app request
     */
    private function is_app_request() {
        global $wp_query;

        // Check if this is an app request at all
        if ( ! $wp_query || ! isset( $wp_query->query_vars['wp_app_request'] ) || ! isset( $wp_query->query_vars['wp_app_path'] ) ) {
            return false;
        }

        // Check if this request is for our specific app
        $app_path = get_query_var( 'wp_app_path' );
        return $app_path === $this->app_url_path;
    }

    /**
     * Check if current user has capability to access this app
     */
    private function can_user_access_app() {
        return \WpApp\Registry::can_user_access_app( $this->app_url_path );
    }

    /**
     * Check if this app should be shown outside its active app context.
     */
    private function should_show_global_app_link() {
        return \WpApp\Settings::should_show_global_app_link( $this->app_url_path );
    }

    /**
     * Check if this inactive app should be represented only in the overflow menu.
     */
    private function should_show_app_link_in_overflow_only() {
        $current_app_path = self::get_current_app_url_path();

        return $current_app_path && $current_app_path !== $this->app_url_path && \WpApp\Settings::should_show_inactive_apps_in_overflow() && ! $this->should_show_global_app_link();
    }

    /**
     * Check if app link settings leave visible content for the link.
     */
    private function should_show_app_link_content() {
        return self::should_show_app_link_content_for_app( $this->app_url_path, $this->get_app_metadata() );
    }

    /**
     * Check if app link settings leave visible content for an app link.
     *
     * @param string $app_path App URL path.
     * @param array  $metadata App metadata.
     * @return bool True when there is visible app link content.
     */
    private static function should_show_app_link_content_for_app( $app_path, $metadata ) {
        $settings = \WpApp\Settings::get_app_settings( $app_path );

        if ( ! empty( $settings['show_text'] ) ) {
            return true;
        }

        if ( empty( $settings['show_icon'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get app home URL
     */
    private function get_app_home_url() {
        return home_url( '/' . $this->app_url_path );
    }

    /**
     * Set whether to disable WordPress admin bar
     */
    public function disable_wp_admin_bar( $disable = true, $only_on_app_routes = false ) {
        $this->disable_wp_admin_bar = $disable;
        $this->only_on_app_routes   = $only_on_app_routes;

        if ( $disable ) {
            remove_filter( 'show_admin_bar', '__return_true' );
            add_filter( 'show_admin_bar', '__return_false' );
        }
    }

    /**
     * Configure which WordPress admin bar items to remove on app pages
     *
     * @param array $items_to_remove Array of admin bar item IDs to remove
     */
    public function set_removed_admin_bar_items( $items_to_remove ) {
        add_filter(
            'wp_app_admin_bar_remove_items',
            function () use ( $items_to_remove ) {
				return $items_to_remove;
			}
        );
    }

    /**
     * Actually disable the WordPress admin bar
     */
    public function do_disable_wp_admin_bar() {
        // Check if we should only disable on app routes
        if ( $this->only_on_app_routes && get_query_var( 'wp_app_route' ) === '' ) {
            return;
        }

        show_admin_bar( false );
        add_filter( 'show_admin_bar', '__return_false' );

        // Remove admin bar styles and scripts
        remove_action( 'wp_head', '_admin_bar_bump_cb' );
        add_theme_support( 'admin-bar', [ 'callback' => '__return_false' ] );
    }

    /**
     * Echo the rendered masterbar
     */
    public function echo_render() {
        echo $this->render();
    }

    /**
     * Fallback for themes that don\'t support wp_body_open
     */
    public function echo_render_fallback() {
        if ( ! did_action( 'wp_body_open' ) ) {
            echo '<script>document.addEventListener("DOMContentLoaded", function() { document.body.insertAdjacentHTML("afterbegin", ' . wp_json_encode( $this->render() ) . '); });</script>';
        }
    }
}
