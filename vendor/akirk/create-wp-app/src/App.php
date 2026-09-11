<?php

namespace WpAppScaffoldNamespace;

use WpApp\WpApp;
use WpApp\BaseApp;
use WpApp\BaseStorage;

class App extends BaseApp {
    public function __construct() {
        // See https://github.com/akirk/wp-app for documentation.
        $this->app = new WpApp( $this->get_template_dir(), $this->get_url_path(), [
            // Access control
            // 'require_login'      => true,   // default; shorthand for require_capability => 'read'
            // 'require_capability' => 'read', // wins over require_login and implies it

            // App content: post types / taxonomies the app registers. Declaring
            // them gates their REST reads with the app's capability (the front-end
            // require_login does NOT cover /wp-json/) for types registered with
            // show_in_rest => true, so register_post_type() needs no
            // rest_controller_class. Declaring never turns show_in_rest on.
            // A map sets a capability per type: [ '{{identifier}}_item' => 'edit_posts' ].
            // 'post_types'         => [ '{{identifier}}_item' ],
            // 'taxonomies'         => [ '{{identifier}}_category' ],

            // Masterbar
            // 'show_masterbar_for_anonymous' => true,
            // 'show_wp_logo'                 => true,
            // 'show_site_name'               => true,
            // 'admin_bar_app_link'           => true,
            // 'clear_admin_bar'              => false,

            // App identity
            // 'app_name'            => $this->get_plugin_name(),
            // 'app_name_textdomain' => '{{slug}}',
            // Launchers (My Apps, OpenStation): false to opt out, or a custom name
            // 'launcher'            => true,
            // 'app_icon'            => null, // URL or 'dashicons-…'
        ] );

        // Uncomment only when these extension points contain real code.
        // add_action( 'init', [ $this, 'enqueue_assets' ] );
        // add_action( 'init', [ $this, 'register_post_types' ] );
        // add_action( 'init', [ $this, 'register_taxonomies' ] );
        // add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widgets' ] );
        // add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
        // add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
        // add_filter( 'ai_assistant_ability_domains', [ $this, 'register_ai_assistant_ability_domains' ] );
        // add_filter( 'ai_assistant_ability_instructions', [ $this, 'get_ai_assistant_ability_instructions' ], 10, 4 );
    }

    /**
     * Register this app's CSS and JS.
     *
     * Pass the app path as the scope. Without it the scope is guessed from
     * whatever is rendering, which is '' this early and would load the assets
     * on every app on the site. With it, this does not need to run during a
     * render at all, hence init - late enough for translated strings.
     */
    public function enqueue_assets(): void {
        // $scope = $this->get_url_path();
        //
        // wp_app_enqueue_style(
        //     '{{slug}}',
        //     plugins_url( 'assets/app.css', dirname( __DIR__ ) . '/{{slug}}.php' ),
        //     [],
        //     false,
        //     $scope
        // );
        //
        // Data for a script goes in its own inline handle, registered before
        // the script. wp_localize_script() does not work with these: they
        // print their own tags instead of registering with WP_Scripts.
        // wp_app_add_inline_script(
        //     '{{slug}}-config',
        //     'window.{{slug}}Config = ' . wp_json_encode( [] ) . ';',
        //     true,
        //     $scope
        // );
        //
        // wp_app_enqueue_script(
        //     '{{slug}}',
        //     plugins_url( 'assets/app.js', dirname( __DIR__ ) . '/{{slug}}.php' ),
        //     [],
        //     false,
        //     true,
        //     $scope
        // );
    }

    protected function get_url_path(): string {
        return '{{url-path}}';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
    }

    protected function get_plugin_name(): string {
        if ( ! function_exists( 'get_file_data' ) ) {
            return '{{plugin-name}}';
        }

        $plugin_data = get_file_data( dirname( __DIR__ ) . '/{{slug}}.php', [ 'name' => 'Plugin Name' ] );

        return $plugin_data['name'] ?: '{{plugin-name}}';
    }

