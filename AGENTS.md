# Development

To run the plugin locally using WordPress Playground:

```bash
npx @wp-playground/cli server --auto-mount
```

This starts a local WordPress Playground instance and automatically mounts the plugin directory, so any changes you make to the source files are reflected immediately.

## Testing with Playwright

Use the Playwright MCP to interact with the running WordPress Playground instance for manual/visual testing.

- **Base URL**: `http://127.0.0.1:9400`
- **Login**: username `admin`, password `password`
- **Settings page**: `http://127.0.0.1:9400/wp-admin/admin.php?page=ai-assistant-settings`

Use `browser_snapshot` (not screenshots) for interacting with page elements. Use `browser_navigate` to visit pages, `browser_click`/`browser_fill_form`/`browser_type` to interact, and `browser_evaluate` for direct JS when needed (e.g. clearing localStorage values).
