<?php

namespace WpApp;

if ( class_exists( 'WpApp\Themes' ) ) {
	return;
}

/**
 * App theme registration, selection, and template fallback handling.
 */
class Themes {
	private $app_path;
	private $default_template_directory;
	private $masterbar;
	private $themes           = [];
	private $selected_theme   = null;
	private $selection_loaded = false;
	private $theme_loaded     = false;

	public function __construct( $app_path, $template_directory, Masterbar $masterbar ) {
		$this->app_path                   = trim( $app_path, '/' );
		$this->default_template_directory = rtrim( $template_directory, '/\\' );
		$this->masterbar                  = $masterbar;

		$this->register( 'default', __( 'Default' ), $this->default_template_directory );
	}

	/**
	 * Register a theme supplied by the app or another plugin.
	 *
	 * @param string $slug               Theme slug.
	 * @param string $name               Display name.
	 * @param string $template_directory Directory containing theme templates. Empty means no overrides.
	 * @return bool Whether the theme was registered.
	 */
	public function register( $slug, $name, $template_directory = '' ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || '' === trim( (string) $name ) ) {
			return false;
		}

		$this->themes[ $slug ] = [
			'slug'               => $slug,
			'name'               => (string) $name,
			'template_directory' => '' === $template_directory ? '' : rtrim( $template_directory, '/\\' ),
		];
		if ( class_exists( __NAMESPACE__ . '\\Registry' ) ) {
			Registry::register_app_metadata( $this->app_path, [ 'themes' => $this->themes ] );
		}
		$this->refresh_menu();

		return true;
	}

	/** Get registered themes keyed by slug. */
	public function get_themes() {
		return $this->themes;
	}

	/** Get the selected theme slug. */
	public function get_selected() {
		$this->load_selection();
		return $this->selected_theme;
	}

	/**
	 * Return template directories in lookup order.
	 *
	 * The selected theme is checked first and the app's regular template
	 * directory remains the fallback.
	 */
	public function get_template_directories() {
		$selected    = $this->get_selected();
		$directories = [];

		if ( isset( $this->themes[ $selected ] ) && '' !== $this->themes[ $selected ]['template_directory'] ) {
			$directories[] = $this->themes[ $selected ]['template_directory'];
		}
		$directories[] = $this->default_template_directory;

		$this->load_theme();

		return array_values( array_unique( $directories ) );
	}

	private function load_selection() {
		if ( $this->selection_loaded ) {
			return;
		}

		$selected = get_user_option( $this->get_user_option_name(), get_current_user_id() );
		if ( ! is_string( $selected ) || ! isset( $this->themes[ $selected ] ) ) {
			$app_settings = class_exists( __NAMESPACE__ . '\\Settings' ) ? Settings::get_app_settings( $this->app_path ) : [];
			$site_default = isset( $app_settings['default_theme'] ) ? sanitize_key( $app_settings['default_theme'] ) : '';
			$selected     = isset( $this->themes[ $site_default ] ) ? $site_default : 'default';
		}

		if ( isset( $_GET['wp_app_theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A validated, user-local display preference.
			$requested = sanitize_key( wp_unslash( $_GET['wp_app_theme'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $this->themes[ $requested ] ) ) {
				$selected = $requested;
				if ( is_user_logged_in() ) {
					update_user_option( get_current_user_id(), $this->get_user_option_name(), $selected );
				}
			}
		}

		$this->selected_theme   = $selected;
		$this->selection_loaded = true;
		$this->refresh_menu();
	}

	private function load_theme() {
		if ( $this->theme_loaded ) {
			return;
		}

		$this->theme_loaded = true;
		do_action( 'wp_app_load_theme', $this->selected_theme, $this->app_path, $this->themes[ $this->selected_theme ] );
		do_action( 'wp_app_load_theme_' . $this->get_hook_suffix() . '_' . $this->selected_theme, $this->themes[ $this->selected_theme ] );
	}

	private function refresh_menu() {
		if ( count( $this->themes ) < 2 ) {
			return;
		}

		$parent_id = 'wp-app-theme-' . $this->get_hook_suffix();
		$this->masterbar->add_menu_item( $parent_id, __( 'Themes' ) );

		foreach ( $this->themes as $slug => $theme ) {
			$selected = $this->selection_loaded ? $this->selected_theme : get_user_option( $this->get_user_option_name(), get_current_user_id() );
			if ( ! is_string( $selected ) || ! isset( $this->themes[ $selected ] ) ) {
				$app_settings = class_exists( __NAMESPACE__ . '\\Settings' ) ? Settings::get_app_settings( $this->app_path ) : [];
				$site_default = isset( $app_settings['default_theme'] ) ? sanitize_key( $app_settings['default_theme'] ) : '';
				$selected     = isset( $this->themes[ $site_default ] ) ? $site_default : 'default';
			}
			$title = ( $slug === $selected ? '✓ ' : '' ) . $theme['name'];
			$this->masterbar->add_menu_item(
				$parent_id . '-choice-' . $slug,
				$title,
				add_query_arg( 'wp_app_theme', $slug ),
				[ 'parent' => $parent_id ]
			);
		}
	}

	private function get_user_option_name() {
		return 'wp_app_theme_' . $this->get_hook_suffix();
	}

	private function get_hook_suffix() {
		return sanitize_key( str_replace( '/', '_', $this->app_path ) );
	}
}
