# create-wp-app

Scaffold a WordPress plugin powered by [WpApp](https://github.com/akirk/wp-app).

## Usage

```bash
composer create-project akirk/create-wp-app my-plugin
```

This prompts you for:

- **Plugin name** — Display name for your plugin
- **Namespace** — PHP namespace for your classes
- **Author** — Plugin author (optional)
- **URL path** — Where your app lives (e.g., `/my-plugin/`)
- **Setup type** — Full by default, or Minimal for a small direct `WpApp` setup

The default generated app uses the structured `BaseApp` scaffold:

```text
my-plugin/
├── my-plugin.php      # Main plugin file
├── src/
│   └── App.php        # BaseApp subclass with routes, menu, and lifecycle hooks
├── templates/
│   └── index.php
├── composer.json
└── .gitignore
```

## Screenshot

<img width="788" height="681" alt="create-wp-app" src="https://github.com/user-attachments/assets/f0180015-96e9-4ae1-af64-1cec0bae9de1" />

## Setup Types

### Full

The default setup for generated apps:

```text
my-plugin/
├── my-plugin.php      # Main plugin file
├── src/
│   └── App.php        # BaseApp subclass with routes, menu, and lifecycle hooks
├── templates/
│   └── index.php
├── composer.json
└── .gitignore
```

### Minimal

A smaller direct `WpApp` setup:

```text
my-plugin/
├── my-plugin.php      # Main plugin file with WpApp initialization
├── templates/
│   └── index.php      # Your app's home page
├── composer.json
└── .gitignore
```

## Non-Interactive Mode

For CI/CD or scripting, use environment variables:

```bash
WP_APP_PLUGIN_NAME="My App" \
WP_APP_NAMESPACE="MyApp" \
WP_APP_AUTHOR="Your Name" \
WP_APP_URL_PATH="my-app" \
WP_APP_SETUP_TYPE="full" \
WP_APP_OVERWRITE="1" \
WP_APP_DEPENDENCY_MODE="composer" \
WP_APP_AUTOLOAD_MODE="composer" \
composer create-project --no-interaction akirk/create-wp-app my-plugin
```

The command above creates a `my-plugin/` directory, configures the plugin from the environment variables, and removes the setup script:

```text
my-plugin/
├── my-plugin.php       # Main plugin file for "My App"
├── src/
│   └── App.php         # App lifecycle extension points
├── templates/
│   └── index.php       # App home page shown at /my-app/
├── vendor/
│   └── autoload.php    # Composer-generated autoloader
├── composer.json       # Generated package metadata for my-plugin/my-plugin
├── README.md
└── .gitignore
```

`WP_APP_SETUP_TYPE` defaults to `full`. Use `minimal` only when you want the small direct `WpApp` setup.

## Programmatic Usage

Use `Akirk\CreateWpApp\Scaffolder` when another tool, CLI, or WordPress ability needs to create the app without reimplementing file generation. If `target_dir` does not exist, the scaffolder creates it and seeds the base plugin files before applying the config. Set `overwrite` to `false` to reject a non-empty target directory before any files are generated.

### Normal Composer Project

This mode writes the generated app's `composer.json` and runs `composer dump-autoload`:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Akirk\CreateWpApp\Scaffolder;

$result = Scaffolder::create( [
    'slug'            => 'my-app',
    'plugin_name'     => 'My App',
    'namespace'       => 'MyApp',
    'author'          => 'Your Name',
    'url_path'        => 'my-app',
    // Created automatically if it does not exist.
    'target_dir'      => '/path/to/wp-content/plugins/my-app',
    'overwrite'       => true,
    'dependency_mode' => 'composer',
    'autoload_mode'   => 'composer',
] );

foreach ( $result['messages'] as $message ) {
    echo $message . PHP_EOL;
}
```

The scaffolder creates this full plugin:

```text
/path/to/wp-content/plugins/my-app/
├── my-app.php          # Main plugin file; requires vendor/autoload.php
├── src/
│   └── App.php         # BaseApp subclass for routes, menu, and lifecycle hooks
├── templates/
│   └── index.php       # App home page shown at /my-app/
├── vendor/
│   └── autoload.php    # Composer-generated autoloader
├── composer.json       # Normal Composer project requiring akirk/wp-app
├── README.md
└── .gitignore
```

### No-Composer / Playground Project

This mode still writes a normal `composer.json`, but it also copies `akirk/wp-app` into the generated app and creates a Composer-lite `vendor/autoload.php`:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Akirk\CreateWpApp\Scaffolder;

$result = Scaffolder::create( [
    'slug'              => 'my-app',
    'plugin_name'       => 'My App',
    'namespace'         => 'MyApp',
    'author'            => 'Your Name',
    'url_path'          => 'my-app',
    // Created automatically if it does not exist.
    'target_dir'        => '/path/to/wp-content/plugins/my-app',
    'overwrite'         => true,
    'dependency_mode'   => 'copy',
    'autoload_mode'     => 'polyfill',
    // Optional. If omitted, create-wp-app looks in its own vendor tree.
    'wp_app_source_dir' => '/path/to/create-wp-app/vendor/akirk/wp-app',
] );
```

The generated plugin code is the same in both modes: it requires `vendor/autoload.php`. If the user later runs `composer install` or `composer dump-autoload`, Composer replaces the polyfill with the real autoloader.

With the Playground example above, the scaffolder creates this self-contained plugin:

```text
/path/to/wp-content/plugins/my-app/
├── my-app.php              # Main plugin file; still requires vendor/autoload.php
├── src/
│   └── App.php
├── templates/
│   └── index.php
├── vendor/
│   ├── autoload.php        # create-wp-app generated Composer-lite autoloader
│   └── akirk/
│       └── wp-app/         # Copied dependency used by the autoload polyfill
├── composer.json           # Normal Composer project metadata remains present
├── README.md
└── .gitignore
```

The polyfill intentionally implements only the runtime pieces this scaffold needs; Composer can replace it later with a normal generated autoloader.

## Assistant Guidance

Generated apps include lifecycle extension points. Do not register post types, taxonomies, rewrite rules, dashboard widgets, REST routes, or other WordPress-hooked features directly inside `__construct()`; attach WordPress hooks there and run registration from the proper hook.

Prefer custom post types, post meta, taxonomies, terms, term meta, and user meta before custom tables. Use custom tables and `BaseStorage` only when native WordPress storage does not fit.

For AI Assistant integrations, register focused WordPress Abilities with strict input/output schemas and annotations, then add `ai_assistant_ability_domains` and `ai_assistant_ability_instructions` filters when they help the assistant discover or present app-specific actions. See https://github.com/akirk/ai-assistant/blob/main/docs/plugin-integration.md for further guidance.

After modifying PHP, run a syntax or runtime check before navigating the app.

## After Setup

1. Move the folder to `wp-content/plugins/` (if not already there)
2. Activate the plugin in WordPress
3. Visit your app at the URL path you configured

## Documentation

See the [WpApp documentation](https://github.com/akirk/wp-app/blob/main/README.md) for details on routing, the masterbar, access control, and more.

## License

GPL-2.0-or-later
