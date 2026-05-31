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
            // 'require_login'      => false,
            // 'require_capability' => 'read',

            // Masterbar
            // 'show_masterbar_for_anonymous' => false,
            // 'show_wp_logo'                 => true,
            // 'show_site_name'               => true,
            // 'show_dark_mode_toggle'        => false,
            // 'clear_admin_bar'              => false,
            // 'add_app_node'                 => false,

            // App identity
            // 'app_name'     => '{{plugin-name}}',
            // 'my_apps'      => true,
            // 'my_apps_icon' => null,
        ] );

        // Uncomment only when these extension points contain real code.
        // add_action( 'init', [ $this, 'register_post_types' ] );
        // add_action( 'init', [ $this, 'register_taxonomies' ] );
        // add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widgets' ] );
        // add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
        // add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
        // add_filter( 'ai_assistant_ability_domains', [ $this, 'register_ai_assistant_ability_domains' ] );
        // add_filter( 'ai_assistant_ability_instructions', [ $this, 'get_ai_assistant_ability_instructions' ], 10, 4 );
    }

    protected function get_url_path(): string {
        return '{{url-path}}';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
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
         *     protected function get_schema() {
         *         $charset_collate = $this->wpdb->get_charset_collate();
         *         return [
         *             "CREATE TABLE {$this->wpdb->prefix}{{identifier}}_items (
         *                 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
         *                 user_id bigint(20) unsigned NOT NULL,
         *                 title varchar(255) NOT NULL,
         *                 created_at datetime DEFAULT CURRENT_TIMESTAMP,
         *                 PRIMARY KEY (id),
         *                 KEY user_id (user_id)
         *             ) $charset_collate;",
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
        // Register focused WordPress Abilities here. AI Assistant can discover
        // and execute these instead of guessing plugin internals.
        // See https://github.com/akirk/ai-assistant/blob/main/docs/plugin-integration.md
        // for AI Assistant-specific guidance.
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
        //             'search' => [
        //                 'type'        => 'string',
        //                 'description' => 'Optional search term for item titles.',
        //             ],
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
        //                         'id'    => [ 'type' => 'integer', 'description' => 'Use with {{slug}}/get-item.' ],
        //                         'title' => [ 'type' => 'string' ],
        //                     ],
        //                 ],
        //             ],
        //         ],
        //     ],
        //     'execute_callback'    => [ $this, 'list_ability_items' ],
        //     'permission_callback' => function() {
        //         return current_user_can( 'read' );
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
