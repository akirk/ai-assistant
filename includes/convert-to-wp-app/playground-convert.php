<?php
/**
 * Vendored copy of scripts/playground-convert.php from
 * https://github.com/akirk/convert-to-wp-app (not on Packagist). Keep in sync
 * with upstream rather than editing here; Wp_App_Abilities calls its functions.
 */
/**
 * Convert checked-out static files into a self-contained WpApp plugin.
 *
 * This file is designed to be checked out by a WordPress Playground Blueprint
 * and then required from runPHP.
 */

if ( ! function_exists( 'convert_to_wp_app_playground' ) ) {
	function convert_to_wp_app_playground( array $config ): array {
		$slug        = convert_to_wp_app_slug( $config['slug'] ?? basename( $config['plugin_dir'] ?? 'wp-app' ) );
		$plugin_dir  = convert_to_wp_app_normalize_dir( $config['plugin_dir'] ?? convert_to_wp_app_default_plugins_dir() . '/' . $slug );
		$plugins_dir = convert_to_wp_app_normalize_dir( $config['plugins_dir'] ?? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( $plugin_dir ) ) );
		$plugin_dir  = convert_to_wp_app_normalize_plugin_dir( $plugin_dir, $plugins_dir );
		$plugin_name = trim( (string) ( $config['plugin_name'] ?? convert_to_wp_app_title( $slug ) ) );
		$plugin_name = $plugin_name !== '' ? $plugin_name : convert_to_wp_app_title( $slug );
		$plugin_author = trim( (string) ( $config['plugin_author'] ?? '' ) );
		$url_path    = trim( (string) ( $config['url_path'] ?? $slug ), "/ \t\n\r\0\x0B" );
		$url_path    = $url_path !== '' ? $url_path : $slug;
		$allow_php_source = ! isset( $config['allow_php_source'] ) || ! in_array( (string) $config['allow_php_source'], array( '0', 'false', 'no' ), true );
		$source_public_path = trim( (string) ( $config['source_public_path'] ?? '' ) );
		$source      = convert_to_wp_app_resolve_source( $config['source_build_dir'] ?? '', $plugin_dir, $allow_php_source );
		$source_dir  = $source['dir'];
		$wp_app_dir  = convert_to_wp_app_normalize_dir( $config['wp_app_source_dir'] ?? $plugins_dir . '/__wp_app_runtime' );

		convert_to_wp_app_mkdir( $plugin_dir );
		if ( ! is_dir( $wp_app_dir . '/src' ) && ! is_file( $wp_app_dir . '/class-wpapp.php' ) ) {
			throw new RuntimeException( "WpApp source directory does not exist: {$wp_app_dir}" );
		}

		$asset_dir = $plugin_dir . '/app';
		if ( is_dir( $asset_dir ) ) {
			convert_to_wp_app_remove_directory( $asset_dir );
		}
		convert_to_wp_app_mkdir( $asset_dir );

		convert_to_wp_app_copy_directory( $source_dir, $asset_dir, array( '.git', 'node_modules', 'vendor' ) );
		convert_to_wp_app_rewrite_copied_asset_files( $asset_dir, $slug, $plugin_dir, $source_public_path );

		if ( $source['type'] === 'php-onepager' ) {
			$template = convert_to_wp_app_create_php_onepager_template( $source['entry'], $slug, $url_path );
		} else {
			$index = file_get_contents( $source_dir . '/index.html' );
			if ( $index === false ) {
				throw new RuntimeException( "Could not read {$source_dir}/index.html" );
			}

			$template = convert_to_wp_app_create_template( $index, $slug, $source_public_path );
		}
		convert_to_wp_app_mkdir( $plugin_dir . '/templates' );
		file_put_contents( $plugin_dir . '/templates/index.php', $template );

		convert_to_wp_app_copy_wp_app_runtime( $wp_app_dir, $plugin_dir );
		file_put_contents( $plugin_dir . '/vendor/autoload.php', convert_to_wp_app_autoload_php() );
		file_put_contents( $plugin_dir . '/' . $slug . '.php', convert_to_wp_app_plugin_php( $slug, $plugin_name, $url_path, $plugin_author ) );

		if ( function_exists( 'activate_plugin' ) ) {
			$result = activate_plugin( $slug . '/' . $slug . '.php' );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}

		convert_to_wp_app_cleanup_dirs( $config['cleanup_dirs'] ?? array(), $plugins_dir, $plugin_dir );

		return array(
			'slug'       => $slug,
			'plugin_dir' => $plugin_dir,
			'url_path'   => $url_path,
			'url'        => function_exists( 'home_url' ) ? home_url( '/' . trim( $url_path, '/' ) . '/' ) : '/' . trim( $url_path, '/' ) . '/',
		);
	}

	function convert_to_wp_app_slug( string $value ): string {
		$value = strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $value ) );
		$value = trim( $value, '-' );
		return $value !== '' ? $value : 'wp-app';
	}

	function convert_to_wp_app_title( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	function convert_to_wp_app_default_plugins_dir(): string {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return WP_PLUGIN_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/plugins';
		}
		return getcwd();
	}

	function convert_to_wp_app_normalize_dir( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	function convert_to_wp_app_normalize_plugin_dir( string $path, string $plugins_dir ): string {
		$path = convert_to_wp_app_normalize_dir( $path );
		$base = convert_to_wp_app_normalize_dir( $plugins_dir );
		if ( strpos( $path . '/', $base . '/' ) !== 0 ) {
			throw new RuntimeException( "Path must be inside wp-content/plugins: {$path}" );
		}
		return $path;
	}

	function convert_to_wp_app_resolve_source( string $source_build_dir, string $plugin_dir, bool $allow_php_source = true ): array {
		$candidates = array();
		if ( $source_build_dir !== '' ) {
			$candidates[] = rtrim( str_replace( '\\', '/', $source_build_dir ), '/' );
		}
		foreach ( array( $plugin_dir . '/build', $plugin_dir . '/dist' ) as $candidate ) {
			if ( is_file( $candidate . '/index.html' ) ) {
				return array(
					'type' => 'static-html',
					'dir'  => $candidate,
				);
			}
		}
		$candidates[] = $plugin_dir;

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate . '/index.html' ) && convert_to_wp_app_is_deployable_index( $candidate . '/index.html' ) ) {
				return array(
					'type' => 'static-html',
					'dir'  => $candidate,
				);
			}
		}

		if ( $allow_php_source ) {
			foreach ( $candidates as $candidate ) {
				$entry = convert_to_wp_app_find_php_onepager_entry( $candidate );
				if ( $entry !== null ) {
					return array(
						'type'  => 'php-onepager',
						'dir'   => $candidate,
						'entry' => $entry,
					);
				}
			}
		}

		throw new RuntimeException( 'Could not find deployable index.html or index.php in the checked-out app, build, dist, or configured source_build_dir.' );
	}

	function convert_to_wp_app_find_php_onepager_entry( string $directory ): ?string {
		if ( is_file( $directory . '/index.php' ) ) {
			return 'index.php';
		}

		$entries = scandir( $directory );
		if ( $entries === false ) {
			return null;
		}

		$php_files = array_values(
			array_filter(
				$entries,
				static function( string $entry ) use ( $directory ): bool {
					return substr( $entry, -4 ) === '.php' && is_file( $directory . '/' . $entry );
				}
			)
		);

		return count( $php_files ) === 1 ? $php_files[0] : null;
	}

	function convert_to_wp_app_is_deployable_index( string $index_html ): bool {
		$html = file_get_contents( $index_html );
		if ( $html === false ) {
			return false;
		}
		if ( strpos( $html, '%PUBLIC_URL%' ) !== false ) {
			return false;
		}
		return ! preg_match( '#\b(?:src|href)=([\'"])/?src/#i', $html );
	}

	function convert_to_wp_app_create_template( string $html, string $slug, string $source_public_path = '' ): string {
		$head = convert_to_wp_app_extract_tag_contents( $html, 'head' );
		$body = convert_to_wp_app_extract_tag_contents( $html, 'body' );
		$head = convert_to_wp_app_rewrite_asset_urls( $head, $source_public_path );
		$body = convert_to_wp_app_rewrite_asset_urls( $body, $source_public_path );

		$title_tag = '<title><?php wp_app_the_title(); ?></title>';
		if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $head, $title_match ) ) {
			$title = trim( html_entity_decode( strip_tags( $title_match[1] ), ENT_QUOTES ) );
			if ( $title !== '' ) {
				$title_tag = '<title><?php wp_app_the_title( ' . var_export( $title, true ) . ' ); ?></title>';
			}
		}
		$head = preg_replace( '/<title\b[^>]*>.*?<\/title>/is', $title_tag, $head, 1, $count );
		if ( $count === 0 ) {
			$head = $title_tag . "\n" . ltrim( $head );
		}

		$plugin_file = var_export( $slug . '.php', true );
		$head        = convert_to_wp_app_indent( trim( $head ), '    ' );
		$body        = convert_to_wp_app_indent( trim( $body ), '    ' );

		return <<<PHP
