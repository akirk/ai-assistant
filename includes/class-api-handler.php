<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX API Handler for tool execution
 *
 * Note: All LLM communication happens client-side via JavaScript.
 * This handler executes WordPress-backed tools. Filesystem tools normally use
 * the direct file endpoint, but remain supported here for backward
 * compatibility and internal callers.
 */
class API_Handler {

    private $tools;
    private $executor;

    public function __construct($tools, $executor) {
        $this->tools = $tools;
        $this->executor = $executor;

        add_action('wp_ajax_ai_assistant_execute_tool', [$this, 'handle_execute_tool']);
        add_action('wp_ajax_ai_assistant_get_ability_details', [$this, 'handle_get_ability_details']);
        add_action('wp_ajax_ai_assistant_wpok', [$this, 'handle_wpok']);
    }

    /**
     * Lightweight AJAX endpoint used to confirm WordPress is OK after recovery.
     */
    public function handle_wpok() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (
            !current_user_can('ai_assistant_full') &&
            !current_user_can('ai_assistant_read_only') &&
            !current_user_can('ai_assistant_chat_only')
        ) {
            wp_send_json_error(['message' => 'AI Assistant access not allowed'], 403);
        }

        wp_send_json_success(['ok' => true]);
    }

    /**
     * Handle tool execution AJAX request
     */
    public function handle_execute_tool() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('ai_assistant_full') && !current_user_can('ai_assistant_read_only')) {
            wp_send_json_error(['message' => 'Tool execution not allowed']);
        }

        $permission = current_user_can('ai_assistant_full') ? 'full' : 'read_only';

        $tool_name = sanitize_text_field($_POST['tool'] ?? '');
        $arguments_json = stripslashes($_POST['arguments'] ?? '{}');
        $arguments = json_decode($arguments_json, true);
        $conversation_id = isset($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : null;
        if ($conversation_id === 0) {
            $conversation_id = null;
        }

        if (empty($tool_name)) {
            wp_send_json_error(['message' => 'Tool name is required']);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid arguments JSON: ' . json_last_error_msg()]);
        }

        if (!is_array($arguments)) {
            wp_send_json_error(['message' => 'Tool arguments must be a JSON object']);
        }

        try {
            $result = $this->executor->execute_tool($tool_name, $arguments, $permission, $conversation_id);
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if (empty($error_message)) {
                $error_message = 'Unknown error (throwable class: ' . get_class($e) . ')';
            }
            wp_send_json_error(['message' => $error_message]);
        }

        wp_send_json_success($result);
    }

    /**
     * Return read-only ability metadata for client-side approval preflight.
     */
    public function handle_get_ability_details() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('ai_assistant_full') && !current_user_can('ai_assistant_read_only')) {
            wp_send_json_error(['message' => 'Ability details not allowed']);
        }

        if (
            !current_user_can('ai_assistant_tool_get_ability') &&
            !current_user_can('ai_assistant_tool_execute_ability')
        ) {
            wp_send_json_error([
                'message' => 'Ability tools are not enabled. Enable them in AI Assistant → Settings → Tool Permissions.',
            ]);
        }

        $ability_id = sanitize_text_field(wp_unslash($_POST['ability'] ?? ''));
        if ($ability_id === '') {
            wp_send_json_error(['message' => 'Ability ID is required']);
        }

        if (!function_exists('wp_get_ability')) {
            wp_send_json_error([
                'message' => 'Abilities API not available. WordPress 6.9+ with the Abilities API is required.',
            ]);
        }

        $auto_approved = array_map('strval', (array) get_option('ai_assistant_auto_approved_abilities', []));
        $details = Ability_Annotations::get_details_for_id($ability_id, in_array($ability_id, $auto_approved, true));

        if ($details === null) {
            wp_send_json_error([
                'code'    => 'ability_not_found',
                'message' => 'Ability not found: ' . $ability_id,
            ], 404);
        }

        wp_send_json_success($details);
    }
}
