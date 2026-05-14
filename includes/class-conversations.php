<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Conversations {

    const POST_TYPE = 'ai_conversation';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('wp_ajax_ai_assistant_save_conversation', [$this, 'ajax_save_conversation']);
        add_action('wp_ajax_ai_assistant_load_conversation', [$this, 'ajax_load_conversation']);
        add_action('wp_ajax_ai_assistant_list_conversations', [$this, 'ajax_list_conversations']);
        add_action('wp_ajax_ai_assistant_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_ai_assistant_rename_conversation', [$this, 'ajax_rename_conversation']);
        add_action('wp_ajax_ai_assistant_save_summary', [$this, 'ajax_save_summary']);
        add_action('wp_ajax_ai_assistant_get_conversation_for_summary', [$this, 'ajax_get_conversation_for_summary']);
        add_action('admin_post_ai_assistant_export_conversation', [$this, 'admin_post_export_conversation']);
        add_filter('ai_assistant_conversation_export_formats', [$this, 'register_default_export_formats'], 10, 2);
        add_filter('ai_assistant_conversation_export_shrink_tool_calls', [$this, 'shrink_file_tool_calls_for_export'], 10, 3);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_columns'], 10, 2);
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('AI Conversations', 'ai-assistant'),
                'singular_name' => __('Conversation', 'ai-assistant'),
                'menu_name' => __('Conversations', 'ai-assistant'),
                'all_items' => __('All Conversations', 'ai-assistant'),
                'view_item' => __('View Conversation', 'ai-assistant'),
                'edit_item' => __('Continue Conversation', 'ai-assistant'),
                'search_items' => __('Search Conversations', 'ai-assistant'),
                'not_found' => __('No conversations found', 'ai-assistant'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => ['title'],
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }

    public function add_meta_boxes() {
        add_meta_box(
            'ai_conversation_messages',
            __('Conversation Messages', 'ai-assistant'),
            [$this, 'render_messages_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'ai_conversation_continue',
            __('Continue Conversation', 'ai-assistant'),
            [$this, 'render_continue_meta_box'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    private function get_messages($post) {
        if (empty($post->post_content)) {
            return [];
        }
        $json = base64_decode($post->post_content);
        return json_decode($json, true) ?: [];
    }

    public function register_default_export_formats($formats, $conversation = null) {
        $formats = (array) $formats;

        if (!isset($formats['markdown'])) {
            $formats['markdown'] = [
                'label' => __('Markdown', 'ai-assistant'),
                'description' => __('Readable plain-text transcript.', 'ai-assistant'),
                'extension' => 'md',
                'mime' => 'text/markdown',
                'callback' => [$this, 'export_conversation_as_markdown'],
            ];
        }

        if (!isset($formats['html'])) {
            $formats['html'] = [
                'label' => __('HTML', 'ai-assistant'),
                'description' => __('Standalone web page transcript.', 'ai-assistant'),
                'extension' => 'html',
                'mime' => 'text/html',
                'callback' => [$this, 'export_conversation_as_html'],
            ];
        }

        if (!isset($formats['json'])) {
            $formats['json'] = [
                'label' => __('JSON', 'ai-assistant'),
                'description' => __('Raw messages and metadata for backup or import workflows.', 'ai-assistant'),
                'extension' => 'json',
                'mime' => 'application/json',
                'callback' => [$this, 'export_conversation_as_json'],
            ];
        }

        return $formats;
    }

    /**
     * Get registered export formats for conversations.
     *
     * Other plugins can add formats such as EPUB by filtering
     * `ai_assistant_conversation_export_formats`. Each format needs:
     * - label: Human-readable label for the UI.
     * - description: Optional tooltip/help text.
     * - extension: File extension without a leading dot.
     * - mime: Download MIME type.
     * - callback: Callable receiving ($conversation, $format).
     *
     * The callback may return a string download body, a WP_Error, or an array
     * with content, filename, and/or mime keys.
     */
    public function get_export_formats($conversation = null) {
        $formats = [];

        /**
         * Filters conversation export formats.
         *
         * @param array      $formats      Export format definitions keyed by format slug.
         * @param array|null $conversation Conversation export data during download, null while building UI config.
         */
        $formats = apply_filters('ai_assistant_conversation_export_formats', $formats, $conversation);

        return $this->normalize_export_formats($formats);
    }

    public function get_transcript_export_messages(array $conversation, array $format) {
        $messages = isset($conversation['messages']) && is_array($conversation['messages'])
            ? $conversation['messages']
            : [];

        /**
         * Filters messages before transcript-style exports render them.
         *
         * The built-in filter shrinks tool calls by removing provider IDs and
         * redacting full read_file/write_file contents. Plugins that export
         * readable formats such as EPUB can apply this same filter.
         *
         * @param array $messages     Conversation messages.
         * @param array $conversation Full conversation export data.
         * @param array $format       Export format definition.
         */
        return apply_filters('ai_assistant_conversation_export_shrink_tool_calls', $messages, $conversation, $format);
    }

    /**
     * Adds transcript-safe Markdown and HTML fragments to each export message.
     *
     * The original provider message payload stays intact in `content`; derived
     * fields are built from the filtered transcript view.
     */
    public function prepare_conversation_messages_for_export(array $conversation, array $format) {
        $raw_messages = isset($conversation['messages']) && is_array($conversation['messages'])
            ? $conversation['messages']
            : [];
        $raw_messages = array_map([$this, 'remove_export_message_representations'], $raw_messages);

        $render_conversation = array_merge($conversation, [
            'messages' => $raw_messages,
        ]);
        $render_messages = $this->get_transcript_export_messages($render_conversation, $format);
        $include_tool_calls = !empty($conversation['include_tool_calls']);
        $prepared_messages = [];

        foreach ($raw_messages as $index => $message) {
            if (!is_array($message)) {
                $prepared_messages[] = $message;
                continue;
            }

            $render_message = isset($render_messages[$index]) && is_array($render_messages[$index])
                ? $render_messages[$index]
                : $message;
            $markdown = $this->message_to_plain_text($render_message, $include_tool_calls);

            if ($markdown !== '' && !$this->message_content_matches_export_representation($message['content'] ?? '', $markdown)) {
                $message['markdown'] = $markdown;
            }

            $html = $markdown !== '' ? $this->render_export_markdown_content($markdown) : '';
            if ($html !== '' && !$this->message_content_matches_export_representation($message['content'] ?? '', $html)) {
                $message['html'] = $html;
            }
            $prepared_messages[] = $message;
        }

        $conversation['messages'] = $prepared_messages;

        return $conversation;
    }

    private function remove_export_message_representations($message) {
        if (is_array($message)) {
            unset($message['markdown'], $message['html']);
        }

        return $message;
    }

    private function message_content_matches_export_representation($content, $representation) {
        return is_string($content) && $content === $representation;
    }

    private function remove_export_representations_from_json_export(array $conversation) {
        if (empty($conversation['messages']) || !is_array($conversation['messages'])) {
            return $conversation;
        }

        foreach ($conversation['messages'] as &$message) {
            if (is_array($message)) {
                unset($message['markdown'], $message['html']);
            }
        }
        unset($message);

        return $conversation;
    }

    public function get_export_formats_for_config() {
        $config = [];
        foreach ($this->get_export_formats() as $slug => $format) {
            $config[] = [
                'format' => $slug,
                'label' => $format['label'],
                'description' => $format['description'],
                'extension' => $format['extension'],
            ];
        }
        return $config;
    }

    public function shrink_file_tool_calls_for_export($messages, $conversation, $format) {
        if (!is_array($messages)) {
            return [];
        }

        $tool_names_by_id = $this->collect_tool_names_by_id($messages);
        $redacted_messages = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                $redacted_messages[] = $message;
                continue;
            }

            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as &$tool_call) {
                    if (!is_array($tool_call)) {
                        continue;
                    }

                    $tool_name = $tool_call['function']['name'] ?? '';
                    unset($tool_call['id']);

                    if ($this->is_file_content_tool($tool_name) && isset($tool_call['function']['arguments'])) {
                        $tool_call['function']['arguments'] = $this->redact_file_tool_content_value(
                            $tool_call['function']['arguments'],
                            $tool_name,
                            'arguments'
                        );
                    }
                }
                unset($tool_call);
            }

            if (isset($message['tool_call_id'])) {
                $tool_name = $tool_names_by_id[$message['tool_call_id']] ?? '';
                unset($message['tool_call_id']);

                if ($tool_name !== '' && empty($message['name'])) {
                    $message['name'] = $tool_name;
                }

                if ($this->is_file_content_tool($tool_name) && isset($message['content'])) {
                    $message['content'] = $this->redact_file_tool_content_value(
                        $message['content'],
                        $tool_name,
                        'result'
                    );
                }
            }

            if (isset($message['content']) && is_array($message['content'])) {
                foreach ($message['content'] as &$block) {
                    if (!is_array($block)) {
                        continue;
                    }

                    if (($block['type'] ?? '') === 'tool_use') {
                        $tool_name = $block['name'] ?? '';
                        unset($block['id']);

                        if ($this->is_file_content_tool($tool_name) && isset($block['input'])) {
                            $block['input'] = $this->redact_file_tool_content_value(
                                $block['input'],
                                $tool_name,
                                'arguments'
                            );
                        }
                    } elseif (($block['type'] ?? '') === 'tool_result') {
                        $tool_name = $tool_names_by_id[$block['tool_use_id'] ?? ''] ?? '';
                        unset($block['tool_use_id']);

                        if ($this->is_file_content_tool($tool_name) && isset($block['content'])) {
                            $block['content'] = $this->redact_file_tool_content_value(
                                $block['content'],
                                $tool_name,
                                'result'
                            );
                        }
                    }
                }
                unset($block);
            }

            $redacted_messages[] = $message;
        }

        return $redacted_messages;
    }

    private function collect_tool_names_by_id(array $messages) {
        $tool_names_by_id = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $tool_call) {
                    if (!is_array($tool_call) || empty($tool_call['id'])) {
                        continue;
                    }
                    $tool_names_by_id[$tool_call['id']] = $tool_call['function']['name'] ?? '';
                }
            }

            if (!isset($message['content']) || !is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use' && !empty($block['id'])) {
                    $tool_names_by_id[$block['id']] = $block['name'] ?? '';
                }
            }
        }

        return $tool_names_by_id;
    }

    private function is_file_content_tool($tool_name) {
        return in_array($tool_name, ['read_file', 'write_file'], true);
    }

    private function redact_file_tool_content_value($value, $tool_name, $context) {
        if (is_array($value)) {
            return $this->redact_file_tool_content_array($value, $tool_name);
        }

        if (!is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->json_encode($this->redact_file_tool_content_array($decoded, $tool_name));
        }

        if ($context === 'result' && $tool_name === 'read_file') {
            return '[' . $tool_name . ' content omitted from export]';
        }

        return $value;
    }

    private function redact_file_tool_content_array(array $value, $tool_name) {
        foreach ($value as $key => $item) {
            if (in_array($key, ['content', 'contents'], true)) {
                $value[$key] = '[' . $tool_name . ' content omitted from export]';
            } elseif (is_array($item)) {
                $value[$key] = $this->redact_file_tool_content_array($item, $tool_name);
            }
        }

        return $value;
    }

    private function normalize_export_formats($formats) {
        $normalized = [];

        foreach ((array) $formats as $slug => $format) {
            if (!is_array($format)) {
                continue;
            }

            $slug = sanitize_key(is_string($slug) ? $slug : ($format['format'] ?? ''));
            if ($slug === '' || empty($format['callback']) || !is_callable($format['callback'])) {
                continue;
            }

            $extension = isset($format['extension']) ? (string) $format['extension'] : $slug;
            $extension = preg_replace('/[^a-zA-Z0-9._-]/', '', $extension);
            $extension = $extension !== '' ? ltrim($extension, '.') : 'txt';

            $normalized[$slug] = array_merge($format, [
                'label' => sanitize_text_field($format['label'] ?? ucfirst($slug)),
                'description' => sanitize_text_field($format['description'] ?? ''),
                'extension' => $extension,
                'mime' => sanitize_text_field($format['mime'] ?? 'application/octet-stream'),
                'callback' => $format['callback'],
            ]);
        }

        return $normalized;
    }

    public function get_conversation_export_data($conversation_id) {
        $conversation_id = intval($conversation_id);
        if ($conversation_id <= 0) {
            return new \WP_Error('invalid_conversation', __('Invalid conversation ID', 'ai-assistant'));
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return new \WP_Error('conversation_not_found', __('Conversation not found', 'ai-assistant'));
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            return new \WP_Error('permission_denied', __('Permission denied', 'ai-assistant'));
        }

        $messages = $this->get_messages($post);
        $author_id = intval($post->post_author);
        $author_display_name = '';
        if ($author_id > 0 && function_exists('get_userdata')) {
            $author = get_userdata($author_id);
            if ($author && !empty($author->display_name)) {
                $author_display_name = $this->single_line_text($author->display_name);
            }
        }

        return [
            'id' => $conversation_id,
            'title' => $post->post_title,
            'summary' => $post->post_excerpt ?: '',
            'messages' => $messages,
            'message_count' => count($messages),
            'provider' => get_post_meta($conversation_id, '_ai_provider', true) ?: '',
            'model' => get_post_meta($conversation_id, '_ai_model', true) ?: '',
            'system_prompt' => get_post_meta($conversation_id, '_ai_system_prompt', true) ?: '',
            'created' => $post->post_date_gmt ?: $post->post_date,
            'modified' => $post->post_modified_gmt ?: $post->post_modified,
            'author_id' => $author_id,
            'author_display_name' => $author_display_name,
            'include_tool_calls' => false,
        ];
    }

    public function admin_post_export_conversation() {
        check_admin_referer('ai_assistant_chat');

        $conversation_id = intval($_GET['conversation_id'] ?? 0);
        $format_slug = sanitize_key($_GET['format'] ?? 'markdown');
        $conversation = $this->get_conversation_export_data($conversation_id);

        if (is_wp_error($conversation)) {
            wp_die(esc_html($conversation->get_error_message()), '', ['response' => 403]);
        }

        $conversation['include_tool_calls'] = isset($_GET['include_tool_calls']) && $_GET['include_tool_calls'] === '1';
        $conversation = $this->prepare_conversation_messages_for_export($conversation, [
            'format' => $format_slug,
            'extension' => $format_slug,
            'mime' => '',
        ]);

        $formats = $this->get_export_formats($conversation);
        if (empty($formats[$format_slug])) {
            wp_die(esc_html__('Export format not found.', 'ai-assistant'), '', ['response' => 400]);
        }

        $format = $formats[$format_slug];
        $conversation = $this->prepare_conversation_messages_for_export($conversation, $format);
        $result = call_user_func($format['callback'], $conversation, $format);

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => 500]);
        }

        if (is_array($result)) {
            if (!array_key_exists('content', $result)) {
                wp_die(esc_html__('Export callback did not return content.', 'ai-assistant'), '', ['response' => 500]);
            }
            $content = (string) $result['content'];
            $mime = sanitize_text_field($result['mime'] ?? $format['mime']);
            $filename = sanitize_file_name($result['filename'] ?? $this->build_export_filename($conversation, $format));
        } else {
            $content = (string) $result;
            $mime = $format['mime'];
            $filename = $this->build_export_filename($conversation, $format);
        }

        $this->send_export_download($content, $filename, $mime);
    }

    public function export_conversation_as_json(array $conversation, array $format) {
        $conversation = $this->remove_export_representations_from_json_export($conversation);
        $conversation = $this->move_system_prompt_into_json_messages($conversation);

        return [
            'filename' => $this->build_export_filename($conversation, $format),
            'mime' => $format['mime'],
            'content' => $this->json_encode([
                'schema' => 'ai-assistant-conversation-export/v1',
                'exported_at' => gmdate('c'),
                'conversation' => $conversation,
            ]),
        ];
    }

    private function move_system_prompt_into_json_messages(array $conversation) {
        $system_prompt = isset($conversation['system_prompt']) && is_scalar($conversation['system_prompt'])
            ? (string) $conversation['system_prompt']
            : '';
        unset($conversation['system_prompt']);

        if ($system_prompt === '') {
            return $conversation;
        }

        if (empty($conversation['messages']) || !is_array($conversation['messages'])) {
            $conversation['messages'] = [];
        }

        $first_message = $conversation['messages'][0] ?? null;
        if (
            is_array($first_message)
            && ($first_message['role'] ?? '') === 'system'
            && ($first_message['content'] ?? '') === $system_prompt
        ) {
            return $conversation;
        }

        array_unshift($conversation['messages'], [
            'role' => 'system',
            'content' => $system_prompt,
        ]);

        return $conversation;
    }

    public function export_conversation_as_markdown(array $conversation, array $format) {
        $lines = [];
        $title = $this->single_line_text($conversation['title'] ?: __('Conversation', 'ai-assistant'));
        $transcript = $this->get_export_transcript($conversation, $format);

        $lines[] = '# ' . $title;
        $lines[] = '';
        $lines[] = '- Conversation ID: ' . $conversation['id'];
        $lines[] = '- Messages: ' . $transcript['message_count'];
        if ($transcript['tool_call_count'] > 0) {
            $lines[] = '- Tool calls: ' . $transcript['tool_call_count'];
        }

        if (!empty($conversation['author_display_name'])) {
            $lines[] = '- Author: ' . $this->single_line_text($conversation['author_display_name']);
        }
        if (!empty($conversation['provider'])) {
            $lines[] = '- Provider: ' . $conversation['provider'];
        }
        if (!empty($conversation['model'])) {
            $lines[] = '- Model: ' . $conversation['model'];
        }
        if (!empty($conversation['created'])) {
            $lines[] = '- Created: ' . $conversation['created'];
        }
        if (!empty($conversation['modified'])) {
            $lines[] = '- Modified: ' . $conversation['modified'];
        }

        if (!empty($conversation['summary'])) {
            $lines[] = '';
            $lines[] = '## Summary';
            $lines[] = '';
            $lines[] = trim($conversation['summary']);
        }

        $lines[] = '';
        $lines[] = '## Messages';

        foreach ($transcript['messages'] as $message) {
            $lines[] = '';
            $lines[] = '### ' . $message['role_label'];
            $lines[] = '';
            $lines[] = $message['content'] !== '' ? $message['content'] : '_No text content_';
        }

        return implode("\n", $lines) . "\n";
    }

    public function export_conversation_as_html(array $conversation, array $format) {
        $title = $this->single_line_text($conversation['title'] ?: __('Conversation', 'ai-assistant'));
        $transcript = $this->get_export_transcript($conversation, $format);
        $meta = [
            __('Conversation ID', 'ai-assistant') => $conversation['id'],
            __('Messages', 'ai-assistant') => $transcript['message_count'],
        ];

        if ($transcript['tool_call_count'] > 0) {
            $meta[__('Tool calls', 'ai-assistant')] = $transcript['tool_call_count'];
        }

        if (!empty($conversation['author_display_name'])) {
            $meta[__('Author', 'ai-assistant')] = $this->single_line_text($conversation['author_display_name']);
        }
        if (!empty($conversation['provider'])) {
            $meta[__('Provider', 'ai-assistant')] = $conversation['provider'];
        }
        if (!empty($conversation['model'])) {
            $meta[__('Model', 'ai-assistant')] = $conversation['model'];
        }
        if (!empty($conversation['created'])) {
            $meta[__('Created', 'ai-assistant')] = $this->format_export_datetime($conversation['created']);
        }

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . $this->html_escape($title) . '</title>';
        $html .= '<style>' . $this->get_export_html_conversation_view_styles() . '</style>';
        $html .= '</head><body>';
        $html .= '<main class="ai-export-shell">';
        $html .= '<header class="ai-export-header">';
        $html .= '<div class="ai-export-title-group">';
        $html .= '<p class="ai-export-kicker">' . $this->html_escape(__('AI Assistant Transcript', 'ai-assistant')) . '</p>';
        $html .= '<h1>' . $this->html_escape($title) . '</h1>';
        $html .= '</div>';
        $html .= '<dl class="ai-export-meta">';
        foreach ($meta as $label => $value) {
            $html .= '<div><dt>' . $this->html_escape($label) . '</dt><dd>' . $this->html_escape($value) . '</dd></div>';
        }
        $html .= '</dl>';
        $html .= '</header>';
        $html .= '<div id="ai-assistant-messages" class="ai-export-messages">';

        if (!empty($conversation['summary'])) {
            $html .= '<section class="ai-conversation-summary">';
            $html .= '<div class="ai-summary-header">';
            $html .= '<span class="ai-summary-title">' . $this->html_escape(__('Conversation Summary', 'ai-assistant')) . '</span>';
            $html .= '</div>';
            $html .= '<div class="ai-summary-content">' . $this->render_export_markdown_content(trim($conversation['summary'])) . '</div>';
            $html .= '</section>';
        }

        foreach ($transcript['messages'] as $message) {
            $role = $message['role'];
            $base_role = $message['base_role'];
            $role_class = $this->html_class($role);
            $message_classes = 'ai-message ai-message-' . $base_role;
            if ($role_class !== $base_role) {
                $message_classes .= ' ai-message-' . $role_class;
            }
            $html .= '<section class="' . $this->html_escape($message_classes) . '" aria-label="' . $this->html_escape($message['role_label']) . '">';
            $html .= '<span class="ai-message-role">' . $this->html_escape($message['role_label']) . '</span>';
            $html .= '<div class="ai-message-content">' . $this->render_export_markdown_content($message['content'] !== '' ? $message['content'] : __('No text content', 'ai-assistant')) . '</div>';
            $html .= '</section>';
        }

        $html .= '</div></main></body></html>';

        return $html;
    }

    private function get_export_transcript(array $conversation, array $format) {
        $include_tool_calls = !empty($conversation['include_tool_calls']);
        $rows = [];
        $message_count = 0;
        $tool_call_count = 0;

        foreach ($this->get_transcript_export_messages($conversation, $format) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $visible_message_text = $this->message_to_visible_export_message_text($message);
            $has_visible_message = $visible_message_text !== '';
            $has_tool_payload = $this->message_has_export_tool_payload($message);
            $tool_call_count += $this->count_export_tool_calls($message);

            if ($has_visible_message) {
                $message_count++;
            }

            if (!$include_tool_calls && !$has_visible_message) {
                continue;
            }

            $content = $this->message_to_plain_text($message, $include_tool_calls);
            if ($content === '' && !$has_tool_payload) {
                continue;
            }

            $role = $this->get_export_message_role($message);
            $base_role = $this->html_class($message['role'] ?? 'message') ?: 'message';

            if (!$has_visible_message && $has_tool_payload) {
                $role = $this->message_has_export_tool_result_payload($message)
                    ? __('Tool result', 'ai-assistant')
                    : __('Tool call', 'ai-assistant');
                $base_role = 'tool';
            }

            $rows[] = [
                'message' => $message,
                'role' => $role,
                'base_role' => $base_role,
                'role_label' => $this->get_export_message_role_label(array_merge($message, [
                    'role' => $role,
                ]), $conversation),
                'content' => $content,
            ];
        }

        return [
            'messages' => $rows,
            'message_count' => $message_count,
            'tool_call_count' => $tool_call_count,
        ];
    }

    private function message_to_visible_export_message_text(array $message) {
        $role = strtolower($this->single_line_text($message['role'] ?? ''));
        if ($role === 'tool') {
            return '';
        }

        return $this->message_content_to_plain_text($message['content'] ?? '', false);
    }

    private function count_export_tool_calls(array $message) {
        $count = 0;

        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tool_call) {
                if (is_array($tool_call)) {
                    $count++;
                }
            }
        }

        foreach ($this->get_export_message_content_blocks($message) as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $count++;
            }
        }

        return $count;
    }

    private function message_has_export_tool_payload(array $message) {
        $role = strtolower($this->single_line_text($message['role'] ?? ''));
        if ($role === 'tool') {
            return true;
        }

        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            return true;
        }

        foreach ($this->get_export_message_content_blocks($message) as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'tool_use' || $type === 'tool_result') {
                return true;
            }
        }

        return false;
    }

    private function message_has_export_tool_result_payload(array $message) {
        $role = strtolower($this->single_line_text($message['role'] ?? ''));
        if ($role === 'tool') {
            return true;
        }

        foreach ($this->get_export_message_content_blocks($message) as $block) {
            if (($block['type'] ?? '') === 'tool_result') {
                return true;
            }
        }

        return false;
    }

    private function get_export_message_content_blocks(array $message) {
        if (empty($message['content']) || !is_array($message['content'])) {
            return [];
        }

        return array_filter($message['content'], function($block) {
            return is_array($block);
        });
    }

    private function get_export_html_conversation_view_styles() {
        return '
            html{height:100%}
            body{min-height:100%;margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;font-size:13px;line-height:1.4}
            a{color:#2271b1;text-decoration:none}
            a:hover{text-decoration:underline}
            .ai-export-shell{max-width:1100px;min-height:100vh;margin:0 auto;display:flex;flex-direction:column}
            .ai-export-header{padding:28px 24px 20px;background:#fff}
            .ai-export-title-group{min-width:0;max-width:780px}
            .ai-export-kicker{margin:0 0 7px;color:#2271b1;font-size:11px;font-weight:700;line-height:1.3;text-transform:uppercase}
            h1{margin:0;color:#1d2327;font-size:26px;font-weight:600;line-height:1.2}
            .ai-export-meta{display:flex;flex-wrap:wrap;gap:7px;margin:16px 0 0;padding:0;color:#50575e;font-size:12px}
            .ai-export-meta div{display:flex;gap:5px;min-width:0;padding:4px 8px;background:#f6f7f7;border-radius:4px}
            .ai-export-meta dt{font-weight:600}
            .ai-export-meta dd{margin:0;overflow-wrap:anywhere}
            #ai-assistant-messages{flex:1;padding:20px;display:flex;flex-direction:column;gap:10px;background:#f0f0f1}
            .ai-message{max-width:85%;display:flex;flex-direction:column;gap:4px}
            .ai-message-user{align-self:flex-end;align-items:flex-end}
            .ai-message-assistant,.ai-message-error,.ai-message-system,.ai-message-tool,.ai-message-message{align-self:flex-start;align-items:flex-start}
            .ai-message-role{margin:0 4px;color:#646970;font-size:11px;line-height:1.2}
            .ai-message-content{min-width:0;box-sizing:border-box;padding:8px 12px;border-radius:8px;line-height:1.5;font-size:13px;overflow-wrap:anywhere}
            .ai-message-user .ai-message-content{background:#2271b1;color:#fff;border-bottom-right-radius:4px}
            .ai-message-assistant .ai-message-content,.ai-message-message .ai-message-content{background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-bottom-left-radius:4px}
            .ai-message-tool .ai-message-content{background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-bottom-left-radius:4px}
            .ai-message-system .ai-message-content{background:#fff8e5;color:#1d2327;border:1px solid #dba617}
            .ai-message-error .ai-message-content{background:#fcf0f1;color:#8a0f0f;border:1px solid #d63638}
            .ai-message-content p,.ai-summary-content p{margin:0 0 .65em}
            .ai-message-content p:last-child,.ai-summary-content p:last-child{margin-bottom:0}
            .ai-message-content h2,.ai-message-content h3,.ai-message-content h4,.ai-summary-content h2,.ai-summary-content h3,.ai-summary-content h4{font-size:13px;font-weight:600;line-height:1.4;margin:.8em 0 .35em}
            .ai-message-content h2:first-child,.ai-message-content h3:first-child,.ai-message-content h4:first-child,.ai-summary-content h2:first-child,.ai-summary-content h3:first-child,.ai-summary-content h4:first-child{margin-top:0}
            .ai-message-content code,.ai-summary-content code{background:rgba(0,0,0,.08);padding:2px 5px;border-radius:3px;font-family:Consolas,Monaco,monospace;font-size:12px}
            .ai-message-user .ai-message-content code{background:rgba(255,255,255,.2)}
            .ai-message-content pre,.ai-summary-content pre{background:#1d2327;color:#f0f0f1;padding:10px;border-radius:4px;overflow-x:auto;margin:6px 0;white-space:pre}
            .ai-message-content pre code,.ai-summary-content pre code{background:none;padding:0;color:inherit}
            .ai-conversation-summary{background:#f0f6fc;border:1px solid #c3d9ed;border-radius:8px;margin-bottom:10px}
            .ai-summary-header{display:flex;align-items:center;gap:8px;padding:10px 12px}
            .ai-summary-title{font-weight:600;font-size:12px;color:#1d2327;flex:1}
            .ai-summary-content{padding:10px 12px 12px;font-size:13px;line-height:1.5;color:#1d2327;border-top:1px solid #c3d9ed;overflow-wrap:anywhere}
            @media screen and (max-width:782px){.ai-export-header{padding:22px 15px 16px}h1{font-size:22px}#ai-assistant-messages{padding:15px}.ai-message{max-width:95%}}
            @media print{body{background:#fff}.ai-export-shell{max-width:none;min-height:0;border:0}#ai-assistant-messages{background:#fff;padding:16px 0}.ai-message{break-inside:avoid}}
        ';
    }

    private function format_export_datetime($datetime) {
        $datetime = $this->single_line_text($datetime);
        if ($datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $format = 'F j, Y \a\t g:i A';
        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp);
        }

        return date($format, $timestamp);
    }

    private function get_export_message_role($message) {
        $role = $this->single_line_text($message['role'] ?? 'message');
        if ($role === 'tool' && !empty($message['name'])) {
            $role .= ': ' . $this->single_line_text($message['name']);
        }
        return $role;
    }

    private function get_export_message_role_label($message, array $conversation = []) {
        $role = $this->get_export_message_role($message);
        if (strtolower($role) === 'user') {
            $display_name = $this->single_line_text($conversation['author_display_name'] ?? '');
            if ($display_name !== '') {
                return $display_name;
            }
        }

        return ucfirst($role);
    }

    private function message_to_plain_text($message, $include_tool_calls = false) {
        $parts = [];
        $content = $this->message_content_to_plain_text($message['content'] ?? '', $include_tool_calls);
        if ($content !== '') {
            $parts[] = $content;
        }

        if ($include_tool_calls && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tool_call) {
                if (!is_array($tool_call)) {
                    continue;
                }
                $tool_name = $tool_call['function']['name'] ?? 'unknown';
                $text = '[Tool: ' . $tool_name . ']';
                if (isset($tool_call['function']['arguments']) && $tool_call['function']['arguments'] !== '') {
                    $text .= "\n" . $this->format_jsonish_text($tool_call['function']['arguments']);
                }
                $parts[] = $text;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function render_export_markdown_content($content) {
        if ($content === null || $content === '') {
            return '';
        }

        $code_blocks = [];
        $content = preg_replace("/\r\n?/", "\n", (string) $content);
        $content = preg_replace('/\s+$/', '', $content);

        $content = preg_replace_callback('/```([\w-]+)?[^\S\n]*\n([\s\S]*?)```/', function($matches) use (&$code_blocks) {
            $token = '@@AI_ASSISTANT_CODE_BLOCK_' . count($code_blocks) . '@@';
            $code_blocks[] = '<pre><code class="language-' . $this->html_escape($matches[1] ?? '') . '">' .
                $this->html_escape(preg_replace('/^\n+|\n+$/', '', $matches[2] ?? '')) .
                '</code></pre>';
            return "\n\n" . $token . "\n\n";
        }, $content);

        $content = $this->html_escape($content);

        // Inline markdown.
        $content = preg_replace('/`([^`]+)`/', '<code>$1</code>', $content);
        $content = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $content);
        $content = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $content);
        $content = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($matches) {
            return '<a href="' . $matches[2] . '">' . $matches[1] . '</a>';
        }, $content);

        // Block markdown.
        $content = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $content);
        $content = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $content);

        $content = $this->render_export_markdown_blocks($content);
        $content = preg_replace_callback('/@@AI_ASSISTANT_CODE_BLOCK_(\d+)@@/', function($matches) use ($code_blocks) {
            $index = intval($matches[1]);
            return $code_blocks[$index] ?? '';
        }, $content);

        return $this->sanitize_export_markdown_html($content);
    }

    private function render_export_markdown_blocks($content) {
        $html = [];
        $blocks = preg_split('/\n{2,}/', (string) $content);

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            $paragraph_lines = [];

            $flush_paragraph = function() use (&$html, &$paragraph_lines) {
                if (empty($paragraph_lines)) {
                    return;
                }
                $html[] = '<p>' . implode('<br>', $paragraph_lines) . '</p>';
                $paragraph_lines = [];
            };

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    $flush_paragraph();
                    continue;
                }

                if (preg_match('/^<h[234]>.*<\/h[234]>$/', $line) || preg_match('/^@@AI_ASSISTANT_CODE_BLOCK_\d+@@$/', $line)) {
                    $flush_paragraph();
                    $html[] = $line;
                    continue;
                }

                $paragraph_lines[] = $line;
            }

            $flush_paragraph();
        }

        return implode('', $html);
    }

    private function sanitize_export_markdown_html($html) {
        $allowed_html = [
            'a' => [
                'href' => true,
            ],
            'br' => [],
            'code' => [
                'class' => true,
            ],
            'em' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'p' => [],
            'pre' => [],
            'strong' => [],
        ];

        return wp_kses($html, $allowed_html);
    }

    private function message_content_to_plain_text($content, $include_tool_calls = false) {
        if (is_string($content)) {
            return trim($this->strip_file_context_for_display($content));
        }

        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $this->strip_file_context_for_display($block);
                continue;
            }

            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? '';
            if ($type === 'text' && isset($block['text'])) {
                $parts[] = $this->strip_file_context_for_display((string) $block['text']);
            } elseif ($type === 'tool_use') {
                if (!$include_tool_calls) {
                    continue;
                }
                $tool = $block['name'] ?? 'unknown';
                $text = '[Tool: ' . $tool . ']';
                if (isset($block['input'])) {
                    $text .= "\n" . (is_string($block['input'])
                        ? $this->format_jsonish_text($block['input'])
                        : $this->json_encode($block['input']));
                }
                $parts[] = $text;
            } elseif ($type === 'tool_result') {
                if (!$include_tool_calls) {
                    continue;
                }
                $text = '[Tool Result]';
                if (isset($block['content'])) {
                    $result_text = $this->message_content_to_plain_text($block['content'], $include_tool_calls);
                    if ($result_text !== '') {
                        $text .= "\n" . $result_text;
                    }
                }
                $parts[] = $text;
            } else {
                $parts[] = $this->json_encode($block);
            }
        }

        return trim(implode("\n\n", array_filter($parts, function($part) {
            return trim((string) $part) !== '';
        })));
    }

    private function format_jsonish_text($text) {
        $decoded = json_decode((string) $text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->json_encode($decoded);
        }
        return (string) $text;
    }

    private function build_export_filename(array $conversation, array $format) {
        $title = sanitize_file_name($conversation['title'] ?: 'conversation');
        if ($title === '') {
            $title = 'conversation';
        }
        return sprintf('ai-conversation-%d-%s.%s', $conversation['id'], $title, $format['extension']);
    }

    private function single_line_text($text) {
        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }

    private function html_escape($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function html_class($text) {
        return preg_replace('/[^a-z0-9_-]/', '-', strtolower((string) $text));
    }

    private function json_encode($value) {
        if (function_exists('wp_json_encode')) {
            return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function send_export_download($content, $filename, $mime) {
        $filename = sanitize_file_name($filename ?: 'ai-conversation-export.txt');
        if ($filename === '') {
            $filename = 'ai-conversation-export.txt';
        }

        if (!headers_sent()) {
            nocache_headers();
            $content_type = $mime ?: 'application/octet-stream';
            if ($this->is_text_mime_type($content_type)) {
                $content_type .= '; charset=utf-8';
            }
            header('Content-Type: ' . $content_type);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
        }

        echo $content;
        exit;
    }

    private function is_text_mime_type($mime) {
        return strpos($mime, 'text/') === 0
            || in_array($mime, ['application/json', 'application/xml'], true);
    }

    public function render_messages_meta_box($post) {
        $messages = $this->get_messages($post);
        if (empty($messages)) {
            echo '<p>' . esc_html__('No messages in this conversation.', 'ai-assistant') . '</p>';
            return;
        }

        echo '<div class="ai-conversation-history">';
        foreach ($messages as $message) {
            $role = esc_attr($message['role']);
            $content = $this->format_message_content($message['content']);
            echo '<div class="ai-history-message ai-history-' . $role . '">';
            echo '<strong>' . esc_html(ucfirst($role)) . ':</strong>';
            echo '<div class="ai-history-content">' . $content . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<style>
            .ai-conversation-history { max-height: 600px; overflow-y: auto; }
            .ai-history-message { padding: 10px; margin: 5px 0; border-radius: 5px; }
            .ai-history-user { background: #e3f2fd; }
            .ai-history-assistant { background: #f5f5f5; }
            .ai-history-system { background: #fff3e0; font-style: italic; }
            .ai-history-content { margin-top: 5px; white-space: pre-wrap; }
            .ai-history-content pre { background: #263238; color: #aed581; padding: 10px; overflow-x: auto; }
        </style>';
    }

    public function render_continue_meta_box($post) {
        $conversation_url = admin_url('tools.php?page=ai-conversations&conversation=' . $post->ID);
        echo '<p>';
        echo '<a href="' . esc_url($conversation_url) . '" class="button button-primary button-large" style="width:100%;text-align:center;">';
        echo esc_html__('Continue this conversation', 'ai-assistant');
        echo '</a>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Opens the chat interface with this conversation loaded.', 'ai-assistant') . '</p>';
    }

    private function format_message_content($content) {
        if (is_array($content)) {
            $text = '';
            foreach ($content as $block) {
                if (is_array($block)) {
                    if (isset($block['type']) && $block['type'] === 'text') {
                        $text .= $block['text'];
                    } elseif (isset($block['type']) && $block['type'] === 'tool_use') {
                        $text .= "\n[Tool: " . $block['name'] . "]\n";
                    } elseif (isset($block['type']) && $block['type'] === 'tool_result') {
                        $text .= "\n[Tool Result]\n";
                    }
                }
            }
            $content = $text ?: json_encode($content, JSON_PRETTY_PRINT);
        }
        $content = $this->strip_file_context_for_display($content);
        return wp_kses_post(nl2br(esc_html($content)));
    }

    private function strip_file_context_for_display($content) {
        if (!is_string($content)) {
            return $content;
        }

        if (!preg_match('/\n*<ai_assistant_file_context>\n(.*?)\n<\/ai_assistant_file_context>/s', $content, $matches)) {
            return $content;
        }

        $visible = trim(str_replace($matches[0], '', $content));
        $payload = json_decode($matches[1], true);
        $files = is_array($payload) && isset($payload['files']) && is_array($payload['files'])
            ? $payload['files']
            : [];

        if (empty($files)) {
            return $visible;
        }

        $summary = "\n\n[Attached files]\n";
        foreach ($files as $file) {
            $name = $file['original_name'] ?? $file['filename'] ?? 'Attachment';
            $path = $file['wp_content_path'] ?? '';
            $summary .= '- ' . $name . ($path ? ' (' . $path . ')' : '') . "\n";
        }

        return ($visible ?: __('Attached files', 'ai-assistant')) . rtrim($summary);
    }

    public function add_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['message_count'] = __('Messages', 'ai-assistant');
                $new_columns['last_message'] = __('Last Message', 'ai-assistant');
            }
        }
        return $new_columns;
    }

    public function render_columns($column, $post_id) {
        $post = get_post($post_id);
        $messages = $this->get_messages($post);

        switch ($column) {
            case 'message_count':
                echo count($messages);
                break;
            case 'last_message':
                if (!empty($messages)) {
                    $last = end($messages);
                    $content = is_array($last['content']) ? '[Complex content]' : $last['content'];
                    $content = $this->strip_file_context_for_display($content);
                    echo esc_html(wp_trim_words($content, 10, '...'));
                }
                break;
        }
    }

    public function ajax_save_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $messages_base64 = $_POST['messages'] ?? '';
        $title = sanitize_text_field($_POST['title'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $system_prompt = isset($_POST['system_prompt']) && is_scalar($_POST['system_prompt'])
            ? (string) wp_unslash($_POST['system_prompt'])
            : '';
        $existing_title_status = $conversation_id > 0 ? get_post_meta($conversation_id, '_ai_title_status', true) : '';
        $title_status = $existing_title_status ?: 'generated';

        // Decode base64 to get message count for title generation
        $messages_json = base64_decode($messages_base64);
        $messages = json_decode($messages_json, true) ?: [];

        if (empty($title) && !empty($messages)) {
            $title_status = 'placeholder';
            $first_user_message = array_filter($messages, function($m) {
                return $m['role'] === 'user';
            });
            $first = reset($first_user_message);
            if ($first) {
                $content = is_array($first['content']) ? '' : $first['content'];
                $title = wp_trim_words($content, 8, '...');
            }
            if (empty($title)) {
                $title = __('Conversation', 'ai-assistant') . ' ' . date('Y-m-d H:i');
            }
        } elseif (!empty($title)) {
            $title_status = $existing_title_status === 'manual' ? 'manual' : 'generated';
        }

        $post_data = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => get_current_user_id(),
            'post_content' => $messages_base64,
        ];

        if ($conversation_id > 0) {
            $existing = get_post($conversation_id);
            if ($existing && $existing->post_type === self::POST_TYPE) {
                $post_data['ID'] = $conversation_id;
            }
        }

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }

        update_post_meta($post_id, '_ai_message_count', count($messages));
        update_post_meta($post_id, '_ai_provider', $provider);
        update_post_meta($post_id, '_ai_model', $model);
        update_post_meta($post_id, '_ai_title_status', $title_status);
        update_post_meta($post_id, '_ai_system_prompt', function_exists('wp_slash') ? wp_slash($system_prompt) : $system_prompt);

        wp_send_json_success([
            'conversation_id' => $post_id,
            'title' => get_the_title($post_id),
            'title_status' => $title_status,
        ]);
    }

    public function ajax_load_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        // Use direct query to avoid post type checks and filters
        global $wpdb;
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_excerpt, post_author, post_type
             FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
            $conversation_id
        ));

        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Get meta in single query
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key IN ('_ai_provider', '_ai_model', '_ai_title_status')",
            $conversation_id
        ), OBJECT_K);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => $post->post_title,
            'messages_base64' => $post->post_content ?: '',
            'summary' => $post->post_excerpt ?: '',
            'provider' => isset($meta['_ai_provider']) ? $meta['_ai_provider']->meta_value : '',
            'model' => isset($meta['_ai_model']) ? $meta['_ai_model']->meta_value : '',
            'title_status' => isset($meta['_ai_title_status']) ? $meta['_ai_title_status']->meta_value : '',
        ]);
    }

    public function ajax_list_conversations() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (!current_user_can('edit_others_posts')) {
            $args['author'] = get_current_user_id();
        }

        $query = new \WP_Query($args);
        $conversations = [];

        foreach ($query->posts as $post) {
            $message_count = get_post_meta($post->ID, '_ai_message_count', true);
            $conversations[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'title_status' => get_post_meta($post->ID, '_ai_title_status', true) ?: '',
                'date' => $post->post_modified,
                'message_count' => $message_count ?: 0,
            ];
        }

        wp_send_json_success(['conversations' => $conversations]);
    }

    public function ajax_delete_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('delete_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_delete_post($conversation_id, true);

        wp_send_json_success(['deleted' => true]);
    }

    public function ajax_rename_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        if (empty($title)) {
            wp_send_json_error(['message' => 'Title cannot be empty']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_update_post([
            'ID' => $conversation_id,
            'post_title' => $title,
        ]);
        update_post_meta($conversation_id, '_ai_title_status', 'manual');

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => $title,
            'title_status' => 'manual',
        ]);
    }

    public function ajax_get_conversation_for_summary() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $messages = $this->get_messages($post);
        $message_count = count($messages);

        // Format messages for summarization prompt
        $formatted = [];
        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = is_array($msg['content']) ? $this->extract_text_content($msg['content']) : $msg['content'];
            if ($content) {
                $formatted[] = ucfirst($role) . ': ' . $content;
            }
        }

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => $post->post_title,
            'message_count' => $message_count,
            'existing_summary' => $post->post_excerpt,
            'messages_text' => implode("\n\n", $formatted),
            'provider' => get_post_meta($conversation_id, '_ai_provider', true) ?: '',
            'model' => get_post_meta($conversation_id, '_ai_model', true) ?: '',
        ]);
    }

    private function extract_text_content($content_array) {
        $text = '';
        foreach ($content_array as $block) {
            if (is_array($block) && isset($block['type'])) {
                if ($block['type'] === 'text' && isset($block['text'])) {
                    $text .= $block['text'] . ' ';
                } elseif ($block['type'] === 'tool_use' && isset($block['name'])) {
                    $text .= '[Used tool: ' . $block['name'] . '] ';
                }
            }
        }
        return trim($text);
    }

    public function ajax_save_summary() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $summary = sanitize_textarea_field($_POST['summary'] ?? '');

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        if (empty($summary)) {
            wp_send_json_error(['message' => 'Summary cannot be empty']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_update_post([
            'ID' => $conversation_id,
            'post_excerpt' => $summary,
        ]);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'summary' => $summary,
        ]);
    }
}
