<?php

namespace WpApp;

if ( class_exists( 'WpApp\Settings' ) ) {
    return;
}

/**
 * Admin settings for global WpApp masterbar behavior.
 */
class Settings {
    const OPTION = 'wp_app_masterbar_settings';

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
     * Get the default settings.
     */
    public static function get_default_settings() {
        return [
            'only_show_active_app'           => true,
            'show_inactive_apps_in_overflow' => true,
            'app_order'                      => [],
            'apps'                           => [],
        ];
    }

    /**
     * Get defaults for a single app.
     */
    public static function get_default_app_settings() {
        return [
            'title'                => '',
            'icon'                 => '',
            'show_icon'            => true,
            'generate_letter_icon' => true,
            'show_text'            => true,
            'always_show'          => false,
        ];
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
     * Get normalized settings for an app path.
     *
     * @param string $app_path App URL path.
     * @return array App settings.
     */
    public static function get_app_settings( $app_path ) {
        $settings = self::get_settings();
        $apps     = isset( $settings['apps'] ) && is_array( $settings['apps'] ) ? $settings['apps'] : [];
        $app      = isset( $apps[ $app_path ] ) && is_array( $apps[ $app_path ] ) ? $apps[ $app_path ] : [];

        return array_merge( self::get_default_app_settings(), $app );
    }

    /**
     * Whether an app should appear outside its active app context.
     *
     * @param string $app_path App URL path.
     * @return bool True when the app should be shown globally.
     */
    public static function should_show_global_app_link( $app_path ) {
        $settings = self::get_settings();

        if ( empty( $settings['only_show_active_app'] ) ) {
            return true;
        }

        $app_settings = self::get_app_settings( $app_path );
        return ! empty( $app_settings['always_show'] );
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
     * Get WpApp apps registered with this library.
     *
     * @return array App metadata keyed by app path.
     */
    public static function get_registered_apps() {
        $apps = class_exists( __NAMESPACE__ . '\Registry' ) ? Registry::get_app_metadata() : [];

        return self::sort_registered_apps( $apps );
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
                    'show_icon'            => ! empty( $app_settings['show_icon'] ),
                    'generate_letter_icon' => ! empty( $app_settings['generate_letter_icon'] ),
                    'show_text'            => ! empty( $app_settings['show_text'] ),
                    'always_show'          => ! empty( $app_settings['always_show'] ),
                ];
            }
        }

