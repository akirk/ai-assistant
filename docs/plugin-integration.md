# Integrating Your Plugin with AI Assistant

The AI Assistant discovers and executes plugin functionality through the **WordPress Abilities API**. By registering abilities, you give the AI structured, safe access to your plugin's features — instead of having it guess its way through `run_php` or `db_query`.

This document covers everything you need to write a well-behaved ability integration.

## Prerequisites

Abilities require the WordPress Abilities API plugin. Guard your registration code so it silently no-ops when the API is not present:

```php
add_action( 'plugins_loaded', function () {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }
    add_action( 'wp_abilities_api_categories_init', 'myplugin_register_ability_category' );
    add_action( 'wp_abilities_api_init',            'myplugin_register_abilities' );
} );
```

## Registering a Category

Each plugin registers one category that groups all its abilities together.

```php
function myplugin_register_ability_category() {
    wp_register_ability_category( 'my-plugin', [
        'label'       => __( 'My Plugin', 'my-plugin' ),
        'description' => __( 'Manage invoices and billing.', 'my-plugin' ),
    ] );
}
```

The category slug (`my-plugin` above) is used in every ability ID and should match your plugin's text domain.

## Registering Abilities

```php
wp_register_ability( 'my-plugin/get-invoice', [
    'label'               => __( 'Get Invoice', 'my-plugin' ),
    'description'         => 'Returns full details for an invoice by ID, including line items, totals, and payment status.',
    'category'            => 'my-plugin',
    'input_schema'        => [ ... ],   // JSON Schema, or omit for no-argument abilities
    'output_schema'       => [ ... ],   // JSON Schema describing what you return
    'execute_callback'    => 'myplugin_get_invoice',
    'permission_callback' => 'myplugin_can_read',
    'meta' => [
        'show_in_rest' => true,
        'annotations'  => [
            'instructions' => 'Present the invoice total in bold. Link to the invoice using the url field.',
            'readonly'     => true,
            'destructive'  => false,
            'idempotent'   => true,
        ],
    ],
] );
```

### Ability ID naming

Use `plugin-slug/verb-noun`, matching the category slug: `my-plugin/create-invoice`, `my-plugin/list-invoices`, `my-plugin/delete-invoice`.

### The `description` field

Write this for an AI to read, not a human. Describe what data the ability returns and what it operates on — not just what it "does":

```php
// Too vague:
'description' => 'Gets an invoice.'

// Better:
'description' => 'Returns full details for a single invoice by ID: line items, subtotal, tax, total, status (draft/sent/paid), and a pdf_url for downloading.'
```

## Input Schema

