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
<html <?php wp_app_language_attributes(); ?>>
<head>
	<title><?php wp_app_the_title( 'Page Not Found' ); ?></title>
	<?php
	wp_app_enqueue_style( 'wp-app-error-pages', wp_app_get_asset_url( 'wp-app-error-pages.css' ), [], WP_APP_VERSION, $app_path ? $app_path : 'global' );
	wp_app_head();
	?>

</head>
<body class="wp-app-body">

<?php wp_app_body_open(); ?>

<div class="wp-app-error">
	<div class="wp-app-error__code">404</div>

	<?php if ( $error_type === 'template_missing' ) : ?>
		<h1 class="wp-app-error__title">Template Missing</h1>
		<p class="wp-app-error__message">The route exists but the template file is missing.</p>

		<?php if ( $request_path ) : ?>
			<div class="wp-app-error__details">
				Requested path: <strong>/<?php echo esc_html( $request_path ); ?></strong>
			</div>
		<?php endif; ?>

		<?php if ( $template_path ) : ?>
			<div class="wp-app-error__details">
				Missing template: <strong><?php echo esc_html( $template_path ); ?></strong>
			</div>
		<?php endif; ?>

		<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $matched_route ) : ?>
			<div class="wp-app-error__details wp-app-error__details--route">
				<strong>Route info:</strong><br>
				Pattern: <?php echo esc_html( $matched_route['pattern'] ); ?><br>
				Template: <?php echo esc_html( $matched_route['template'] ); ?>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<h1 class="wp-app-error__title">Page Not Found</h1>
		<p class="wp-app-error__message">Sorry, the page you're looking for doesn't exist in our app.</p>

		<?php if ( $request_path ) : ?>
			<div class="wp-app-error__details">
				Requested path: <strong>/<?php echo esc_html( $request_path ); ?></strong>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
		<div class="wp-app-error__details wp-app-error__details--debug">
			<strong>Debug Information:</strong><br>
			Error Type: <code><?php echo esc_html( var_export( $error_type, true ) ); ?></code><br>
			Request Path: <code><?php echo esc_html( var_export( $request_path, true ) ); ?></code><br>
			Template Path: <code><?php echo esc_html( var_export( $template_path, true ) ); ?></code><br>
			Matched Route: <code><?php echo esc_html( var_export( $matched_route, true ) ); ?></code><br>
			wp_app_route: <code><?php echo esc_html( var_export( $wp_app_route, true ) ); ?></code>
		</div>
	<?php endif; ?>

	<div class="wp-app-error__actions">
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

		<a href="<?php echo esc_url( $app_home_url ); ?>" class="wp-app-error__button wp-app-error__button--primary">
			Go to App Home
		</a>

		<a href="<?php echo esc_url( home_url() ); ?>" class="wp-app-error__button wp-app-error__button--secondary">
			Back to Website
		</a>
	</div>
</div>

<?php wp_app_body_close(); ?>
</body>
</html>
