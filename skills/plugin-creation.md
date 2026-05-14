---
title: Manual WordPress Plugin Creation
description: Create ordinary WordPress plugins when no app-style UI or plugin-creation ability applies
category: plugins
---

# Manual WordPress Plugin Creation

Use this when the user asks for an ordinary WordPress plugin that does not need its own app-like interface, route, dashboard workflow, logged-in experience, or standalone screen.

Do not use this for app-like plugins. For app-like plugins, use the `wp-app` skill and the `create-wp-app` ability flow instead.

## Rules

- Always create plugins in their own subdirectory, for example `plugins/gallery-mywp/gallery-mywp.php`.
- Never create single-file plugins directly in the `plugins` folder.
- Use the suffix `-mywp` for manually created plugin slugs, for example `gallery-mywp` or `contact-form-mywp`.
- Keep the main plugin file focused on bootstrap code. Put substantial logic in included files or classes.
- Add a standard plugin header with `Plugin Name`, `Description`, `Version`, and `Author` when appropriate.
- Prefer WordPress hooks, filters, REST routes, shortcodes, settings APIs, blocks, or admin pages that match the user's requested behavior.
- Before overwriting existing plugin files, read the current files and preserve unrelated user changes.

## Safety

- Avoid activating newly written plugin code until PHP syntax has been checked when a lint tool is available.
- If a change may affect public content, user data, permissions, or database writes, explain the change clearly before execution so the user can approve it.
- Use WordPress-native APIs instead of direct database writes unless the user explicitly needs a custom report or migration.