<?php
\$asset_url = static function( string \$path ): string {
    return plugins_url( 'app/' . ltrim( \$path, '/' ), dirname( __DIR__ ) . '/' . {$plugin_file} );
};
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
{$head}
    <?php wp_app_head(); ?>
</head>
<body>
    <?php wp_app_body_open(); ?>
{$body}
    <?php wp_app_body_close(); ?>
</body>
</html>
PHP;
	}

	function convert_to_wp_app_create_php_onepager_template( string $entry, string $slug, string $url_path ): string {
		$plugin_file = var_export( $slug . '.php', true );
		$entry_file  = var_export( $entry, true );
		$route       = var_export( trim( $url_path, '/' ), true );

		return <<<PHP
<?php
\$asset_url = static function( string \$path ): string {
    return plugins_url( 'app/' . ltrim( \$path, '/' ), dirname( __DIR__ ) . '/' . {$plugin_file} );
};
\$onepager_rewrite_assets = static function( string \$html ) use ( \$asset_url ): string {
    return preg_replace_callback(
        '/<([a-z][a-z0-9:-]*)\\b[^>]*>/i',
        static function( array \$matches ) use ( \$asset_url ): string {
            \$tag_name = strtolower( \$matches[1] );
            return preg_replace_callback(
                '/\\b(src|href)=([\\'"])([^\\'"]+)\\2/i',
                static function( array \$attr_matches ) use ( \$tag_name, \$asset_url ): string {
                    \$attribute = strtolower( \$attr_matches[1] );
                    \$asset_attributes = array(
                        'src'  => array( 'audio', 'embed', 'iframe', 'img', 'script', 'source', 'track', 'video' ),
                        'href' => array( 'link' ),
                    );
                    if ( ! in_array( \$tag_name, \$asset_attributes[ \$attribute ] ?? array(), true ) ) {
                        return \$attr_matches[0];
                    }
                    \$path = trim( html_entity_decode( \$attr_matches[3], ENT_QUOTES ) );
                    if ( \$path === '' || \$path[0] === '#' || \$path[0] === '?' || preg_match( '/^(?:[a-z][a-z0-9+.-]*:|\\/\\/)/i', \$path ) ) {
                        return \$attr_matches[0];
                    }
                    \$path = preg_replace( '/[?#].*\$/', '', \$path );
                    \$path = preg_replace( '#^\\./#', '', \$path );
                    \$path = ltrim( \$path, '/' );
                    if ( \$path === '' || strpos( \$path, '..' ) !== false ) {
                        return \$attr_matches[0];
                    }
                    return \$attr_matches[1] . '=' . \$attr_matches[2] . esc_url( \$asset_url( \$path ) ) . \$attr_matches[2];
                },
                \$matches[0]
            );
        },
        \$html
    );
};
\$onepager_rewrite_route_links = static function( string \$html ): string {
    \$host = \$_SERVER['HTTP_HOST'] ?? parse_url( home_url(), PHP_URL_HOST );
    \$route_url = home_url( '/' . trim( {$route}, '/' ) . '/' );
    return preg_replace_callback(
        '/\\b(href|action)=([\\'"])([^\\'"]*)\\2/i',
        static function( array \$matches ) use ( \$host, \$route_url ): string {
            \$url = html_entity_decode( \$matches[3], ENT_QUOTES );
            if ( \$url === '/' || strpos( \$url, '/?' ) === 0 ) {
                \$rewritten = rtrim( \$route_url, '/' ) . '/' . ltrim( \$url, '/' );
                return \$matches[1] . '=' . \$matches[2] . esc_url( \$rewritten ) . \$matches[2];
            }
            \$parts = parse_url( \$url );
            if ( isset( \$parts['host'] ) && strcasecmp( \$parts['host'], (string) \$host ) === 0 ) {
                \$path = \$parts['path'] ?? '/';
                if ( isset( \$parts['query'] ) ) {
                    \$path .= '?' . \$parts['query'];
                }
                if ( \$path === '/' || strpos( \$path, '/?' ) === 0 ) {
                    \$rewritten = rtrim( \$route_url, '/' ) . '/' . ltrim( \$path, '/' );
                    return \$matches[1] . '=' . \$matches[2] . esc_url( \$rewritten ) . \$matches[2];
                }
            }
            return \$matches[0];
        },
        \$html
    );
};
\$onepager_extract_tag = static function( string \$html, string \$tag ): string {
    if ( preg_match( '/<' . preg_quote( \$tag, '/' ) . '\\b[^>]*>(.*?)<\\/' . preg_quote( \$tag, '/' ) . '>/is', \$html, \$matches ) ) {
        return trim( \$matches[1] );
    }
    return '';
};
\$entry = __DIR__ . '/../app/' . {$entry_file};
\$previous_cwd = getcwd();
chdir( dirname( \$entry ) );
ob_start();
try {
    include \$entry;
} finally {
    if ( \$previous_cwd !== false ) {
        chdir( \$previous_cwd );
    }
}
\$html = ob_get_clean();
\$html = \$onepager_rewrite_route_links( \$onepager_rewrite_assets( \$html ) );
\$head = \$onepager_extract_tag( \$html, 'head' );
\$body = \$onepager_extract_tag( \$html, 'body' );
\$head = preg_replace( '/<title\\b[^>]*>.*?<\\/title>/is', '<title>' . esc_html( wp_app_get_title() ) . '</title>', \$head, 1, \$count );
if ( \$count === 0 ) {
    \$head = '<title>' . esc_html( wp_app_get_title() ) . '</title>' . "\\n" . ltrim( \$head );
}
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <?php echo \$head; ?>
    <?php wp_app_head(); ?>
