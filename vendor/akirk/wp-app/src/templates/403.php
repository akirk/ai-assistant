<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <title><?php wp_app_the_title( '403 Forbidden' ); ?></title>
    <?php
    $app_path = wp_app_get_current_app_path();
    wp_app_enqueue_style( 'wp-app-error-pages', wp_app_get_asset_url( 'wp-app-error-pages.css' ), [], WP_APP_VERSION, $app_path ? $app_path : 'global' );
    wp_app_head();
    ?>

</head>
<body class="wp-app-body">
<?php wp_app_body_open(); ?>

<div class="wp-app-error">
    <div class="wp-app-error__code">403</div>
    <h1 class="wp-app-error__title">Access Denied</h1>

    <?php if ( is_user_logged_in() ) : ?>
        <p class="wp-app-error__message">You don't have permission to access this page. You may need additional privileges to view this content.</p>
        <div class="wp-app-error__actions">
            <a href="<?php echo esc_url( home_url() ); ?>" class="wp-app-error__button wp-app-error__button--secondary">Return to Home</a>
        </div>
    <?php else : ?>
        <p class="wp-app-error__message">You need to be logged in to access this page.</p>
        <div class="wp-app-error__actions">
            <a href="<?php echo esc_url( wp_login_url( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>" class="wp-app-error__button wp-app-error__button--primary">Login</a>
        </div>
    <?php endif; ?>

    <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        <?php
        global $wp_app_route;
        $params = $wp_app_route['params'] ?? [];
        ?>
        <div class="wp-app-error__details wp-app-error__details--debug">
            <h3>Debug Information</h3>
            <strong>Request Path:</strong> <?php echo esc_html( $params['request_path'] ?? 'unknown' ); ?><br>
            <strong>Required Capability:</strong> <?php echo esc_html( $params['required_capability'] ?? 'none' ); ?><br>
            <strong>User Login Status:</strong> <?php echo is_user_logged_in() ? 'Logged in' : 'Not logged in'; ?><br>
            <?php if ( is_user_logged_in() ) : ?>
                <strong>User ID:</strong> <?php echo get_current_user_id(); ?><br>
                <strong>User Capabilities:</strong> 
                <?php
                $user = wp_get_current_user();
                if ( ! empty( $user->allcaps ) ) {
                    echo esc_html( implode( ', ', array_keys( array_filter( $user->allcaps ) ) ) );
                } else {
                    echo 'None';
                }
                ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
