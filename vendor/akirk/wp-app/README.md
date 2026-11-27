# WpApp Framework

A framework to build frontend apps on WordPress, each with its own URL space and user interface.

[Why?](#why-wordpress-for-web-applications) · [Quick Start](#quick-start) · [Examples](#examples) · [WP Apps Site](#the-wp-apps-site) · [Documentation](#documentation) · [Manual Installation](#manual-installation) · [Requirements](#requirements)

## Quick Start

Scaffold a new plugin with one command:

```bash
composer create-project akirk/create-wp-app my-app
```

Which gives you this little wizard:

```
Creating WpApp plugin: my-app
----------------------------------------

Plugin name [My App]: 
Namespace [MyApp]: 
Author name []: 
URL path [my-app]: 

Setup type:
  [m] Minimal - simple WpApp setup
  [f] Full - with BaseApp structure
Choose [f]: 

✓ Updated plugin-name.php
✓ Updated templates/index.php
✓ Updated src/App.php
✓ Renamed plugin-name.php to my-app.php
✓ Created .gitignore
✓ Updated composer.json
✓ Regenerated autoloader
✓ Updated README.md
✓ Cleaned up setup scripts

Done! Your plugin is ready.

Next steps:

  Option A: Run locally with WordPress Playground
    npx @wp-playground/cli@latest server --auto-mount=my-app --login

  Option B: Install in WordPress
    1. Move this folder to wp-content/plugins/
    2. Activate the plugin in WordPress
    3. Visit /my-app/ to see your app
```

Which gives you something like this:

<img width="788" height="681" alt="create-wp-app" src="https://github.com/user-attachments/assets/f0180015-96e9-4ae1-af64-1cec0bae9de1" />

## The WP Apps Site

Visit [wpapps.kirk.at](https://wpapps.kirk.at/) for a visual introduction to the WP Apps concept and a catalog of apps you can run on your own WordPress, including a travel app, a cookbook, and a family wiki.

## Examples

The examples are available in the [wp-app-examples repository](https://github.com/akirk/wp-app-examples). Try them instantly in your browser with WordPress Playground:

- **[Minimal App](https://github.com/akirk/wp-app-examples/tree/main/minimal-app)** — the smallest working app. [Try in Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/wp-app-examples/main/minimal-app.json).
- **[Community App](https://github.com/akirk/wp-app-examples/tree/main/community-app)** — BaseApp, database storage, user progress tracking, REST endpoints, and admin integration. [Try in Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/wp-app-examples/main/community-app.json).

## Why WordPress for Web Applications?

WordPress isn't just for blogs. It's a powerful foundation for web applications.

**For developers:**
- **Built-in User Management** - Authentication, roles, and permissions out of the box
- **Admin Interface** - Professional backend without building from scratch
- **Security & Updates** - Automatic security patches and proven practices
- **Global Ready** - Built-in i18n and multisite support

**For users:**
- **Universal Hosting** - Deploy anywhere WordPress runs, from shared hosting to cloud
- **Familiar Environment** - Manage your app alongside your existing WordPress site

## Features

- **Own URL Space, Private by Default** - Each app owns a subdirectory of your WordPress site, such as `/my-app/`, and handles the routes beneath it. App pages require login by default; apps can be fully public or combine public pages with routes protected by WordPress capabilities. See [Routing](docs/routing.md) and [Access Control](docs/access-control.md), including separate controls for REST data access.
- **A Blank Canvas for Your UI** - App templates run independently of the active WordPress theme. Bring your own HTML, CSS, and JavaScript to control the layout, styling, and interactions while using WordPress users, data, and APIs. See [Templates](docs/templates.md) and [Assets](docs/assets.md).
- **[Admin Bar Integration](docs/masterbar.md)** - WordPress-style navigation for your app
- **[Access Control](docs/access-control.md)** - WordPress capability-based authentication
- **[Offline PWA Support](docs/offline-pwa.md)** - App-scoped manifests and service workers for installable apps
- **[BaseApp Pattern](src/abstract-baseapp.php)** - Structured architecture for complex applications
- **[BaseStorage Pattern](src/BaseStorage.php)** - Database abstraction with schema management using `dbDelta`

## Local Development

Run your plugin locally without a full WordPress installation:

```bash
npx @wp-playground/cli@latest server --auto-mount --login
```

This starts a local WordPress instance with your plugin mounted and activated.

## Documentation

Use the [documentation index](docs/README.md) to find guides by task.

- **Start building:** [Getting Started](docs/getting-started.md), [Tutorial](TUTORIAL.md), [BaseApp Pattern](docs/baseapp.md)
- **Build pages:** [Routing](docs/routing.md), [Templates](docs/templates.md), [Assets](docs/assets.md), [Gutenberg Content](docs/gutenberg-content.md)
- **Customize the app:** [Configuration](docs/configuration.md), [Masterbar](docs/masterbar.md), [Themes](docs/themes.md), [Colors](docs/colors.md)
- **Work with data and integrations:** [Access Control](docs/access-control.md), [Abilities](docs/abilities.md), [Offline PWA](docs/offline-pwa.md), [Encryption](docs/encryption.md)

## Claude Code Integration

Get AI assistance for WpApp development in [Claude Code](https://claude.ai/code):

```bash
/plugin marketplace add akirk/wp-app
/plugin install wpapp@wp-app
```

## Manual Installation

```bash
composer require akirk/wp-app
```

The simplest possible WpApp requires just 3 lines:

```php
<?php
/**
 * Plugin Name: Minimal App
 */

require_once __DIR__ . '/vendor/autoload.php';
use WpApp\WpApp;

$app = new WpApp( __DIR__ . '/templates', 'minimal' );
$app->init();
```

Create `templates/index.php`:

```php
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
	<title><?php wp_app_the_title( 'My App' ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body>
	<?php wp_app_body_open(); ?>
	<h1>Welcome to My App!</h1>
	<?php wp_app_body_close(); ?>
</body>
</html>
```

Your app is now available at `/minimal`!

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher

## Tests and Coding Standards

Run the test suite and WordPress coding standards checks:

```bash
composer test
composer phpcs
```

## License

GPL-2.0-or-later

For shared application shells, safe bootstrap, local data, offline caching, and durable operation queues, see [Offline shells and outboxes](docs/offline.md).
