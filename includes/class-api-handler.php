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
}
