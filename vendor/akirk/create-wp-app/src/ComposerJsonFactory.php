<?php

namespace Akirk\CreateWpApp;

class ComposerJsonFactory {
    public function create( array $base_composer_json, array $config ): array {
        $composer_json = $base_composer_json;
        $is_full_setup = $config['setup_type'] === 'full';

        $composer_json['name'] = $config['slug'] . '/' . $config['slug'];
        $composer_json['version'] = '0.1.0';
        $composer_json['description'] = $config['plugin_name'] . ' - A WordPress app powered by WpApp';
        unset( $composer_json['scripts'] );

        if ( ! empty( $config['author'] ) ) {
            $composer_json['authors'] = [ [ 'name' => $config['author'] ] ];
        } else {
            unset( $composer_json['authors'] );
        }

        if ( $is_full_setup ) {
            $composer_json['autoload']['psr-4'] = [ $config['namespace'] . '\\' => 'src/' ];
        } else {
            unset( $composer_json['autoload'] );
        }

        $composer_json['config']['autoloader-suffix'] = preg_replace( '/[^a-zA-Z0-9]/', '', $config['slug'] );

        return $composer_json;
    }
}