</head>
<body>
    <?php wp_app_body_open(); ?>
    <?php echo \$body; ?>
    <?php wp_app_body_close(); ?>
</body>
</html>
PHP;
	}

	function convert_to_wp_app_extract_tag_contents( string $html, string $tag ): string {
		if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/is', $html, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	function convert_to_wp_app_rewrite_asset_urls( string $html, string $source_public_path = '' ): string {
		return preg_replace_callback(
			'/<([a-z][a-z0-9:-]*)\b[^>]*>/i',
			static function( array $matches ) use ( $source_public_path ): string {
				$tag_name = strtolower( $matches[1] );
				return preg_replace_callback(
					'/\b(src|href)=([\'"])([^\'"]+)\2/i',
					static function( array $attr_matches ) use ( $tag_name, $source_public_path ): string {
						$attribute = strtolower( $attr_matches[1] );
						if ( ! convert_to_wp_app_is_asset_attribute( $tag_name, $attribute ) ) {
							return $attr_matches[0];
						}
						$path = convert_to_wp_app_local_asset_path( html_entity_decode( $attr_matches[3], ENT_QUOTES ), $source_public_path );
						if ( $path === null ) {
							return $attr_matches[0];
						}
						return $attr_matches[1] . '=' . $attr_matches[2] . '<?php echo esc_url( $asset_url( ' . var_export( $path, true ) . ' ) ); ?>' . $attr_matches[2];
					},
					$matches[0]
				);
			},
			$html
		);
	}

	function convert_to_wp_app_local_asset_path( string $url, string $source_public_path = '' ): ?string {
		$url = trim( $url );
		if ( $url === '' || $url[0] === '#' || $url[0] === '?' ) {
			return null;
		}
		if ( preg_match( '/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url ) ) {
			return null;
		}
		if ( strpos( $url, '%PUBLIC_URL%/' ) === 0 ) {
			$url = substr( $url, strlen( '%PUBLIC_URL%/' ) );
		}
		$url = preg_replace( '/[?#].*$/', '', $url );
		$url = preg_replace( '#^\./#', '', $url );
		$url = ltrim( $url, '/' );
		$source_public_path = trim( $source_public_path, '/' );
		if ( $source_public_path !== '' && strpos( $url . '/', $source_public_path . '/' ) === 0 ) {
			$url = substr( $url, strlen( $source_public_path ) );
			$url = ltrim( $url, '/' );
		}
		return $url !== '' && strpos( $url, '..' ) === false ? $url : null;
	}

	function convert_to_wp_app_rewrite_copied_asset_files( string $asset_dir, string $slug, string $plugin_dir, string $source_public_path = '' ): void {
		$source_public_path = trim( $source_public_path, '/' );
		if ( $source_public_path === '' ) {
			return;
		}

		$plugin_file = $plugin_dir . '/' . $slug . '.php';
		$asset_base_url = function_exists( 'plugins_url' )
			? plugins_url( 'app/', $plugin_file )
			: '/wp-content/plugins/' . rawurlencode( $slug ) . '/app/';
		$asset_base_url = rtrim( $asset_base_url, '/' ) . '/';

		convert_to_wp_app_rewrite_text_asset_files(
			$asset_dir,
			static function( string $content ) use ( $source_public_path, $asset_base_url ): string {
				return convert_to_wp_app_rewrite_public_path_in_content( $content, $source_public_path, $asset_base_url );
			}
		);
	}

	function convert_to_wp_app_rewrite_text_asset_files( string $directory, callable $rewrite ): void {
		$entries = scandir( $directory );
		if ( $entries === false ) {
			throw new RuntimeException( "Could not read directory: {$directory}" );
		}
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				convert_to_wp_app_rewrite_text_asset_files( $path, $rewrite );
				continue;
			}
			if ( ! convert_to_wp_app_should_rewrite_text_asset_file( $path ) ) {
				continue;
			}
			$content = file_get_contents( $path );
			if ( $content === false ) {
				throw new RuntimeException( "Could not read file: {$path}" );
			}
			$rewritten = $rewrite( $content );
			if ( $rewritten !== $content ) {
				file_put_contents( $path, $rewritten );
			}
		}
	}

	function convert_to_wp_app_should_rewrite_text_asset_file( string $path ): bool {
		$extension = strtolower( pathinfo( preg_replace( '/\.map$/i', '', $path ), PATHINFO_EXTENSION ) );
		return in_array( $extension, array( 'css', 'html', 'js', 'json', 'mjs', 'svg', 'txt', 'webmanifest', 'xml' ), true );
	}

	function convert_to_wp_app_rewrite_public_path_in_content( string $content, string $source_public_path, string $asset_base_url ): string {
		$source_public_path = trim( $source_public_path, '/' );
		if ( $source_public_path === '' ) {
			return $content;
		}

		$quoted_path = preg_quote( $source_public_path, '/' );
		$quoted_base = rtrim( $asset_base_url, '/' ) . '/';
		$rewritten = preg_replace_callback(
			'/(?<![A-Za-z0-9._~%+\/-])\/' . $quoted_path . '\/([^\'"`\s<>)]+)/',
			static function( array $matches ) use ( $quoted_base ): string {
				$path = rawurldecode( $matches[1] );
				if ( $path === '' || strpos( $path, '..' ) !== false ) {
					return $matches[0];
				}
				return $quoted_base . ltrim( $matches[1], '/' );
			},
			$content
		);
		return is_string( $rewritten ) ? $rewritten : $content;
	}

	function convert_to_wp_app_is_asset_attribute( string $tag_name, string $attribute ): bool {
		$asset_attributes = array(
			'src'  => array( 'audio', 'embed', 'iframe', 'img', 'script', 'source', 'track', 'video' ),
			'href' => array( 'link' ),
		);
		return in_array( $tag_name, $asset_attributes[ $attribute ] ?? array(), true );
	}

	function convert_to_wp_app_plugin_php( string $slug, string $plugin_name, string $url_path, string $plugin_author = '' ): string {
		$plugin_name_header = str_replace( array( "\r", "\n" ), ' ', $plugin_name );
		$author_header      = str_replace( array( "\r", "\n" ), ' ', $plugin_author );
		$author_header      = $author_header !== '' ? " * Author: {$author_header}\n" : '';
		$text_domain        = $slug;
		$app_name           = var_export( $plugin_name, true );
		$route              = var_export( $url_path, true );
		return <<<PHP
<?php
/**
 * Plugin Name: {$plugin_name_header}
 * Description: A checked-out static one-pager converted into a WordPress app powered by WpApp.
 * Version: 1.0.0
{$author_header} * Text Domain: {$text_domain}
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

add_action( 'plugins_loaded', function() {
    \$app = new \\WpApp\\WpApp( __DIR__ . '/templates', {$route}, array(
        'app_name' => {$app_name},
        'my_apps'  => true,
    ) );
    \$app->init();
} );

register_activation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
PHP;
	}

	function convert_to_wp_app_copy_wp_app_runtime( string $source, string $plugin_dir ): void {
		$destination = $plugin_dir . '/vendor/akirk/wp-app';
		if ( is_dir( $destination ) ) {
			convert_to_wp_app_remove_directory( $destination );
		}
		convert_to_wp_app_mkdir( $destination );
		$source_src = is_dir( $source . '/src' ) ? $source . '/src' : $source;
		convert_to_wp_app_copy_directory( $source_src, $destination . '/src' );
	}

	function convert_to_wp_app_cleanup_dirs( $directories, string $plugins_dir, string $plugin_dir ): void {
		if ( ! is_array( $directories ) ) {
			return;
		}
		$plugin_dir = convert_to_wp_app_normalize_dir( $plugin_dir );
		foreach ( $directories as $directory ) {
			$directory = convert_to_wp_app_normalize_plugin_dir( (string) $directory, $plugins_dir );
			if ( $directory === $plugin_dir || strpos( $plugin_dir . '/', $directory . '/' ) === 0 ) {
				continue;
			}
			convert_to_wp_app_remove_directory( $directory );
		}
	}

	function convert_to_wp_app_autoload_php(): string {
		return <<<'PHP'
<?php
$wp_app_dir = __DIR__ . '/akirk/wp-app';
$files = array(
    'src/class-registry.php',
    'src/class-settings.php',
    'src/class-router.php',
    'src/class-masterbar.php',
    'src/class-wpapp.php',
    'src/class-client-encrypted-fields.php',
    'src/BaseStorage.php',
    'src/abstract-baseapp.php',
    'src/functions.php',
);
foreach ( $files as $file ) {
    $path = $wp_app_dir . '/' . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}
return true;
PHP;
	}

	function convert_to_wp_app_copy_directory( string $source, string $destination, array $skip = array() ): void {
		convert_to_wp_app_mkdir( $destination );
		$entries = scandir( $source );
		if ( $entries === false ) {
			throw new RuntimeException( "Could not read directory: {$source}" );
		}
		$destination = convert_to_wp_app_normalize_dir( $destination );
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || in_array( $entry, $skip, true ) ) {
				continue;
			}
			$source_path      = $source . '/' . $entry;
			$destination_path = $destination . '/' . $entry;
			if ( strpos( convert_to_wp_app_normalize_dir( $source_path ) . '/', $destination . '/' ) === 0 ) {
				continue;
			}
			if ( is_dir( $source_path ) && ! is_link( $source_path ) ) {
				convert_to_wp_app_copy_directory( $source_path, $destination_path, $skip );
			} else {
				copy( $source_path, $destination_path );
			}
		}
	}

	function convert_to_wp_app_remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$entries = scandir( $directory );
		if ( $entries === false ) {
			throw new RuntimeException( "Could not read directory: {$directory}" );
		}
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				convert_to_wp_app_remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $directory );
	}

	function convert_to_wp_app_mkdir( string $directory ): void {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
			throw new RuntimeException( "Could not create directory: {$directory}" );
		}
	}

	function convert_to_wp_app_indent( string $content, string $indent ): string {
		return $content === '' ? '' : $indent . str_replace( "\n", "\n" . $indent, $content );
	}
}

if ( isset( $GLOBALS['convert_to_wp_app_playground_config'] ) && is_array( $GLOBALS['convert_to_wp_app_playground_config'] ) ) {
	$GLOBALS['convert_to_wp_app_playground_result'] = convert_to_wp_app_playground( $GLOBALS['convert_to_wp_app_playground_config'] );
}
