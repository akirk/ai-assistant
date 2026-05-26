<?php

namespace Akirk\CreateWpApp;

class DependencyCopier {
    public function copy_wp_app( string $target_dir, bool $overwrite = true, ?string $source_dir = null ): void {
        $source_dir = $source_dir ?: $this->find_wp_app_source();

        if ( $source_dir === null || ! is_dir( $source_dir ) ) {
            throw new \RuntimeException( 'Could not find akirk/wp-app to copy into the generated app.' );
        }

        $destination = $target_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'akirk' . DIRECTORY_SEPARATOR . 'wp-app';
        if ( is_dir( $destination ) ) {
            if ( ! $overwrite ) {
                return;
            }

            $this->remove_directory( $destination );
        }

        $this->copy_directory( $source_dir, $destination );
    }

    private function find_wp_app_source(): ?string {
        $candidates = [
            dirname( __DIR__ ) . '/vendor/akirk/wp-app',
            dirname( __DIR__, 2 ) . '/akirk/wp-app',
        ];

        foreach ( $candidates as $candidate ) {
            if ( is_dir( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    private function copy_directory( string $source, string $destination ): void {
        if ( ! is_dir( $destination ) ) {
            mkdir( $destination, 0777, true );
        }

        foreach ( scandir( $source ) as $entry ) {
            if ( in_array( $entry, [ '.', '..', '.git' ], true ) ) {
                continue;
            }

            $source_path = $source . DIRECTORY_SEPARATOR . $entry;
            $destination_path = $destination . DIRECTORY_SEPARATOR . $entry;

            if ( is_dir( $source_path ) ) {
                $this->copy_directory( $source_path, $destination_path );
            } else {
                copy( $source_path, $destination_path );
            }
        }
    }

    private function remove_directory( string $directory ): void {
        foreach ( scandir( $directory ) as $entry ) {
            if ( in_array( $entry, [ '.', '..' ], true ) ) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if ( is_dir( $path ) ) {
                $this->remove_directory( $path );
            } else {
                unlink( $path );
            }
        }

        rmdir( $directory );
    }
}
