# Integrating Plugins with AI Assistant

AI Assistant discovers and executes plugin functionality through the [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) and decides whether to use them based on the [ability domains](#ai_assistant_ability_domains) expressed in your plugin.

This document covers only the AI Assistant-specific integration points: how the assistant chooses abilities, how to guide its behavior, how to refresh browser UI after ability execution, and how to add conversation export formats.

## Table of Contents

- [Abilities in AI Assistant](#abilities-in-ai-assistant)
- [Authoring for AI Use](#authoring-for-ai-use)
- [Annotations](#annotations)
- [Image Inputs](#image-inputs)
- [AI Assistant Filters](#ai-assistant-filters)
- [Browser Callbacks After Tool Calls](#browser-callbacks-after-tool-calls)
- [Conversation Export Formats](#conversation-export-formats)
- [Checklist](#checklist)

## Abilities in AI Assistant

Register abilities with the WordPress Abilities API. Once registered, AI Assistant exposes them through its `ability` tool:

- `list` discovers registered abilities, optionally filtered by category.
- `get` retrieves an ability's description, schemas, and annotations before use.
- `execute` runs an ability with structured arguments, subject to the assistant's confirmation and permission flow.

For best results, expose focused abilities with clear input/output schemas instead of requiring the assistant to infer database structure or call plugin internals with `run_php` or `db_query`.

Use the official WordPress handbook for the shared API surface:

- [Abilities API overview](https://developer.wordpress.org/apis/abilities-api/)
- [Getting started](https://developer.wordpress.org/apis/abilities-api/getting-started/)
- [PHP reference](https://developer.wordpress.org/apis/abilities-api/php-reference/)
- [REST API endpoints](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/)

## Authoring for AI Use

The Abilities API defines the required fields. AI Assistant works best when those fields are written for a model that needs to decide whether, when, and how to call your ability.

```php
wp_register_ability( 'my-plugin/get-invoice', [
    'label'               => __( 'Get Invoice', 'my-plugin' ),
    'description'         => 'Returns full details for one invoice by ID, including line items, totals, payment status, and pdf_url.',
    'category'            => 'my-plugin',
    'input_schema'        => [
        'type'                 => 'object',
        'properties'           => [
            'id' => [
                'type'        => 'integer',
                'description' => 'Invoice ID from my-plugin/list-invoices.',
            ],
        ],
        'required'             => [ 'id' ],
        'additionalProperties' => false,
    ],
    'output_schema'       => [
        'type'       => 'object',
        'properties' => [
            'id'      => [ 'type' => 'integer', 'description' => 'Use in edit-invoice or delete-invoice.' ],
            'number'  => [ 'type' => 'string' ],
            'total'   => [ 'type' => 'number' ],
            'status'  => [ 'type' => 'string' ],
            'pdf_url' => [ 'type' => 'string', 'description' => 'Download link for the invoice PDF.' ],
        ],
    ],
    'execute_callback'    => 'myplugin_get_invoice',
    'permission_callback' => 'myplugin_can_read',
    'meta'                => [
        'annotations' => [
            'instructions' => 'Present the invoice total in bold. If pdf_url is present, include it as a Download PDF link.',
            'readonly'     => true,
            'destructive'  => false,
        ],
    ],
] );
```

Guidelines:

- Prefer small, purpose-built abilities such as `list-invoices`, `get-invoice`, and `create-invoice` over one broad "do anything" ability.
- Describe returned data, not just the action. The assistant needs to know whether the result contains IDs, URLs, status fields, or follow-up inputs.
- Use property descriptions to explain which fields feed other abilities.
- Keep input schemas strict with `additionalProperties: false` when the ability has required parameters.
- Sanitize all input in the execute callback and return `WP_Error` for failures.

## Annotations

AI Assistant reads Abilities API annotations and uses them in both model context and UI decisions.

| Annotation | AI Assistant behavior |
|---|---|
| `instructions` | Added to the model context when the assistant inspects the ability. Use this for call ordering, batching, ambiguity handling, and result presentation. |
| `readonly` | Marks the ability as read-only. Read-only abilities can run in read-only tool mode and may be eligible for lighter confirmation flows. |
| `destructive` | Marks the ability as capable of permanent or destructive changes, which triggers stronger confirmation treatment. |

Use `instructions` when the raw schema is not enough:

```php
'annotations' => [
    'instructions' => 'Use list-invoices for bulk lookups. If get-invoice returns not_found, report that to the user instead of creating a replacement invoice.',
    'readonly'     => true,
],
```

## Image Inputs

If your ability needs an image that should live in WordPress, prefer accepting a Media Library attachment ID as input. AI Assistant can ask the user to choose an image with the built-in `pick_image` tool. The picker can search images or accept a dropped local photo, uploads selected files through the WordPress media endpoint, then returns the attachment ID, local URL, attribution, and source metadata.

If browser download or Media Library upload fails for a search result, the picker offers the selected remote image URL as a fallback.

Recommended input shape:

```php
'input_schema' => [
    'type'       => 'object',
    'properties' => [
        'background' => [
            'type'        => 'string',
            'description' => 'Media Library attachment ID.',
        ],
        'image_url' => [
            'type'        => 'string',
            'description' => 'Optional local or external image URL.',
        ],
        'attribution' => [
            'type'        => 'string',
            'description' => 'Optional attribution text.',
        ],
    ],
    'required'             => [ 'background' ],
    'additionalProperties' => false,
],
```

Recommended output shape:

```php
return [
    'attachment_id' => $attachment_id,
    'url'           => wp_get_attachment_url( $attachment_id ),
];
```

This keeps image search, user choice, and browser-side media upload in the assistant UI, while your plugin owns the plugin-specific setting or rendering rules.

## AI Assistant Filters

The AI Assistant plugin provides filters that help steer the assistant toward your abilities and shape its response after execution.

### `ai_assistant_ability_domains`

Use this filter to tell the assistant which topics belong to your plugin. This is the main way to make AI Assistant consider your plugin's abilities specifically when a user asks about your domain.

AI Assistant can discover all registered abilities, but it does not know which plugin should handle words like "invoice", "reservation", "member", or "gallery" unless you connect those terms to your ability namespace. The assistant includes these domain terms in its `ability` tool description so it reaches for your abilities before generic tools like `run_php` or `db_query`.

```php
add_filter( 'ai_assistant_ability_domains', 'myplugin_ability_domains' );

function myplugin_ability_domains( $domains ) {
    $domains['my-plugin'] = 'invoices, billing, payments, clients, line items';
    return $domains;
}
```

The key should match your plugin or ability namespace. The value is a comma-separated list of terms users naturally use when asking about your plugin. Include product names, content types, business objects, UI labels, and common synonyms.

### `ai_assistant_ability_instructions`

Use this filter to inject instructions into the assistant context after a specific ability executes. This is useful when the result needs a specific presentation or follow-up workflow.

```php
add_filter( 'ai_assistant_ability_instructions', 'myplugin_ability_instructions', 10, 4 );

function myplugin_ability_instructions( $instructions, $ability_id, $args, $result ) {
    if ( 'my-plugin/get-invoice' === $ability_id && ! empty( $result ) ) {
        $instructions = 'Present the invoice as a table. Show the total in bold. If pdf_url is present, include a Download PDF link.';
    }

    return $instructions;
}
```

Parameters:

- `$instructions`: Current instructions string.
- `$ability_id`: The ability that was just executed, for example `my-plugin/get-invoice`.
- `$args`: The arguments passed by the assistant.
- `$result`: The value returned by the ability callback.

## Browser Callbacks After Tool Calls

Some abilities change data that is already visible in a browser UI. For example, an app launcher ability might save a new background color server-side, but the current apps page needs JavaScript to repaint itself after the ability succeeds.

Use the browser callback API for this. It does not expose a new tool to the assistant. Instead, your page script subscribes to completed tool calls and reacts when AI Assistant executes your ability.

```js
(function() {
    function refreshAppsBackground(context) {
        // context.result is the ability result returned to the assistant.
        // context.arguments.arguments is the input passed to the ability.
        if (window.MyApps && typeof window.MyApps.reloadBackground === 'function') {
            window.MyApps.reloadBackground(context.result);
        }
    }

    var subscription = {
        criteria: {
            ability: 'my-apps/set-background-color',
            success: true
        },
        callback: refreshAppsBackground
    };

    if (window.aiAssistant && typeof window.aiAssistant.onToolCall === 'function') {
        window.aiAssistant.onToolCall(subscription.criteria, subscription.callback);
    } else {
        window.aiAssistantToolCallbacks = window.aiAssistantToolCallbacks || [];
        window.aiAssistantToolCallbacks.push(subscription);
    }
})();
```

`criteria` can be a tool name string, a predicate function, or an object. Object criteria support:

- `tool`: Tool name, for example `ability`, `execute_ability`, or `rest_api`.
- `ability`: Shorthand for a completed ability execution. This matches both `ability { action: "execute" }` and legacy `execute_ability`.
- `success`: `true` or `false`.
- `arguments` / `input`: Partial nested match against tool input.
- `result`: Partial nested match against tool output.

The callback receives:

```js
{
    id: 'tool-call-id',
    tool: 'ability',
    name: 'ability',
    arguments: { action: 'execute', ability: 'my-apps/set-background-color', arguments: {} },
    input: { action: 'execute', ability: 'my-apps/set-background-color', arguments: {} },
    result: {},
    output: {},
    success: true,
    provider: 'anthropic'
}
```

When you can declare a dependency on the AI Assistant script, depend on `ai-assistant-chat-core` and call `window.aiAssistant.onToolCall(...)` directly. The queued `window.aiAssistantToolCallbacks` form is useful when your script may load before the assistant panel script.

## Conversation Export Formats

Plugins can add export formats to the conversation export menu with `ai_assistant_conversation_export_formats`. The built-in Markdown, HTML, and JSON formats use this same filter. The callback runs on the server and can return text or binary content, so formats like EPUB are supported.

```php
add_filter( 'ai_assistant_conversation_export_formats', function ( array $formats ) {
    $formats['epub'] = [
        'label'       => __( 'EPUB', 'my-plugin' ),
        'description' => __( 'E-reader friendly conversation export.', 'my-plugin' ),
        'extension'   => 'epub',
        'mime'        => 'application/epub+zip',
        'callback'    => 'myplugin_export_ai_conversation_epub',
    ];

    return $formats;
} );

function myplugin_export_ai_conversation_epub( array $conversation, array $format ) {
    return [
        'filename' => sanitize_file_name( $conversation['title'] ) . '.epub',
        'mime'     => 'application/epub+zip',
        'content'  => MyPlugin_Epub_Builder::from_ai_conversation( $conversation ),
    ];
}
```

Text-only conversation example:

Prepared `markdown` and `html` message fields are derived for readable exporters, but each field is only present when it differs from the raw `content` value.

```php
$conversation = [
    'id'                 => 123,
    'title'              => 'Homepage copy edits',
    'summary'            => 'The user asked for a shorter hero headline.',
    'message_count'      => 2,
    'provider'           => 'openai',
    'model'              => 'gpt-4o',
    'created'            => '2026-05-13 10:00:00',
    'modified'           => '2026-05-13 10:04:00',
    'author_id'          => 1,
    'author_display_name' => 'Ada Lovelace',
    'include_tool_calls' => false,
    'messages'           => [
        [
            'role'     => 'user',
            'content'  => 'Make the homepage hero headline shorter.',
            'html'     => '<p>Make the homepage hero headline shorter.</p>',
        ],
        [
            'role'     => 'assistant',
            'content'  => [
                [
                    'type' => 'text',
                    'text' => 'Try "Build faster with WordPress" as the headline.',
                ],
            ],
            'markdown' => 'Try "Build faster with WordPress" as the headline.',
            'html'     => '<p>Try &quot;Build faster with WordPress&quot; as the headline.</p>',
        ],
    ],
];
```

Conversation with tool blocks example:

```php
$conversation['include_tool_calls'] = true;
$conversation['messages'] = [
    [
        'role'    => 'assistant',
        'content' => [
            [
                'type'  => 'text',
                'text'  => 'I will inspect the template first.',
            ],
            [
                'type'  => 'tool_use',
                'id'    => 'toolu_01',
                'name'  => 'read_file',
                'input' => [
                    'path' => 'themes/example/front-page.php',
                ],
            ],
        ],
    ],
    [
        'role'    => 'user',
        'content' => [
            [
                'type'        => 'tool_result',
                'tool_use_id' => 'toolu_01',
                'content'     => '<?php get_header(); ?>...',
            ],
        ],
    ],
];
```

## Checklist

Before shipping an AI Assistant integration, verify:

- [ ] Baseline ability registration follows the official Abilities API handbook.
- [ ] Abilities are focused and named with your plugin namespace.
- [ ] Descriptions and schema property descriptions identify returned IDs, URLs, and follow-up ability inputs.
- [ ] Input schemas are strict where appropriate.
- [ ] `readonly` and `destructive` annotations are accurate.
- [ ] `instructions` annotations or `ai_assistant_ability_instructions` explain presentation, batching, ambiguity, or follow-up behavior when needed.
- [ ] `ai_assistant_ability_domains` registers the terms users use for your plugin so AI Assistant considers these abilities for domain-specific requests.
- [ ] Image-related abilities accept remote URLs unless an existing attachment ID is required.
- [ ] Browser UI registers an `onToolCall` callback when it needs to refresh after ability execution.
- [ ] Custom conversation exporters use prepared `markdown` / `html` message fields for readable output, or pass raw message content through `ai_assistant_conversation_export_shrink_tool_calls`.
