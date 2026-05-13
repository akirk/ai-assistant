---
title: Create a WordPress App Plugin
description: Create an app-like WordPress plugin through the create-wp-app WordPress Ability API integration
category: apps
requires_class: \Akirk\CreateWpApp\Scaffolder
---

# Creating a WordPress App Plugin

Use this when the user asks to create an app, web app, "wp app", "WordPress app", WpApp app, or app-like WordPress plugin.

Also use it when the user asks to create a plugin that sounds like an app: something with its own URL route, screen, dashboard, workflow, logged-in experience, data UI, or standalone interface. Do not use it for narrow infrastructure plugins that only add hooks, filters, blocks, shortcodes, REST endpoints, or admin settings with no app-style UI.

Do not hand-write the scaffold first. Use the WordPress Ability API integration from `akirk/create-wp-app` so the package owns scaffolding, dependency copying, file layout, and future template changes.

## Workflow

1. Use the `ability` tool to list abilities with category `create-wp-app`.
2. Load `create-wp-app/scaffold`.
3. Ask only for required values that cannot be inferred.
4. Execute the ability with structured arguments.
5. Activate the generated plugin if requested or if the ability supports `activate`.
6. Continue the current build flow in-place. Do not use the `navigate` tool to visit the generated app route during scaffolding or intermediate implementation work.
7. In the final response, report the returned `url` so the user can open it when ready.

Navigation changes the browser page and can interrupt the assistant workflow. Only navigate to the generated app when the user explicitly asks to open or visually test it. If route verification is needed later, do it as a separate final verification step after file changes are complete and after confirming that navigation is acceptable.

Expected ability ID: `create-wp-app/scaffold`.

## Inputs

Infer defaults when reasonable:

| Argument | Default rule |
|----------|--------------|
| `slug` | Lowercase kebab-case folder name for the product/domain, e.g. `timetable`; do not include the generic word `app` or use an `-app` suffix |
| `plugin_name` | Human name from the product/domain, e.g. `Timetable`; do not add the generic word `App` unless the user explicitly names it that way |
| `namespace` | PascalCase from plugin name, e.g. `Timetable` |
| `author` | Empty unless the user provides it |
| `url_path` | Same as slug, without leading slash |
| `setup_type` | `minimal` unless the user asks for routes/classes/storage |
| `activate` | `true` when the user wants to try the app immediately |

When the user says "create a {thing} app", treat "app" as the type of work, not part of the name. For example, "create a timetable app" should use `slug: "timetable"`, `plugin_name: "Timetable"`, and `url_path: "timetable"`, not `timetable-app`.

Do not expose arbitrary target paths unless the ability explicitly supports and validates them. Generated apps should live under `wp-content/plugins/{slug}/`.

## Ability Call

Typical call:

```json
{
  "slug": "timetable",
  "plugin_name": "Timetable",
  "namespace": "Timetable",
  "author": "",
  "url_path": "timetable",
  "setup_type": "minimal",
  "activate": true,
  "overwrite": false
}
```

The ability returns:

- `plugin_dir`
- `plugin_file`
- `plugin_slug`
- `url_path`
- `url`
- `created_files`
- `activated`
- `messages`
- `warnings`

AI Assistant's bridge fixes the generated target to `wp-content/plugins/{slug}` and calls `create-wp-app` in no-Composer mode: `dependency_mode=copy` and `autoload_mode=polyfill`. The generated plugin is self-contained and can later replace the polyfill by running Composer.

Do not immediately navigate to the returned `url`. Treat it as output to report or use after the app build is complete.

## App Guidance

When extending the generated app, keep it friendly to AI Assistant, My Apps, and WordPress admin color schemes.

### AI Assistant

- For app-specific data and actions, register WordPress Abilities instead of making AI Assistant use `run_php` or `db_query`.
- Follow the WordPress Abilities API handbook for ability categories, schemas, and permission callbacks.
- Follow `wp-content/plugins/ai-assistant/docs/plugin-integration.md` for AI Assistant-specific domains, annotations, browser callbacks, and post-result instructions.
- Register an `ai_assistant_ability_domains` filter with the app's domain keywords so AI Assistant considers the app's abilities specifically for relevant requests.
- Use `ai_assistant_ability_instructions` when ability results need a specific presentation or follow-up workflow.
- Return structured arrays or `WP_Error`; include input/output schemas and mark abilities with `readonly`, `destructive`, and `idempotent` annotations.

### My Apps

- Keep WpApp's `my_apps` integration enabled so the app appears in the `/my-apps/` launcher.
- Set `app_name` to the human app name and provide `my_apps_icon` when a stable icon URL is available.
- If adding compatibility outside WpApp, filter `my_apps_plugins` and return an entry keyed by slug with `name`, `icon_url`, and `url`.

Example:

```php
add_filter( 'my_apps_plugins', function( $apps ) {
    $apps['timetable'] = [
        'name'     => __( 'Timetable', 'timetable' ),
        'icon_url' => plugins_url( 'assets/icon.png', __FILE__ ),
        'url'      => home_url( '/timetable/' ),
    ];
    return $apps;
} );
```

### Styling

- Use WpApp CSS variables instead of hard-coded brand/admin colors so the app follows the user's WordPress admin color scheme.
- Preferred tokens include `--wp-app-color-primary`, `--wp-app-color-primary-hover`, `--wp-app-color-accent`, `--wp-app-color-background`, `--wp-app-color-surface`, `--wp-app-color-surface-alt`, `--wp-app-color-text`, `--wp-app-color-muted`, `--wp-app-color-border`, `--wp-app-color-link`, `--wp-app-color-link-hover`, and `--wp-app-color-focus`.
- For masterbar-adjacent UI, use `--wp-app-masterbar-background`, `--wp-app-masterbar-highlight`, and `--wp-app-masterbar-text`.

## Fallback Rules

- Inside WordPress Playground, do not run Composer.
- If the ability is unavailable, explain that the `akirk/create-wp-app` dependency or the WordPress Abilities API is not installed or not loaded.
- Do not manually scaffold a WpApp plugin unless the user explicitly asks for a temporary fallback.
- If using the fallback, the generated plugin must be standalone: it must contain its own WpApp source and autoloader, not depend on AI Assistant staying installed.
