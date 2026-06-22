<?php
/**
 * Post-create-project configuration script.
 * Prompts for project details and delegates scaffolding to Scaffolder.
 *
 * Supports non-interactive mode via environment variables:
 *   WP_APP_PLUGIN_NAME  - Plugin name (default: derived from slug)
 *   WP_APP_NAMESPACE    - PHP namespace (default: derived from plugin name)
 *   WP_APP_AUTHOR       - Plugin author display name
 *   WP_APP_URL_PATH     - URL path for the app (default: slug)
 *   WP_APP_SETUP_TYPE   - "minimal" or "full" (also accepts "m" or "f"; default: full)
 *   WP_APP_OVERWRITE    - "0" to reject a non-empty target directory (default: 1)
 *   WP_APP_DEPENDENCY_MODE - "composer" or "copy" (default: composer)
 *   WP_APP_AUTOLOAD_MODE   - "composer" or "polyfill" (default: composer)
 */

use Akirk\CreateWpApp\Scaffolder;

require_once __DIR__ . '/../src/Scaffolder.php';

$is_interactive = getenv( 'COMPOSER_NO_INTERACTION' ) !== '1'
    && stream_isatty( STDIN );

function get_value( string $env_key, string $question, ?string $default, bool $interactive ): string {
    $env_value = $env_key !== '' ? getenv( $env_key ) : false;
    if ( $env_value !== false && $env_value !== '' ) {
        return $env_value;
    }

    if ( ! $interactive ) {
        return $default ?? '';
    }

    $default_text = $default !== null ? " [$default]" : '';
    echo "$question$default_text: ";
    $answer = trim( fgets( STDIN ) );
    return $answer !== '' ? $answer : ( $default ?? '' );
}

$target_dir = getcwd();
$slug = basename( $target_dir );

echo "\n";
echo "Creating WpApp plugin: $slug\n";
echo str_repeat( '-', 40 ) . "\n\n";

$plugin_name = get_value( 'WP_APP_PLUGIN_NAME', 'Plugin name', Scaffolder::slug_to_title( $slug ), $is_interactive );
$namespace = get_value( 'WP_APP_NAMESPACE', 'Namespace', Scaffolder::to_namespace( $plugin_name ), $is_interactive );
$author = get_value( 'WP_APP_AUTHOR', 'Author name', '', $is_interactive );
$url_path = get_value( 'WP_APP_URL_PATH', 'URL path', $slug, $is_interactive );

$setup_type_env = getenv( 'WP_APP_SETUP_TYPE' );
if ( $setup_type_env !== false && in_array( $setup_type_env, [ 'minimal', 'full', 'm', 'f' ], true ) ) {
    $setup_type = $setup_type_env;
} elseif ( $is_interactive ) {
    echo "\n";
    echo "Setup type:\n";
    echo "  [m] Minimal - simple WpApp setup\n";
    echo "  [f] Full - with BaseApp structure\n";
    $setup_type = get_value( '', 'Choose', 'f', true );
} else {
    $setup_type = 'full';
}

$overwrite_env = getenv( 'WP_APP_OVERWRITE' );
$overwrite = $overwrite_env === false ? true : ! in_array( $overwrite_env, [ '0', 'false', 'no' ], true );
$dependency_mode = getenv( 'WP_APP_DEPENDENCY_MODE' ) ?: 'composer';
$autoload_mode = getenv( 'WP_APP_AUTOLOAD_MODE' ) ?: 'composer';

echo "\n";

$scaffolder = new Scaffolder();
$result = $scaffolder->scaffold( [
    'slug' => $slug,
    'plugin_name' => $plugin_name,
    'namespace' => $namespace,
    'author' => $author,
    'url_path' => $url_path,
    'setup_type' => $setup_type,
    'target_dir' => $target_dir,
    'overwrite' => $overwrite,
    'dependency_mode' => $dependency_mode,
    'autoload_mode' => $autoload_mode,
] );

foreach ( $result['messages'] as $message ) {
    echo "$message\n";
}

echo "\n";
echo "Done! Your plugin is ready.\n";
echo "\n";

echo "Next steps:\n";
echo "\n";
echo "  Option A: Run locally with WordPress Playground\n";
echo "    npx @wp-playground/cli@latest server --auto-mount=$slug --login\n";
echo "\n";
echo "  Option B: Install in WordPress\n";
$step = 1;
if ( ! $result['in_plugins_dir'] ) {
    echo "    $step. Move this folder to wp-content/plugins/\n";
    $step++;
}
echo "    $step. Activate the plugin in WordPress\n";
$step++;
echo "    $step. Visit /$url_path/ to see your app\n";
echo "\n";
