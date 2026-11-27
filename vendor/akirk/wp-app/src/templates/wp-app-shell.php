<?php
/** Framework-owned, cacheable document. App content must contain no live credentials. */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $config['manifest']['name'] ); ?></title>
<?php
if ( $offline ) {
	if ( function_exists( 'wp_app_output_admin_color_scheme' ) ) {
		wp_app_output_admin_color_scheme(); }
	wp_app_do_scoped_action( 'wp_app_head_meta' );
	wp_app_do_scoped_action( 'wp_app_head_styles' );
	wp_app_do_scoped_action( 'wp_app_head_scripts' );
} else {
	wp_app_head(); }
?>
</head>
<body class="<?php echo esc_attr( $shell['body_class'] ?? 'wp-app-body' ); ?>">

<?php
if ( ! $offline ) {
	wp_app_body_open(); }
?>
<div data-wp-app-bootstrap="<?php echo esc_attr( wp_json_encode( $bootstrap ) ); ?>">
<?php require $shell['content']; ?>
</div>
<?php
if ( $offline ) {
	wp_app_do_scoped_action( 'wp_app_body_close' );
} else {
	wp_app_body_close(); }
?>
</body>
</html>