The input schema is a [JSON Schema](https://json-schema.org/) object. The AI reads it before calling your ability to know what arguments to pass.

```php
'input_schema' => [
    'type'       => 'object',
    'properties' => [
        'id' => [
            'type'        => 'integer',
            'description' => 'Invoice ID',
        ],
        'status' => [
            'type'        => 'string',
            'enum'        => [ 'draft', 'sent', 'paid', 'overdue' ],
            'description' => 'Filter by payment status',
        ],
        'date_from' => [
            'type'        => 'string',
            'description' => 'ISO 8601 date, e.g. 2025-01-01',
        ],
    ],
    'required'             => [ 'id' ],
    'additionalProperties' => false,   // recommended — prevents the AI from hallucinating extra params
],
```

Tips:
- Use `enum` to constrain string values — the AI picks from the list rather than guessing.
- Add `'description'` to every property that isn't self-evident.
- Set `additionalProperties: false` to make the schema strict.
- Omit `input_schema` entirely for abilities that take no arguments (list/read-all patterns).

## Output Schema

Describe what your `execute_callback` returns. The AI uses this to understand how to work with the result — especially which fields are IDs that feed into other ability calls.

```php
'output_schema' => [
    'type'  => 'array',
    'items' => [
        'type'       => 'object',
        'properties' => [
            'id'     => [ 'type' => 'integer', 'description' => 'Use this ID in get-invoice, edit-invoice, and delete-invoice' ],
            'number' => [ 'type' => 'string' ],
            'total'  => [ 'type' => 'number' ],
            'status' => [ 'type' => 'string' ],
            'url'    => [ 'type' => 'string', 'description' => 'Link to this invoice in the admin' ],
        ],
    ],
],
```

Calling out which fields are IDs for other abilities (as in the `id` description above) is one of the most effective things you can do to help the AI chain calls correctly.

## Execute Callback

The callback receives the `$input` array and must return a plain PHP value or a `WP_Error`.

```php
function myplugin_get_invoice( $input ) {
    $id = absint( $input['id'] ?? 0 );

    if ( ! $id ) {
        return new WP_Error( 'missing_id', 'Invoice ID is required.' );
    }

    $invoice = MyPlugin\Invoice::find( $id );

    if ( ! $invoice ) {
        return new WP_Error( 'not_found', "Invoice {$id} not found." );
    }

    return $invoice->to_array();
}
```

- Always sanitize inputs. The AI generates them — treat them as untrusted user input.
- Return `WP_Error` for failures. Don't throw PHP exceptions.
- Return a plain array or scalar on success. The executor wraps it automatically.

## Permission Callback

A simple callable that returns `true` if the current user may run this ability.

```php
function myplugin_can_read() {
    return current_user_can( 'read' );
}

function myplugin_can_write() {
    return current_user_can( 'edit_posts' );
}
```

## Annotations

Annotations appear in `meta.annotations` and affect both the AI's behavior and the AI Assistant's settings UI.

| Annotation | Type | Meaning |
|---|---|---|
| `readonly` | bool | Ability only reads data, never writes |
| `destructive` | bool | Ability permanently deletes data — triggers stronger confirmation UI |
| `idempotent` | bool | Safe to call multiple times with the same arguments |
| `instructions` | string | Injected into the AI's context when it fetches this ability's schema |

### `instructions` — the most important annotation

This string is sent to the AI when it calls `ability { action: get }` to inspect your ability before using it. Use it to:

- Explain when to use this ability versus a similar one
- Describe how to handle no-results (create vs. report missing)
- Tell the AI how to batch calls efficiently
- Explain how to interpret ambiguous results
- Describe how to chain this ability with others

```php
'annotations' => [
    'instructions' => 'Use when looking up a single invoice. For bulk lookups, call list-invoices instead and filter client-side. If the invoice is not found, do NOT create a new one — report back to the user that it was not found.',
    'readonly'     => true,
],
```

## AI Assistant Filters

The AI Assistant plugin provides two filters that give you additional control over how the AI uses your abilities.

### `ai_assistant_ability_domains`

Tells the AI which topics belong to your plugin. The AI Assistant uses this to prefer your abilities over generic tools like `run_php` or `db_query` when the user asks about those topics.

```php
add_filter( 'ai_assistant_ability_domains', 'myplugin_ability_domains' );

function myplugin_ability_domains( $domains ) {
    $domains['my-plugin'] = 'invoices, billing, payments, clients, line items';
    return $domains;
}
```

The key is your plugin slug; the value is a comma-separated list of terms that should direct the AI toward your abilities. Without this filter, the AI may try to answer questions about your data using raw database queries or PHP execution.

### `ai_assistant_ability_instructions`

Inject instructions into the AI's context after a specific ability executes. Use this to control how the AI presents the result to the user.

```php
add_filter( 'ai_assistant_ability_instructions', 'myplugin_ability_instructions', 10, 4 );

function myplugin_ability_instructions( $instructions, $ability_id, $args, $result ) {
    if ( 'my-plugin/get-invoice' === $ability_id && ! empty( $result ) ) {
        $instructions = 'Present the invoice as a table. Show the total in bold. If pdf_url is present, include a "Download PDF" link.';
    }
    return $instructions;
}
```

Parameters: `$instructions` (current instructions string), `$ability_id` (e.g. `my-plugin/get-invoice`), `$args` (what the AI passed), `$result` (what your callback returned).

## Complete Example

```php
<?php
// In your plugin: includes/abilities.php

namespace MyPlugin;

function register_abilities() {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }

    add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_ability_category' );
    add_action( 'wp_abilities_api_init',            __NAMESPACE__ . '\register' );
    add_filter( 'ai_assistant_ability_domains',     __NAMESPACE__ . '\ability_domains' );
    add_filter( 'ai_assistant_ability_instructions', __NAMESPACE__ . '\ability_instructions', 10, 4 );
}

function ability_domains( $domains ) {
    $domains['my-plugin'] = 'invoices, billing, payments, clients';
    return $domains;
}

function ability_instructions( $instructions, $ability_id, $args, $result ) {
    if ( str_starts_with( $ability_id, 'my-plugin/' ) && ! empty( $result ) ) {
        $instructions = 'Present invoice amounts formatted as currency. Link invoice numbers using the url field.';
    }
    return $instructions;
}

function register_ability_category() {
    wp_register_ability_category( 'my-plugin', [
        'label'       => __( 'My Plugin', 'my-plugin' ),
        'description' => __( 'Manage invoices and billing.', 'my-plugin' ),
    ] );
}

function register() {
    wp_register_ability( 'my-plugin/list-invoices', [
        'label'       => __( 'List Invoices', 'my-plugin' ),
        'description' => 'Returns all invoices with their ID, number, client name, total, and status. Use to browse or filter invoices.',
        'category'    => 'my-plugin',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => [ 'draft', 'sent', 'paid', 'overdue' ],
                    'description' => 'Filter by status. Omit to return all.',
                ],
            ],
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'     => [ 'type' => 'integer', 'description' => 'Use in get-invoice, edit-invoice, delete-invoice' ],
                    'number' => [ 'type' => 'string' ],
                    'client' => [ 'type' => 'string' ],
                    'total'  => [ 'type' => 'number' ],
                    'status' => [ 'type' => 'string' ],
                    'url'    => [ 'type' => 'string' ],
                ],
            ],
        ],
        'execute_callback'    => __NAMESPACE__ . '\list_invoices',
        'permission_callback' => __NAMESPACE__ . '\can_read',
        'meta' => [
            'show_in_rest' => true,
            'annotations'  => [
                'readonly' => true,
            ],
        ],
    ] );

    wp_register_ability( 'my-plugin/create-invoice', [
        'label'       => __( 'Create Invoice', 'my-plugin' ),
        'description' => 'Create a new invoice. Returns the new invoice ID and its admin URL.',
        'category'    => 'my-plugin',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'client_name' => [ 'type' => 'string', 'description' => 'Client name', 'minLength' => 1 ],
                'amount'      => [ 'type' => 'number', 'description' => 'Invoice total in dollars' ],
                'due_date'    => [ 'type' => 'string', 'description' => 'Due date in YYYY-MM-DD format' ],
            ],
            'required'             => [ 'client_name', 'amount' ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'  => [ 'type' => 'integer' ],
                'url' => [ 'type' => 'string', 'description' => 'Admin URL to edit the new invoice' ],
            ],
        ],
        'execute_callback'    => __NAMESPACE__ . '\create_invoice',
        'permission_callback' => __NAMESPACE__ . '\can_write',
        'meta' => [
            'show_in_rest' => true,
            'annotations'  => [
                'instructions' => 'After creating, mention the invoice number and offer the url as a link to view it.',
                'readonly'     => false,
                'destructive'  => false,
                'idempotent'   => false,
            ],
        ],
    ] );
}

function can_read()  { return current_user_can( 'read' ); }
function can_write() { return current_user_can( 'edit_posts' ); }

function list_invoices( $input ) {
    $status = sanitize_text_field( $input['status'] ?? '' );
    // ... query your data ...
    return $results;
}

function create_invoice( $input ) {
    $client = sanitize_text_field( $input['client_name'] );
    $amount = floatval( $input['amount'] );
    $due    = sanitize_text_field( $input['due_date'] ?? '' );

    if ( ! $client || $amount <= 0 ) {
        return new \WP_Error( 'invalid_input', 'Client name and a positive amount are required.' );
    }

    $id = MyInvoice::create( $client, $amount, $due );

    if ( ! $id ) {
        return new \WP_Error( 'create_failed', 'Could not create the invoice.' );
    }

    return [
        'id'  => $id,
        'url' => admin_url( 'admin.php?page=my-plugin-invoices&id=' . $id ),
    ];
}
```

Load this file from your main plugin file or a class constructor:

```php
// In your main plugin file:
require_once __DIR__ . '/includes/abilities.php';
add_action( 'plugins_loaded', 'MyPlugin\register_abilities' );
```

## Checklist

Before shipping your ability integration, verify:

- [ ] Registration is guarded with `function_exists( 'wp_register_ability_category' )`
- [ ] Category and ability IDs use your plugin's slug as prefix
- [ ] `description` fields explain what data is returned, not just what the ability "does"
- [ ] `input_schema` uses `additionalProperties: false` on required-parameter abilities
- [ ] `output_schema` field descriptions identify which fields are IDs for other abilities
- [ ] `meta.annotations.instructions` guides the AI on call ordering, batching, and ambiguity handling
- [ ] `execute_callback` sanitizes all inputs
- [ ] `execute_callback` returns `WP_Error` on failure, not exceptions
- [ ] `ai_assistant_ability_domains` filter registers your domain keywords
- [ ] `readonly` / `destructive` annotations are accurate