        return [
            'only_show_active_app'           => ! empty( $value['only_show_active_app'] ),
            'show_inactive_apps_in_overflow' => ! empty( $value['show_inactive_apps_in_overflow'] ),
            'app_order'                      => $order,
            'apps'                           => $apps,
        ];
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'WP Apps' ); ?></h1>
            <style>
                .wp-app-settings-list {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    margin-top: 16px;
                    max-width: 1100px;
                }

                .wp-app-settings-row {
                    box-sizing: border-box;
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                    margin-top: 0;
                    max-width: none;
                    min-width: 0;
                    padding: 0;
                    width: 100%;
                }

                .wp-app-settings-row::before,
                .wp-app-settings-row::after {
                    background: #2271b1;
                    content: "";
                    display: none;
                    height: 3px;
                    margin: -2px 12px;
                }

                .wp-app-settings-row-summary {
                    align-items: center;
                    box-sizing: border-box;
                    display: grid;
                    gap: 14px;
                    grid-template-columns: 32px minmax(0, 1fr) auto 32px;
                    height: 64px;
                    padding: 10px 12px;
                }

                .wp-app-settings-row.is-dragging {
                    opacity: 0.35;
                }

                .wp-app-settings-row.is-drag-over {
                    border-color: #c3c4c7;
                    box-shadow: none;
                }

                .wp-app-settings-row.is-drop-before::before,
                .wp-app-settings-row.is-drop-after::after {
                    display: block;
                }

                .wp-app-settings-handle {
                    align-items: center;
                    background: transparent;
                    border: 0;
                    border-radius: 2px;
                    color: #50575e;
                    cursor: grab;
                    display: grid;
                    gap: 3px;
                    grid-template-columns: repeat(2, 4px);
                    justify-content: center;
                    margin: 0;
                    min-height: 32px;
                    padding: 0;
                    width: 32px;
                }

                .wp-app-settings-handle:active {
                    cursor: grabbing;
                }

                .wp-app-settings-handle:focus {
                    outline: 0;
                }

                .wp-app-settings-handle:focus-visible {
                    box-shadow: 0 0 0 2px #2271b1;
                    outline: 2px solid transparent;
                }

                .wp-app-settings-handle-dot {
                    background: currentColor;
                    border-radius: 50%;
                    display: block;
                    height: 4px;
                    opacity: 0.72;
                    width: 4px;
                }

                .wp-app-settings-row-preview {
                    min-width: 0;
                    overflow: hidden;
                }

                .wp-app-settings-row-toggles {
                    align-items: center;
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px 12px;
                    justify-content: flex-end;
                }

                .wp-app-settings-row-toggles label {
                    white-space: nowrap;
                }

                .wp-app-settings-row-details {
                    border-top: 1px solid #dcdcde;
                    display: grid;
                    gap: 14px 20px;
                    grid-template-columns: minmax(0, 1fr) auto;
                    padding: 12px 12px 14px 58px;
                }

                .wp-app-settings-row-details[hidden] {
                    display: none;
                }

                .wp-app-settings-row-fields,
                .wp-app-settings-row-status {
                    min-width: 0;
                }

                .wp-app-settings-expand {
                    align-items: center;
                    background: transparent;
                    border: 0;
                    color: #50575e;
                    cursor: pointer;
                    display: flex;
                    height: 32px;
                    justify-content: center;
                    margin: 0;
                    padding: 0;
                    width: 32px;
                }

                .wp-app-settings-expand:focus {
                    box-shadow: 0 0 0 2px #2271b1;
                    outline: 2px solid transparent;
                }

                .wp-app-settings-expand .dashicons {
                    height: 20px;
                    transition: transform 120ms ease-in-out;
                    width: 20px;
                }

                .wp-app-settings-row.is-expanded .wp-app-settings-expand .dashicons {
                    transform: rotate(90deg);
                }

                .wp-app-masterbar-preview-wrap {
                    display: inline-block;
                    max-width: 100%;
                    min-height: 32px;
                    position: relative;
                    vertical-align: top;
                }

                .wp-app-masterbar-preview-wrap.is-hidden {
                    opacity: 0.58;
                }

                .wp-app-masterbar-preview-wrap #wpadminbar {
                    display: inline-block;
                    min-width: 0;
                    position: static;
                    width: auto;
                    z-index: auto;
                }

                .wp-app-masterbar-preview-wrap #wpadminbar .ab-top-menu > li {
                    position: relative;
                }

                .wp-app-masterbar-preview-wrap #wpadminbar .ab-sub-wrapper {
                    z-index: 2;
                }

                .wp-app-settings-row .form-table {
                    margin-top: 0;
                }

                .wp-app-settings-row .form-table th {
                    padding-bottom: 8px;
                    padding-top: 8px;
                    width: 120px;
                }

                .wp-app-settings-row .form-table td {
                    padding-bottom: 8px;
                    padding-top: 8px;
                }

                .wp-app-settings-row .regular-text {
                    max-width: 100%;
                    width: 100%;
                }

                .wp-app-settings-row .form-table td p {
                    margin-bottom: 10px;
                }

                .wp-app-settings-field {
                    margin: 0 0 10px;
                }

                .wp-app-settings-field:last-child {
                    margin-bottom: 0;
                }

                .wp-app-masterbar-visibility-note {
                    margin: 0;
                }

                .wp-app-masterbar-visibility-note code {
                    font-size: 12px;
                }

                .wp-app-settings-card-actions {
                    margin: 0;
                    text-align: right;
                }

                .wp-app-settings-save-status {
                    color: #50575e;
                    display: inline-block;
                    margin-left: 8px;
                }

                .wp-app-settings-save-status.is-saved {
                    color: #008a20;
                }

                .wp-app-settings-save-status.is-failed {
                    color: #b32d2e;
                }

                .wp-app-settings-drag-preview {
                    background: #fff;
                    border: 1px solid #2271b1;
                    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
                    box-sizing: border-box;
                    left: -9999px;
                    max-width: 1100px;
                    opacity: 0.96;
                    pointer-events: none;
                    position: fixed;
                    top: -9999px;
                    width: 720px;
                    z-index: 100000;
                }

                .wp-app-settings-drag-preview .wp-app-settings-row-summary {
                    height: 64px;
                }

                @media (max-width: 960px) {
                    .wp-app-settings-row-details {
                        grid-template-columns: 1fr;
                        padding-left: 12px;
                    }

                    .wp-app-settings-card-actions {
                        text-align: left;
                    }
                }
            </style>
            <form method="post" action="options.php">
                <?php settings_fields( 'wp_app_masterbar' ); ?>

                <h2><?php echo esc_html__( 'Global Display' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'App menu visibility' ); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[only_show_active_app]" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[only_show_active_app]" value="1" <?php checked( ! empty( $settings['only_show_active_app'] ) ); ?>>
                                <?php echo esc_html__( 'Only show the active app by default' ); ?>
                            </label>
                            <br>
                            <input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[show_inactive_apps_in_overflow]" value="0">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_inactive_apps_in_overflow]" value="1" <?php checked( ! empty( $settings['show_inactive_apps_in_overflow'] ) ); ?>>
                                <?php echo esc_html__( 'Show inactive apps in the overflow menu on app pages' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2>
                    <?php echo esc_html__( 'Installed Apps' ); ?>
                    <span class="wp-app-settings-save-status" data-wp-app-save-status hidden></span>
                </h2>
                <?php if ( empty( $apps ) ) : ?>
                    <p><?php echo esc_html__( 'No WpApp apps are currently registered.' ); ?></p>
                <?php endif; ?>

                <div class="wp-app-settings-list" data-wp-app-settings-list>
                    <?php foreach ( $apps as $app_path => $metadata ) : ?>
                        <?php
                        $app_settings      = self::get_app_settings( $app_path );
                        $app_name          = isset( $metadata['name'] ) ? $metadata['name'] : $app_path;
                        $visibility_status = self::get_masterbar_visibility_status( $app_path, $app_settings, $metadata );
                        $can_customize     = self::should_render_app_settings_controls( $visibility_status );
                        ?>
                        <section
                            class="card wp-app-settings-card wp-app-settings-row"
                            data-default-title="<?php echo esc_attr( $app_name ); ?>"
                            data-default-letter="<?php echo esc_attr( strtoupper( substr( $app_name, 0, 1 ) ) ); ?>"
                            data-default-dashicon="<?php echo esc_attr( isset( $metadata['dashicon'] ) ? $metadata['dashicon'] : '' ); ?>"
                            data-icon-url="<?php echo esc_url( isset( $metadata['icon_url'] ) ? $metadata['icon_url'] : '' ); ?>"
                            data-force-show-text="0"
                            data-app-path="<?php echo esc_attr( $app_path ); ?>"
                        >
                            <input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[app_order][]" value="<?php echo esc_attr( $app_path ); ?>">
                            <div class="wp-app-settings-row-summary">
                                <span class="wp-app-settings-handle" role="button" tabindex="0" draggable="true" aria-label="<?php echo esc_attr__( 'Reorder app' ); ?>">
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                    <span class="wp-app-settings-handle-dot" aria-hidden="true"></span>
                                </span>
                                <div class="wp-app-settings-row-preview">
                                    <?php self::render_preview( $app_settings, $metadata, $app_name, $app_path ); ?>
                                </div>
                                <div class="wp-app-settings-row-toggles">
                                    <?php if ( $can_customize ) : ?>
                                        <?php self::render_checkbox( $app_path, 'show_icon', $app_settings['show_icon'], __( 'Icon' ) ); ?>
                                        <?php self::render_checkbox( $app_path, 'show_text', $app_settings['show_text'], __( 'Text' ) ); ?>
                                        <?php if ( self::should_render_always_show_setting( $visibility_status ) ) : ?>
                                            <?php self::render_checkbox( $app_path, 'always_show', $app_settings['always_show'], __( 'Always show' ) ); ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <button
                                    type="button"
                                    class="wp-app-settings-expand"
                                    aria-expanded="false"
                                    aria-controls="<?php echo esc_attr( self::get_field_id( $app_path, 'details' ) ); ?>"
                                    aria-label="<?php echo esc_attr__( 'Expand app settings' ); ?>"
                                >
                                    <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
                                </button>
                            </div>
                            <div id="<?php echo esc_attr( self::get_field_id( $app_path, 'details' ) ); ?>" class="wp-app-settings-row-details" hidden>
                                <div class="wp-app-settings-row-fields">
                                    <?php if ( $can_customize ) : ?>
                                        <table class="form-table" role="presentation">
                                            <tr>
                                                <th scope="row">
                                                    <label for="<?php echo esc_attr( self::get_field_id( $app_path, 'title' ) ); ?>"><?php echo esc_html__( 'Title' ); ?></label>
                                                </th>
                                                <td>
                                                    <input
                                                        id="<?php echo esc_attr( self::get_field_id( $app_path, 'title' ) ); ?>"
                                                        class="regular-text"
                                                        type="text"
                                                        name="<?php echo esc_attr( self::get_field_name( $app_path, 'title' ) ); ?>"
                                                        data-wp-app-setting="title"
                                                        value="<?php echo esc_attr( $app_settings['title'] ); ?>"
                                                        placeholder="<?php echo esc_attr( $app_name ); ?>"
                                                    >
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label for="<?php echo esc_attr( self::get_field_id( $app_path, 'icon' ) ); ?>"><?php echo esc_html__( 'Icon' ); ?></label>
                                                </th>
                                                <td>
                                                    <input
                                                        id="<?php echo esc_attr( self::get_field_id( $app_path, 'icon' ) ); ?>"
                                                        class="regular-text"
                                                        type="text"
                                                        name="<?php echo esc_attr( self::get_field_name( $app_path, 'icon' ) ); ?>"
                                                        data-wp-app-setting="icon"
                                                        value="<?php echo esc_attr( $app_settings['icon'] ); ?>"
                                                        placeholder="<?php echo esc_attr__( 'e.g. dashicons-admin-site' ); ?>"
                                                    >
                                                    <p class="description"><?php echo esc_html__( 'Use an emoji or' ); ?> <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Dashicon' ); ?></a> <?php echo esc_html__( 'class.' ); ?></p>
                                                </td>
                                            </tr>
                                        </table>
                                    <?php else : ?>
                                        <?php self::render_hidden_app_settings( $app_path, $app_settings ); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="wp-app-settings-row-status">
                                    <?php self::render_visibility_note_if_needed( $app_path, $visibility_status ); ?>
                                    <?php if ( $can_customize ) : ?>
                                        <p class="wp-app-settings-card-actions">
                                            <button type="submit" class="button button-primary"><?php echo esc_html__( 'Save' ); ?></button>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <?php submit_button(); ?>
            </form>
            <script>
                document.addEventListener("input", wpAppUpdateMasterbarPreview);
                document.addEventListener("change", wpAppUpdateMasterbarPreview);
                document.addEventListener("change", wpAppAutosaveCheckboxChange);
                document.addEventListener("click", wpAppToggleSettingsRow);
                wpAppShowSavedStatus();
                document.querySelectorAll("[data-wp-app-settings-list]").forEach(wpAppMakeSettingsListSortable);

                let wpAppAutosaveTimer = null;
                let wpAppAutosaveController = null;

                function wpAppAutosaveCheckboxChange(event) {
                    if (!event.target.matches("input[type='checkbox'][name^='<?php echo esc_js( self::OPTION ); ?>']")) {
                        return;
                    }

                    const form = event.target.form;

                    if (!form) {
                        return;
                    }

                    wpAppSetSaveStatus("<?php echo esc_js( __( 'Saving...' ) ); ?>");
                    clearTimeout(wpAppAutosaveTimer);
                    wpAppAutosaveTimer = setTimeout(function() {
                        wpAppSubmitAutosave(form);
                    }, 250);
                }

                function wpAppSubmitAutosave(form) {
                    if (!window.fetch) {
                        wpAppSetSaveStatus("<?php echo esc_js( __( 'Save failed' ) ); ?>", false, true);
                        return;
                    }

                    if (wpAppAutosaveController) {
                        wpAppAutosaveController.abort();
                    }

                    wpAppAutosaveController = window.AbortController ? new AbortController() : null;

                    fetch(form.getAttribute("action") || window.location.href, {
                        body: new FormData(form),
                        credentials: "same-origin",
                        method: "POST",
                        signal: wpAppAutosaveController ? wpAppAutosaveController.signal : undefined
                    }).then(function(response) {
                        if (!response.ok) {
                            throw new Error("Autosave failed");
                        }

                        wpAppSetSaveStatus("<?php echo esc_js( __( 'Saved' ) ); ?>", true);
                    }).catch(function(error) {
                        if (error && error.name === "AbortError") {
                            return;
                        }

                        wpAppSetSaveStatus("<?php echo esc_js( __( 'Save failed' ) ); ?>", false, true);
                    });
                }

                function wpAppShowSavedStatus() {
                    const params = new URLSearchParams(window.location.search);

                    if (params.get("settings-updated") === "true") {
                        wpAppSetSaveStatus("<?php echo esc_js( __( 'Saved' ) ); ?>", true);
                    }
                }

                function wpAppSetSaveStatus(message, saved, failed) {
                    const status = document.querySelector("[data-wp-app-save-status]");

                    if (!status) {
                        return;
                    }

                    status.textContent = message;
                    status.hidden = false;
                    status.classList.toggle("is-saved", !!saved);
                    status.classList.toggle("is-failed", !!failed);
                }

                function wpAppToggleSettingsRow(event) {
                    const button = event.target.closest(".wp-app-settings-expand");

                    if (!button) {
                        return;
                    }

                    const row = button.closest(".wp-app-settings-row");
                    const details = row ? row.querySelector(".wp-app-settings-row-details") : null;
                    const expanded = button.getAttribute("aria-expanded") === "true";

                    if (!row || !details) {
                        return;
                    }

                    button.setAttribute("aria-expanded", expanded ? "false" : "true");
                    button.setAttribute("aria-label", expanded ? "<?php echo esc_js( __( 'Expand app settings' ) ); ?>" : "<?php echo esc_js( __( 'Collapse app settings' ) ); ?>");
                    row.classList.toggle("is-expanded", !expanded);
                    details.hidden = expanded;
                }

                function wpAppUpdateMasterbarPreview(event) {
                    const card = event.target.closest(".wp-app-settings-card");

                    if (!card) {
                        return;
                    }

                    const titleField = card.querySelector("[data-wp-app-setting='title']");
                    const iconField = card.querySelector("[data-wp-app-setting='icon']");
                    const defaultTitle = card.dataset.defaultTitle || "";
                    const defaultDashicon = card.dataset.defaultDashicon || "";
                    const iconUrl = card.dataset.iconUrl || "";
                    const forceShowText = card.dataset.forceShowText === "1";
                    const title = titleField.value.trim() || defaultTitle;
                    const icon = iconField.value.trim();
                    const showTextField = card.querySelector("[data-wp-app-setting='show_text']");
                    const showIconField = card.querySelector("[data-wp-app-setting='show_icon']");
                    const preview = card.querySelector(".wp-app-masterbar-preview-wrap");
                    const iconWrap = preview.querySelector(".wp-app-link-icon");
                    const textWrap = preview.querySelector(".wp-app-link-text");

                    textWrap.textContent = title;
                    textWrap.hidden = !forceShowText && !showTextField.checked;
                    iconWrap.innerHTML = "";
                    iconWrap.hidden = !showIconField.checked;
                    preview.classList.remove("is-hidden");

                    if (!showIconField.checked) {
                        preview.classList.toggle("is-hidden", !showTextField.checked);
                        return;
                    }

                    if (icon) {
                        if (/^dashicons-[a-z0-9-]+$/.test(icon)) {
                            const span = document.createElement("span");
                            span.className = "dashicons " + icon;
                            iconWrap.appendChild(span);
                        } else {
                            iconWrap.textContent = icon;
                        }
                    } else if (defaultDashicon) {
                        const span = document.createElement("span");
                        span.className = "dashicons " + defaultDashicon;
                        iconWrap.appendChild(span);
                    } else if (iconUrl) {
                        const img = document.createElement("img");
                        img.alt = "";
                        img.src = iconUrl;
                        iconWrap.appendChild(img);
                    } else {
                        iconWrap.textContent = (title.charAt(0) || card.dataset.defaultLetter || "").toUpperCase();
                    }
                }

                function wpAppMakeSettingsListSortable(list) {
                    let dragging = null;
                    let dropTarget = null;
                    let dropBefore = true;
                    let dragPreview = null;

                    function clearDropIndicators() {
                        list.querySelectorAll(".is-drag-over, .is-drop-before, .is-drop-after").forEach(function(row) {
                            row.classList.remove("is-drag-over", "is-drop-before", "is-drop-after");
                        });
                    }

                    function clearDragPreview() {
                        if (dragPreview && dragPreview.parentNode) {
                            dragPreview.parentNode.removeChild(dragPreview);
                        }

                        dragPreview = null;
                    }

                    function createDragPreview(row) {
                        const summary = row.querySelector(".wp-app-settings-row-summary");

                        if (!summary) {
                            return null;
                        }

                        const bounds = row.getBoundingClientRect();
                        const preview = document.createElement("div");
                        preview.className = "wp-app-settings-drag-preview";
                        preview.style.width = Math.max(320, Math.round(bounds.width)) + "px";
                        preview.appendChild(summary.cloneNode(true));
                        document.body.appendChild(preview);
                        return preview;
                    }

                    list.addEventListener("dragstart", function(event) {
                        const handle = event.target.closest(".wp-app-settings-handle");
                        const row = handle ? handle.closest(".wp-app-settings-row") : null;

                        if (!row) {
                            event.preventDefault();
                            return;
                        }

                        dragging = row;
                        row.classList.add("is-dragging");
                        dragPreview = createDragPreview(row);
                        event.dataTransfer.effectAllowed = "move";
                        event.dataTransfer.setData("text/plain", row.dataset.appPath || "");

                        if (dragPreview && event.dataTransfer.setDragImage) {
                            event.dataTransfer.setDragImage(dragPreview, 24, 32);
                        }
                    });

                    list.addEventListener("dragover", function(event) {
                        const row = event.target.closest(".wp-app-settings-row");

                        if (!dragging || !row || row === dragging) {
                            return;
                        }

                        event.preventDefault();

                        const bounds = row.getBoundingClientRect();
                        dropTarget = row;
                        dropBefore = event.clientY < bounds.top + bounds.height / 2;

                        clearDropIndicators();
                        row.classList.add("is-drag-over", dropBefore ? "is-drop-before" : "is-drop-after");
                    });

                    list.addEventListener("dragleave", function(event) {
                        const row = event.target.closest(".wp-app-settings-row");

                        if (row && !row.contains(event.relatedTarget)) {
                            row.classList.remove("is-drag-over", "is-drop-before", "is-drop-after");
                        }
                    });

                    list.addEventListener("drop", function(event) {
                        event.preventDefault();

                        if (dragging && dropTarget && dropTarget !== dragging) {
                            list.insertBefore(dragging, dropBefore ? dropTarget : dropTarget.nextSibling);
                        }

                        clearDropIndicators();
                    });

                    list.addEventListener("dragend", function() {
                        if (dragging) {
                            dragging.classList.remove("is-dragging");
                        }

                        dragging = null;
                        dropTarget = null;
                        dropBefore = true;
                        clearDragPreview();
                        clearDropIndicators();
                    });

                    list.addEventListener("keydown", function(event) {
                        if (!event.target.closest(".wp-app-settings-handle") || !["ArrowUp", "ArrowDown"].includes(event.key)) {
                            return;
                        }

                        const row = event.target.closest(".wp-app-settings-row");

                        if (!row) {
                            return;
                        }

                        event.preventDefault();

                        if (event.key === "ArrowUp" && row.previousElementSibling) {
                            list.insertBefore(row, row.previousElementSibling);
                        } else if (event.key === "ArrowDown" && row.nextElementSibling) {
                            list.insertBefore(row.nextElementSibling, row);
                        }

                        event.target.focus();
                    });
                }
            </script>
        </div>
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
                                    <?php self::render_preview_icon( $app_settings, $metadata, $title ); ?>
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
    private static function render_preview_icon( $app_settings, $metadata, $title ) {
        $icon   = isset( $app_settings['icon'] ) ? trim( $app_settings['icon'] ) : '';
        $hidden = empty( $app_settings['show_icon'] );

        echo '<span class="wp-app-link-icon"' . ( $hidden ? ' hidden' : '' ) . '>';

        if ( '' !== $icon ) {
            if ( self::is_dashicon_value( $icon ) ) {
                echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
            } else {
                echo esc_html( $icon );
            }
        } elseif ( ! empty( $metadata['dashicon'] ) ) {
            echo '<span class="dashicons ' . esc_attr( $metadata['dashicon'] ) . '"></span>';
        } elseif ( ! empty( $metadata['icon_url'] ) ) {
            echo '<img src="' . esc_url( $metadata['icon_url'] ) . '" alt="">';
        } else {
            echo esc_html( strtoupper( substr( $title, 0, 1 ) ) );
        }

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
     * Check if an icon override should be rendered as a dashicon.
     */
    private static function is_dashicon_value( $icon ) {
        return is_string( $icon ) && preg_match( '/^dashicons-[a-z0-9-]+$/', $icon );
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
