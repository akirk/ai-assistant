<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_app_title(); ?></title>
    <?php wp_app_head(); ?>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.6; background: var(--wp-app-color-background); color: var(--wp-app-color-text); }
        main { max-width: 680px; margin: 2rem auto; padding: 0 1rem; }
        h1 { margin-bottom: 0.5rem; }
        .subtitle { color: var(--wp-app-color-muted); margin-top: 0; }
        .card { background: var(--wp-app-color-surface); border-radius: 4px; padding: 1.5rem; margin: 1.5rem 0; }
        .card h2 { margin-top: 0; font-size: 1.1rem; }
        code { background: var(--wp-app-color-surface-alt); padding: 0.2em 0.4em; border-radius: 3px; font-size: 0.9em; }
        ul { padding-left: 1.25rem; }
        li { margin: 0.5rem 0; }
        a { color: var(--wp-app-color-link); }
    </style>
</head>
<body>
    <?php wp_app_body_open(); ?>

    <main>
        <h1><?php echo esc_html( '{{plugin-name}}' ); ?></h1>
        <p class="subtitle">Your WpApp application is running.</p>

        <div class="card">
            <h2>Getting Started</h2>
            <ul>
                <li>Edit <code>templates/index.php</code> to customize this page</li>
                <li>Add routes in your main plugin file to create new pages</li>
                <li>Configure options like <code>require_login</code> or <code>show_masterbar_for_anonymous</code></li>
            </ul>
        </div>

        <div class="card">
            <h2>Documentation</h2>
            <p>
                Learn about routing, the masterbar, access control, and more:<br>
                <a href="https://github.com/akirk/wp-app/blob/main/README.md" target="_blank">github.com/akirk/wp-app</a>
            </p>
        </div>
    </main>

    <?php wp_app_body_close(); ?>
</body>
</html>
