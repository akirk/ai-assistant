<?php
/**
 * Default 404 Not Found Template for WpApp
 * This is the fallback template used when no custom 404.php is found
 */

global $app, $wp_app_route;
$request_path  = isset( $wp_app_route['params']['request_path'] ) ? $wp_app_route['params']['request_path'] : '';
$error_type    = isset( $wp_app_route['params']['error_type'] ) ? $wp_app_route['params']['error_type'] : 'route_not_found';
$matched_route = isset( $wp_app_route['params']['matched_route'] ) ? $wp_app_route['params']['matched_route'] : null;
$template_path = isset( $wp_app_route['params']['template_path'] ) ? $wp_app_route['params']['template_path'] : null;
$app_path      = isset( $wp_app_route['params']['app_path'] ) ? $wp_app_route['params']['app_path'] : '';
?>
<!DOCTYPE html>
<html <?php echo wp_app_language_attributes(); ?>>
<head>
	<title><?php echo wp_app_title( 'Page Not Found' ); ?></title>
	<?php wp_app_head(); ?>

	<style>
		/* Default 404 styles - included inline to avoid external dependencies */
		.wp-app-404-container {
			max-width: 600px;
			margin: 100px auto;
			padding: 40px;
			background: var(--wp-app-color-surface);
			border-radius: 10px;
			border: 1px solid var(--wp-app-color-border);
			box-shadow: 0 2px 10px var(--wp-app-color-border);
			text-align: center;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}

		.wp-app-404-code {
			font-size: 72px;
			font-weight: bold;
			color: var(--wp-app-color-error);
			margin-bottom: 20px;
		}

		.wp-app-404-title {
			font-size: 32px;
			color: var(--wp-app-color-text);
			margin-bottom: 20px;
		}

		.wp-app-404-message {
			font-size: 18px;
			color: var(--wp-app-color-muted);
			margin-bottom: 30px;
		}

		.wp-app-404-path {
			background: var(--wp-app-color-surface-alt);
			padding: 10px;
			border-radius: 5px;
			font-family: monospace;
			color: var(--wp-app-color-text);
			margin-bottom: 30px;
			word-break: break-all;
		}

		.wp-app-404-buttons {
			display: flex;
			gap: 15px;
			justify-content: center;
			flex-wrap: wrap;
		}

		.wp-app-404-button {
			display: inline-block;
			padding: 12px 24px;
			text-decoration: none;
			border-radius: 5px;
			font-weight: 500;
			transition: all 0.3s ease;
		}

		.wp-app-404-button-primary {
			background: var(--wp-app-color-primary);
			color: var(--wp-app-color-on-primary);
		}

		.wp-app-404-button-primary:hover {
			background: var(--wp-app-color-primary-hover);
			color: var(--wp-app-color-on-primary);
		}

		.wp-app-404-button-secondary {
			background: var(--wp-app-color-secondary);
			color: var(--wp-app-color-secondary-text);
		}

		.wp-app-404-button-secondary:hover {
			background: var(--wp-app-color-secondary-hover);
			color: var(--wp-app-color-secondary-text);
		}

		.wp-app-404-footer {
			margin-top: 40px;
			padding-top: 20px;
			border-top: 1px solid var(--wp-app-color-border);
			font-size: 14px;
			color: var(--wp-app-color-muted);
		}

		@media (max-width: 600px) {
			.wp-app-404-container {
				margin: 50px 20px;
				padding: 30px 20px;
			}

			.wp-app-404-code {
				font-size: 48px;
			}

			.wp-app-404-title {
				font-size: 24px;
			}

			.wp-app-404-buttons {
				flex-direction: column;
				align-items: center;
			}
		}
	</style>
</head>
<body class="wp-app-body">

<?php wp_app_body_open(); ?>

<div class="wp-app-404-container">
	<div class="wp-app-404-code">404</div>

	<?php if ( $error_type === 'template_missing' ) : ?>
		<h1 class="wp-app-404-title">Template Missing</h1>
		<p class="wp-app-404-message">The route exists but the template file is missing.</p>

		<?php if ( $request_path ) : ?>
			<div class="wp-app-404-path">
				Requested path: <strong>/<?php echo esc_html( $request_path ); ?></strong>
			</div>
		<?php endif; ?>

		<?php if ( $template_path ) : ?>
			<div class="wp-app-404-path" style="margin-top: 10px;">
				Missing template: <strong><?php echo esc_html( $template_path ); ?></strong>
			</div>
		<?php endif; ?>

		<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $matched_route ) : ?>
			<div class="wp-app-404-path" style="margin-top: 10px; text-align: left; font-size: 14px;">
				<strong>Route info:</strong><br>
				Pattern: <?php echo esc_html( $matched_route['pattern'] ); ?><br>
				Template: <?php echo esc_html( $matched_route['template'] ); ?>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<h1 class="wp-app-404-title">Page Not Found</h1>
		<p class="wp-app-404-message">Sorry, the page you're looking for doesn't exist in our app.</p>

		<?php if ( $request_path ) : ?>
			<div class="wp-app-404-path">
				Requested path: <strong>/<?php echo esc_html( $request_path ); ?></strong>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
		<div class="wp-app-404-path" style="margin-top: 20px; text-align: left; font-size: 12px; background: var(--wp-app-color-surface-alt); padding: 15px;">
			<strong>Debug Information:</strong><br>
			Error Type: <code><?php echo esc_html( var_export( $error_type, true ) ); ?></code><br>
			Request Path: <code><?php echo esc_html( var_export( $request_path, true ) ); ?></code><br>
			Template Path: <code><?php echo esc_html( var_export( $template_path, true ) ); ?></code><br>
			Matched Route: <code><?php echo esc_html( var_export( $matched_route, true ) ); ?></code><br>
			wp_app_route: <code><?php echo esc_html( var_export( $wp_app_route, true ) ); ?></code>
		</div>
	<?php endif; ?>

	<div class="wp-app-404-buttons">
		<?php
		// Route data provides the mounted app path even though the app instance is not global.
		if ( isset( $app ) && method_exists( $app, 'router' ) ) {
			$router = $app->router();
			if ( method_exists( $router, 'get_app_path' ) ) {
				$app_path = $router->get_app_path();
			}
		}

		if ( ! $app_path && function_exists( 'get_query_var' ) ) {
			$app_path = get_query_var( 'wp_app_path' );
		}

		$app_path     = trim( (string) $app_path, '/' );
		$app_home_url = $app_path ? home_url( '/' . $app_path . '/' ) : home_url( '/' );
		?>

		<a href="<?php echo esc_url( $app_home_url ); ?>" class="wp-app-404-button wp-app-404-button-primary">
			Go to App Home
		</a>

		<a href="<?php echo esc_url( home_url() ); ?>" class="wp-app-404-button wp-app-404-button-secondary">
			Back to Website
		</a>

		<?php if ( is_user_logged_in() ) : ?>
			<a href="<?php echo esc_url( admin_url() ); ?>" class="wp-app-404-button wp-app-404-button-secondary">
				WordPress Dashboard
			</a>
		<?php endif; ?>
	</div>

	<div class="wp-app-404-footer">
		<p>Powered by <strong>WpApp Framework</strong></p>
	</div>
</div>

<?php wp_app_body_close(); ?>
</body>
</html>