    protected function setup_storage(): void {
        /*
         * Prefer WordPress-native storage before custom tables:
         * - Custom post types and post meta for content-like records.
         * - Taxonomies, terms, and term meta for shared categories or labels.
         * - User meta for per-user settings, preferences, and profile data.
         *
         * Use BaseStorage only when native entities do not fit, such as
         * high-volume rows, relational data, or non-content records.
         *
         * If you do need custom tables:
         *
         * class {{namespace}}Storage extends BaseStorage {
         *     // Keyed by unprefixed table name; create_tables() adds the prefix
         *     // and wraps each definition in CREATE TABLE with the site's charset.
         *     protected function get_schema() {
         *         return [
         *             '{{identifier}}_items' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
         *                 user_id bigint(20) unsigned NOT NULL,
         *                 title varchar(255) NOT NULL,
         *                 created_at datetime DEFAULT CURRENT_TIMESTAMP,
         *                 PRIMARY KEY  (id),
         *                 KEY user_id (user_id)",
         *         ];
         *     }
         * }
         *
         * Then in __construct(): $this->storage = new {{namespace}}Storage();
         * And in activate():     $this->storage->create_tables();
         */
    }

    protected function setup_database(): void {
        $this->setup_storage();
    }

    protected function setup_routes(): void {
        /*
         * Add WpApp routes here. BaseApp calls this method during init().
         *
         * $this->app->route( '' );               // -> templates/index.php
         * $this->app->route( 'overview' );       // -> templates/overview.php
         * $this->app->route( 'item/{id}' );      // -> templates/item.php
         */
    }

    protected function setup_menu(): void {
        /*
         * Add WpApp masterbar/menu entries here. BaseApp calls this method
         * during init(), after routes have been registered.
         *
         * $this->app->add_menu_item( 'overview', 'Overview', home_url( '/{{url-path}}/overview' ) );
         */
    }

    public function register_post_types(): void {
        /*
         * Register custom post types here. This method runs on WordPress init.
         * List each type in the 'post_types' option above: the app then gates
         * its REST reads, so no rest_controller_class is needed here. Keep
         * show_in_rest => true yourself (the block editor needs it).
         *
         * register_post_type( '{{identifier}}_item', [
         *     'label'        => '{{plugin-name}} Items',
         *     'public'       => false,
         *     'show_ui'      => true,
         *     'show_in_rest' => true,
         *     'supports'     => [ 'title', 'editor', 'author' ],
         * ] );
         */
    }

    public function register_taxonomies(): void {
        /*
         * Register taxonomies here. This method runs on WordPress init.
         * List each taxonomy in the 'taxonomies' option above (see
         * register_post_types()).
         *
         * register_taxonomy( '{{identifier}}_category', '{{identifier}}_item', [
         *     'label'        => '{{plugin-name}} Categories',
         *     'hierarchical' => true,
         *     'show_ui'      => true,
         *     'show_in_rest' => true,
         * ] );
         */
    }

    public function register_dashboard_widgets(): void {
        /*
         * Register dashboard widgets here. This method runs on
         * wp_dashboard_setup.
         *
         * wp_add_dashboard_widget(
         *     '{{identifier}}_dashboard',
         *     '{{plugin-name}}',
         *     [ $this, 'render_dashboard_widget' ]
         * );
         */
    }

    public function render_dashboard_widget(): void {
        /*
         * echo esc_html__( 'Add your dashboard summary here.', '{{slug}}' );
         */
    }

    public function register_ability_category(): void {
        // Register an Abilities API category for this plugin.
        //
        // if ( ! function_exists( 'wp_register_ability_category' ) ) {
        //     return;
        // }
        //
        // wp_register_ability_category( '{{slug}}', [
        //     'label'       => __( '{{plugin-name}}', '{{slug}}' ),
        //     'description' => __( 'Abilities for {{plugin-name}}.', '{{slug}}' ),
        // ] );
    }

