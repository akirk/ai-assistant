<?php

namespace Akirk\CreateWpApp;

class AutoloadPolyfillFactory {
    public function write( string $target_dir ): void {
        $vendor_dir = $target_dir . DIRECTORY_SEPARATOR . 'vendor';
        if ( ! is_dir( $vendor_dir ) ) {
            mkdir( $vendor_dir, 0777, true );
        }

        file_put_contents( $vendor_dir . DIRECTORY_SEPARATOR . 'autoload.php', $this->get_autoload_php() );
    }

    private function get_autoload_php(): string {
        return <<<'PHP'
<?php

$root_dir = dirname( __DIR__ );
$vendor_dir = __DIR__;

$load_composer_json = static function( string $path ): array {
    if ( ! file_exists( $path ) ) {
        return [];
    }

    $json = json_decode( file_get_contents( $path ), true );
    return is_array( $json ) ? $json : [];
};

$normalize_path = static function( string $path ): string {
    return str_replace( '\\', '/', rtrim( $path, '/\\' ) );
};

$is_path_inside = static function( string $path, string $base_dir ) use ( $normalize_path ): bool {
    $real_path = realpath( $path );
    $real_base_dir = realpath( $base_dir );

    if ( $real_path === false || $real_base_dir === false ) {
        return false;
    }

    $real_path = $normalize_path( $real_path );
    $real_base_dir = $normalize_path( $real_base_dir );

    return $real_path === $real_base_dir || strpos( $real_path . '/', $real_base_dir . '/' ) === 0;
};

$autoloads = [];
$root_composer = $load_composer_json( $root_dir . '/composer.json' );
if ( isset( $root_composer['autoload'] ) && is_array( $root_composer['autoload'] ) ) {
    $autoloads[] = [ $root_dir, $root_composer['autoload'] ];
}

$wp_app_dir = $vendor_dir . '/akirk/wp-app';
$wp_app_composer = $load_composer_json( $wp_app_dir . '/composer.json' );
if ( isset( $wp_app_composer['autoload'] ) && is_array( $wp_app_composer['autoload'] ) ) {
    $autoloads[] = [ $wp_app_dir, $wp_app_composer['autoload'] ];
}

$prefixes = [];
foreach ( $autoloads as $entry ) {
    list( $base_dir, $autoload ) = $entry;

    foreach ( $autoload['files'] ?? [] as $file ) {
        $path = $base_dir . '/' . $file;
        if ( is_file( $path ) && $is_path_inside( $path, $base_dir ) ) {
            require_once $path;
        }
    }

    foreach ( $autoload['psr-4'] ?? [] as $prefix => $paths ) {
        foreach ( (array) $paths as $path ) {
            $dir = $base_dir . '/' . $path;
            if ( is_dir( $dir ) && $is_path_inside( $dir, $base_dir ) ) {
                $prefixes[$prefix][] = rtrim( $dir, '/\\' ) . '/';
            }
        }
    }
}

uksort( $prefixes, static function( string $a, string $b ): int {
    return strlen( $b ) <=> strlen( $a );
} );

spl_autoload_register( static function( string $class ) use ( $prefixes, $is_path_inside ): void {
    if ( ! preg_match( '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)*[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $class ) ) {
        return;
    }

    foreach ( $prefixes as $prefix => $dirs ) {
        $length = strlen( $prefix );
        if ( strncmp( $prefix, $class, $length ) !== 0 ) {
            continue;
        }

        $relative_class = substr( $class, $length );
        $relative_file = str_replace( '\\', '/', $relative_class ) . '.php';

        foreach ( $dirs as $dir ) {
            $file = $dir . $relative_file;
            if ( is_file( $file ) && $is_path_inside( $file, $dir ) ) {
                require $file;
                return;
            }
        }
    }
} );

return true;
PHP;
    }
}
