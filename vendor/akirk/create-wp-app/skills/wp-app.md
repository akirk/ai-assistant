# WpApp Assistant Rules

Use these rules when creating or modifying a WpApp plugin scaffold.

## Generated App Lifecycle

- Read the generated files before modifying them.
- Keep `__construct()` focused on creating/configuring `WpApp`, assigning storage objects, and attaching WordPress hooks.
- Do not call `register_post_type()`, `register_taxonomy()`, `flush_rewrite_rules()`, `wp_add_dashboard_widget()`, REST route registration, or other WordPress-hooked feature registration directly from `__construct()`.
- Register custom post types and taxonomies on the WordPress `init` hook.
- Register dashboard widgets on the WordPress `wp_dashboard_setup` hook.
- Define WpApp routes in `setup_routes()` and WpApp menu/masterbar entries in `setup_menu()`.
- Run activation-only work, including custom table creation and rewrite flushing, from the plugin activation hook.

## Storage Choices

- Prefer WordPress-native storage before custom tables:
  - Custom post types and post meta for content-like records.
  - Taxonomies, terms, and term meta for shared categories, labels, and groupings.
  - User meta for per-user settings, preferences, and profile data.
- Use custom tables and `BaseStorage` only when native WordPress storage does not fit, such as high-volume rows, relational data, or records that do not map cleanly to posts, terms, or users.
- If using `BaseStorage`, instantiate the storage class during app construction and call `create_tables()` during plugin activation.

## Verification

- After modifying PHP, run or request a syntax check before navigating the app.
- If a WordPress runtime is available, activate the plugin and load the configured app URL after the syntax check passes.
