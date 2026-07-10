<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <title><?php echo wp_app_title( '403 Forbidden' ); ?></title>
    <?php wp_app_head(); ?>
    <style>
        .wp-app-403 {
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .wp-app-403 h1 {
            color: var(--wp-app-color-error);
            font-size: 2.5em;
            margin-bottom: 20px;
        }
        .wp-app-403 p {
            color: var(--wp-app-color-muted);
            font-size: 1.1em;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .wp-app-403 .login-link {
            display: inline-block;
            padding: 12px 24px;
            background: var(--wp-app-color-primary);
            color: var(--wp-app-color-on-primary);
            text-decoration: none;
            border-radius: 5px;
            font-weight: 500;
        }
        .wp-app-403 .login-link:hover {
            background: var(--wp-app-color-primary-hover);
            color: var(--wp-app-color-on-primary);
        }
        .wp-app-403 .debug-info {
            margin-top: 40px;
            padding: 20px;
            background: var(--wp-app-color-surface-alt);
            border-radius: 5px;
            text-align: left;
            font-size: 0.9em;
            color: var(--wp-app-color-text);
        }
        .wp-app-403 .debug-info h3 {
            margin-top: 0;
            color: var(--wp-app-color-text);
        }
    </style>
</head>
<body class="wp-app-body">
<?php wp_app_body_open(); ?>

<div class="wp-app-403">
    <h1>403 - Access Denied</h1>

    <?php if ( is_user_logged_in() ) : ?>
        <p>You don't have permission to access this page. You may need additional privileges to view this content.</p>
        <p><a href="<?php echo esc_url( home_url() ); ?>">← Return to Home</a></p>
    <?php else : ?>
        <p>You need to be logged in to access this page.</p>
        <a href="<?php echo esc_url( wp_login_url( $_SERVER['REQUEST_URI'] ?? '' ) ); ?>" class="login-link">Login</a>
    <?php endif; ?>

    <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
        <?php
        global $wp_app_route;
        $params = $wp_app_route['params'] ?? [];
        ?>
        <div class="debug-info">
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
