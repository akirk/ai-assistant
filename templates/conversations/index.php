<?php
if (!defined('ABSPATH')) {
    exit;
}

$conversation_id = \AI_Assistant\Conversations_App::get_request_conversation_id();
?><!doctype html>
<html <?php echo function_exists('wp_app_language_attributes') ? wp_app_language_attributes() : ''; ?>>
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo function_exists('wp_app_title') ? wp_app_title(__('AI Assistant', 'ai-assistant')) : esc_html__('AI Assistant', 'ai-assistant'); ?></title>
    <?php if (function_exists('wp_app_head')) { wp_app_head(); } ?>
</head>
<body <?php body_class('wp-app-body ai-assistant-app-body'); ?>>
    <?php if (function_exists('wp_app_body_open')) { wp_app_body_open(); } ?>

    <?php
    \AI_Assistant\Conversations_App::render_chat_shell([
        'conversation_id' => $conversation_id,
        'container_class' => 'ai-assistant-page ai-assistant-app-page',
    ]);
    ?>

    <?php if (function_exists('wp_app_body_close')) { wp_app_body_close(); } ?>
</body>
</html>
