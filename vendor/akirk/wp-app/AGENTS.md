# Repository guidance

## Page assets

- Do not handwrite `<style>` or `<script>` blocks in PHP templates or output methods, or create style elements from JavaScript. Avoid inline `style` attributes and event-handler attributes such as `onclick`.
- Put static CSS and JavaScript in `assets/`. Load app-page assets with `wp_app_enqueue_style()` and `wp_app_enqueue_script()`, passing the app scope explicitly. Use `wp_app_get_asset_url()` for framework assets and `WP_APP_VERSION` to version them.
- For WordPress admin pages, use the WordPress enqueue APIs. Pass dynamic data through HTML data attributes or a separate configuration response that an external script reads.
- When changing existing inline output, check the rendered page as well as the source. Shared head hooks can emit tags that are not present in a template.