    public function register_abilities(): void {
        // Register focused WordPress Abilities here so assistants, automation
        // and other apps can use this app without reading its code. The
        // description and schemas ARE the API: one ability per verb-noun
        // (list-items, get-item, create-item), say what is returned and what
        // to do on failure, describe every property, page every list-* and
        // annotate readonly/destructive/idempotent truthfully. Return WP_Error
        // with a stable code (not_found) on failure, never false/null/[].
        // Full guidance: vendor/akirk/wp-app/docs/abilities.md
        //
        // if ( ! function_exists( 'wp_register_ability' ) ) {
        //     return;
        // }
        //
        // wp_register_ability( '{{slug}}/list-items', [
        //     'label'               => __( 'List {{plugin-name}} Items', '{{slug}}' ),
        //     'description'         => 'Returns {{plugin-name}} items with IDs and titles for follow-up ability calls.',
        //     'category'            => '{{slug}}',
        //     'input_schema'        => [
        //         'type'                 => 'object',
        //         'properties'           => [
        //             'search'   => [
        //                 'type'        => 'string',
        //                 'description' => 'Optional search term for item titles.',
        //             ],
        //             'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Page of results.' ],
        //             'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10, 'description' => 'Items per page.' ],
        //         ],
        //         'additionalProperties' => false,
        //     ],
        //     'output_schema'       => [
        //         'type'       => 'object',
        //         'properties' => [
        //             'items' => [
        //                 'type'  => 'array',
        //                 'items' => [
        //                     'type'       => 'object',
        //                     'properties' => [
        //                         'id'    => [ 'type' => 'integer', 'description' => 'Pass to {{slug}}/get-item.' ],
        //                         'title' => [ 'type' => 'string', 'description' => 'Item title.' ],
        //                     ],
        //                 ],
        //             ],
        //             'total' => [ 'type' => 'integer', 'description' => 'Total number of matching items across all pages.' ],
        //         ],
        //     ],
        //     'execute_callback'    => [ $this, 'list_ability_items' ],
        //     // Reuse the app's capability so an ability is never a way around
        //     // the app's access control. Leave meta.public unset.
        //     'permission_callback' => function() {
        //         return current_user_can( $this->app->get_required_capability() ?: 'read' );
        //     },
        //     'meta'                => [
        //         'annotations' => [
        //             'instructions' => 'Use returned item IDs for follow-up detail or edit abilities.',
        //             'readonly'     => true,
        //             'destructive'  => false,
        //             'idempotent'   => true,
        //         ],
        //     ],
        // ] );
    }

    public function list_ability_items( $input ): array {
        // Sanitize ability input and return structured data. Return WP_Error
        // for failures.
        //
        // $input = is_array( $input ) ? $input : [];
        // $search = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
        //
        // return [
        //     'items' => [
        //         [
        //             'id'    => 123,
        //             'title' => __( 'Example item', '{{slug}}' ),
        //         ],
        //     ],
        // ];
        return [
            'items' => [],
        ];
    }

    public function register_ai_assistant_ability_domains( array $domains ): array {
        // Tell AI Assistant which user terms belong to this plugin so it
        // considers your abilities for domain-specific requests.
        //
        // $domains['{{slug}}'] = '{{plugin-name}}, items, records, dashboard';
        return $domains;
    }

    public function get_ai_assistant_ability_instructions( string $instructions, string $ability_id, $args, $result ): string {
        // Add presentation or follow-up guidance after a specific ability runs.
        //
        // if ( '{{slug}}/list-items' === $ability_id && ! empty( $result['items'] ) ) {
        //     $instructions = 'Present the items as a compact table. Mention that item IDs can be used for follow-up changes.';
        // }
        return $instructions;
    }

    public function activate(): void {
        /*
         * If using BaseStorage, create/update custom tables here:
         *
         * $this->storage->create_tables();
         */
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}
