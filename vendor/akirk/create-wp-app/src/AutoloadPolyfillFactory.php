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

    public function get_autoload_php(): string {
        return Scaffolder::snippet( 'autoload-polyfill.php' );
    }
}
