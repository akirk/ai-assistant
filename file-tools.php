<?php
define('AI_ASSISTANT_FILE_TOOLS_ENDPOINT', true);
define('AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR', dirname(__DIR__, 2));

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', AI_ASSISTANT_FILE_TOOLS_WP_CONTENT_DIR);
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!function_exists('get_theme_root')) {
    function get_theme_root() {
        return WP_CONTENT_DIR . '/themes';
    }
}

require __DIR__ . '/includes/class-file-tool-auth.php';
require __DIR__ . '/includes/class-git-tracker.php';
require __DIR__ . '/includes/class-git-tracker-manager.php';
require __DIR__ . '/includes/class-file-tool-executor.php';

use AI_Assistant\File_Tool_Auth;
use AI_Assistant\File_Tool_Executor;
use AI_Assistant\Git_Tracker_Manager;

function ai_assistant_file_tools_send_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ai_assistant_file_tools_send_json([
        'success' => false,
        'data'    => ['message' => 'File tool endpoint requires POST'],
    ], 405);
}

$raw_body = file_get_contents('php://input');
$request = json_decode($raw_body ?: '', true);
if (!is_array($request)) {
    ai_assistant_file_tools_send_json([
        'success' => false,
        'data'    => ['message' => 'Invalid JSON request body'],
    ], 400);
}

try {
    $token = (string) ($request['token'] ?? '');
    $tool_name = (string) ($request['tool'] ?? '');
    $arguments = $request['arguments'] ?? [];
    $conversation_id = isset($request['conversation_id']) ? (int) $request['conversation_id'] : null;
    if ($conversation_id === 0) {
        $conversation_id = null;
    }

    if ($tool_name === '') {
        throw new Exception('Tool name is required');
    }

    if (!is_array($arguments)) {
        throw new Exception('Tool arguments must be an object');
    }

    $payload = File_Tool_Auth::validate_token($token);
    if (!File_Tool_Auth::can_execute_tool($tool_name, $arguments, $payload)) {
        throw new Exception("File tool token is not allowed to execute tool: $tool_name");
    }

    $file_tools = new File_Tool_Executor(WP_CONTENT_DIR, new Git_Tracker_Manager());
    $result = $file_tools->execute($tool_name, $arguments, $conversation_id);

    ai_assistant_file_tools_send_json([
        'success' => true,
        'data'    => $result,
    ]);
} catch (Throwable $e) {
    ai_assistant_file_tools_send_json([
        'success' => false,
        'data'    => ['message' => $e->getMessage()],
    ], 400);
}
