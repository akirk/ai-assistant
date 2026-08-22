<?php

require_once __DIR__ . '/../src/Scaffolder.php';

use Akirk\CreateWpApp\Scaffolder;

$target_dir = getcwd() . '/my-playground-app';
$wp_app_source_dir = getenv( 'WP_APP_SOURCE_DIR' ) ?: __DIR__ . '/../vendor/akirk/wp-app';

if ( ! is_dir( $wp_app_source_dir ) ) {
    fwrite(
        STDERR,
        "Could not find akirk/wp-app at: $wp_app_source_dir\n" .
        "Run composer install in this repo, or set WP_APP_SOURCE_DIR=/path/to/akirk/wp-app.\n"
    );
    exit( 1 );
}

$result = Scaffolder::create( [
    'slug'              => 'my-playground-app',
    'plugin_name'       => 'My Playground App',
    'namespace'         => 'MyPlaygroundApp',
    'author'            => 'Demo User',
    'url_path'          => 'my-playground-app',
    'target_dir'        => $target_dir,
    'overwrite'         => true,
    'dependency_mode'   => 'copy',
    'autoload_mode'     => 'polyfill',
    'wp_app_source_dir' => $wp_app_source_dir,
] );

foreach ( $result['messages'] as $message ) {
    echo $message . PHP_EOL;
}

echo PHP_EOL;
echo "Created composer-less demo plugin at: $target_dir" . PHP_EOL;
echo "Inspect vendor/autoload.php and vendor/akirk/wp-app/ to see the polyfill flow." . PHP_EOL;
echo PHP_EOL;
echo "Test it with WordPress Playground:" . PHP_EOL;
echo "  cd my-playground-app && npx @wp-playground/cli@latest server --auto-mount --login" . PHP_EOL;
