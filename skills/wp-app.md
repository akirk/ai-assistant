---
title: Create a WpApp WordPress App
description: Create a WordPress app plugin through the create-wp-app WordPress Ability API integration
category: apps
---

# Creating a WpApp WordPress App

Use this when the user asks to create a "wp app", "WordPress app", WpApp app, or WpApp-powered plugin.

Do not hand-write the scaffold first. Use the WordPress Ability API integration from `akirk/create-wp-app` so the package owns scaffolding, dependency copying, file layout, and future template changes.

## Workflow

1. Use the `ability` tool to list abilities with category `create-wp-app`.
2. Load `create-wp-app/scaffold`.
3. Ask only for required values that cannot be inferred.
4. Execute the ability with structured arguments.
5. Activate the generated plugin if requested or if the ability supports `activate`.
6. Visit `/{url_path}/` to verify the route. If it 404s, flush rewrite rules once.

Expected ability ID: `create-wp-app/scaffold`.

## Inputs

Infer defaults when reasonable:

| Argument | Default rule |
|----------|--------------|
| `slug` | Lowercase kebab-case folder name, e.g. `my-app` |
| `plugin_name` | Title case from slug, e.g. `My App` |
| `namespace` | PascalCase from plugin name, e.g. `MyApp` |
| `author` | Empty unless the user provides it |
| `url_path` | Same as slug, without leading slash |
| `setup_type` | `minimal` unless the user asks for routes/classes/storage |
| `activate` | `true` when the user wants to try the app immediately |

Do not expose arbitrary target paths unless the ability explicitly supports and validates them. Generated apps should live under `wp-content/plugins/{slug}/`.

## Ability Call

Typical call:

```json
{
  "slug": "my-app",
  "plugin_name": "My App",
  "namespace": "MyApp",
  "author": "",
  "url_path": "my-app",
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

## Fallback Rules

- Inside WordPress Playground, do not run Composer.
- If the ability is unavailable, explain that the `akirk/create-wp-app` dependency or the WordPress Abilities API is not installed or not loaded.
- Do not manually scaffold a WpApp plugin unless the user explicitly asks for a temporary fallback.
- If using the fallback, the generated plugin must be standalone: it must contain its own WpApp source and autoloader, not depend on AI Assistant staying installed.
