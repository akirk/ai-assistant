# Repository guidance

## Page assets

- Do not handwrite `<style>` or `<script>` blocks in PHP templates or output methods, or create style elements from JavaScript. Avoid inline `style` attributes and event-handler attributes such as `onclick`.
- Put static CSS and JavaScript in `assets/`. Load app-page assets with `wp_app_enqueue_style()` and `wp_app_enqueue_script()`, passing the app scope explicitly. Use `wp_app_get_asset_url()` for framework assets and `WP_APP_VERSION` to version them.
- For WordPress admin pages, use the WordPress enqueue APIs. Pass dynamic data through HTML data attributes or a separate configuration response that an external script reads.
- When changing existing inline output, check the rendered page as well as the source. Shared head hooks can emit tags that are not present in a template.

## Gutenberg content in app templates

Block content needs both core layout CSS and the assets discovered while rendering individual blocks. Prepare both before printing the head:

1. Call `wp_app_enqueue_block_styles()` to supply core default CSS. WpApp removes `global-styles`, which includes shared flex and grid rules; gallery/image block CSS alone does not replace them.
2. Render content through the `the_content` filter into a variable before calling `wp_app_head()` or including a header that calls it. Rendering discovers block styles, support styles, and script module dependencies so they can be printed in the head. The CSS helper does not perform this rendering step.
3. Print the saved content in the body and keep `wp_app_body_close()` at the end for footer assets and native lightbox markup.

Verify the rendered page: check gallery flex/grid rules, image sizing, and lightbox behavior in the browser. The presence of `*-inline-css` tags alone does not prove these work; those tags can contain complete block stylesheets. See [Rendering Gutenberg content](docs/gutenberg-content.md) for an example and diagnostic checks.
