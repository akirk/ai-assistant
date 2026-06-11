# AI Assistant

An AI-powered chat interface for WordPress. Bring your own API key or connect to a local LLM.

## Screenshots

#### "Old Style"

![Screenshot Old Style](screenshot.png)

#### Floating Button style

![Screenshot Floating Button Style](screenshot-floating-button.png)

## Features

- **Multiple LLM Providers**: Anthropic (Claude), OpenAI, and local models via Ollama/LM Studio
- **Tool System**: Execute PHP, read/write/edit files, query the database, manage plugins and themes
- **Git-Compatible Change Tracking**: All AI modifications are tracked using a git-compatible structure in `wp-content`
- **Revert & Reapply**: Undo any AI change and optionally reapply it later
- **Patch Export/Import**: Download changes as `.patch` files or apply patches from elsewhere
- **Portable History**: Download your Playground zip with full git history intact for local git operations
- **Conversation History**: Persistent storage with automatic summarization and context budgeting
- **Streaming Responses**: Real-time output as the AI generates responses
- **Settings Persistence**: Configuration stored in localStorage, surviving Playground restarts

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An API key for Anthropic or OpenAI, or a locally running Ollama/LM Studio instance

## Installation

1. Upload the plugin to `/wp-content/plugins/ai-assistant`
2. Activate through the Plugins menu
3. Configure your API keys in Settings > AI Assistant

## Configuration

Go to **Settings > AI Assistant** to configure:

- **Provider**: Choose between Anthropic, OpenAI, or Local (Ollama/LM Studio)
- **Model**: Select which model to use
- **API Keys**: Enter your provider API keys
- **Anthropic Prompt Caching**: Optionally enable Anthropic prompt caching when you frequently use Anthropic with long prompts. It is off by default because cache writes can cost more.
- **Local Endpoint**: Configure Ollama/LM Studio endpoint (default: `http://localhost:11434`)

## Usage

The AI Assistant panel appears in the WordPress admin screen meta area (alongside Help and Screen Options). Click to expand and start chatting.

### Available Tools

| Tool | Description |
|------|-------------|
| `read_file` | Read file contents from wp-content, with offset chunks and targeted search windows |
| `write_file` | Create new files (use `edit_file` for modifications) |
| `edit_file` | Edit existing files via search/replace operations |
| `delete_file` | Delete files |
| `find` | Find files by path/glob or search content; `mode=paths` returns matching file paths without snippets |
| `run_php` | Execute PHP code in the WordPress environment |
| `environment_info` | Get active plugins with titles/descriptions, themes, WP/PHP versions |
| `db_query` | Execute SELECT/DESCRIBE/SHOW queries on the database |
| `install_plugin` | Install a plugin from WordPress.org |
| `ability` | List, inspect, or execute WordPress abilities (plugin-exposed actions) |
| `navigate` | Suggest a clickable link to a URL within the site |
| `get_page_html` | Get HTML of elements on the current page |
| `pick_image` | Ask the user to choose or upload an image |
| `skill` | Load skill documents with specialized WordPress knowledge |
| `summarize_conversation` | Generate a summary of the current conversation |
| `inspect_tool_result` | Inspect a narrow slice of a cached large tool result |

Filesystem tools (`read_file`, `write_file`, `edit_file`, `delete_file`, and `find`) use a direct plugin endpoint with a signed token, so they can still run if a previous file edit causes WordPress to fatal during bootstrap. WordPress-backed tools still use AJAX because they need a loaded WordPress environment.

### Context and Token Management

The assistant keeps long sessions usable by controlling what gets sent to the LLM provider. These safeguards run locally in the browser and WordPress install; the plugin does not send telemetry or diagnostics to a central service.

- **Tool result compaction**: Oversized tool results are compacted before provider requests and before conversation saves. Large strings are truncated with metadata that tells the model what was omitted.
- **Duplicate string removal**: If a tool result repeats the same large payload in multiple fields, such as both `content` and `html`, later duplicates are replaced with a short pointer to the first copy.
- **Stale large-result pruning**: Once the assistant has already responded to a compacted or oversized tool result, that older resolved tool-call/result pair is omitted from future provider requests. Small full results, such as skill documents, ability schemas, and concise errors, are kept as useful working context.
- **Recent `read_file` working set**: Chunked file reads are keyed by normalized `path` plus the requested window (`offset`/`max_length`) or search window (`search`, `occurrence`, `before_lines`, `after_lines`). Provider requests keep the latest distinct `read_file` windows together, default 8 via `ai_assistant_stale_read_file_result_keep_limit`, so the model does not alternate between re-reading already-read chunks while older duplicate windows can still be pruned. Successful `write_file`, `delete_file`, and non-empty `edit_file` mutations invalidate same-path read windows.
- **Inspectable large results**: Raw tool results are cached in the active browser session. If the provider-safe result was compacted, the model can call `inspect_tool_result` with the previous `tool_use_id`, a JSON path, and either a search window or offset chunk. This lets it recover exact details without putting the entire payload back into the prompt.
- **Targeted file reads**: `read_file` supports `offset`/`max_length` for chunks and `search` with `before_lines`/`after_lines`/`occurrence` for function-sized inspection. The file-editing prompt tells the model to re-read the exact current range before editing when the current content is not already in the active turn.
- **Request budgeting**: Before each provider call, messages are compacted and older history can be trimmed if the serialized request still exceeds the configured local budget.
- **One-shot stricter retry**: If a provider rejects a request with a context, prompt-length, input-token, or input-token rate-limit error, the assistant rebuilds the same request with stricter local compaction and retries once.
- **Prompt cache accounting**: Provider-reported cache reads and cache writes are shown separately when available. OpenAI prompt cache routing is used automatically; Anthropic prompt caching must be enabled in settings.

The raw inspect cache is intentionally not part of the saved conversation payload. It is available for the active browser runtime and disappears after reload, which keeps very large private tool output out of long-term conversation storage and out of future provider prompts.

### Tool Permissions for Local LLMs

Local LLMs (Ollama, LM Studio) and cloud providers receive the same enabled tool set. Use **Settings → AI Assistant → Tool Permissions** to choose which tools are available. Smaller local models usually behave best with a narrower enabled set, especially fewer write/code-execution tools.

### Auto-approve

Enable Auto-approve to skip confirmation dialogs for tool execution. It starts enabled by default on `my.wordpress.net` and disabled elsewhere. Use with caution.

### Conversation Exports

Use the export button in a conversation to download the current chat. Built-in formats are Markdown, HTML, and JSON, all registered through the same export filter API that other plugins use. The export dropdown also includes an "Include tool calls" checkbox for generating a fuller technical transcript.

Other plugins can add formats with `ai_assistant_conversation_export_formats`. This supports binary formats such as EPUB because the callback runs server-side and controls the MIME type, extension, filename, and content.

```php
add_filter('ai_assistant_conversation_export_formats', function($formats) {
    $formats['epub'] = [
        'label' => __('EPUB', 'my-plugin'),
        'description' => __('E-reader friendly conversation export.', 'my-plugin'),
        'extension' => 'epub',
        'mime' => 'application/epub+zip',
        'callback' => 'my_plugin_export_ai_conversation_epub',
    ];

    return $formats;
});

function my_plugin_export_ai_conversation_epub(array $conversation, array $format) {
    return [
        'filename' => sanitize_file_name($conversation['title']) . '.epub',
        'mime' => 'application/epub+zip',
        'content' => My_Plugin_Epub_Builder::from_ai_conversation($conversation),
    ];
}
```

## AI Changes

Find this under **Tools > AI Changes**. Every file the AI creates or modifies is tracked using a git-compatible structure stored in `wp-content/.git`.

- **View diffs**: Click any file to see exactly what changed
- **Commit history**: Browse individual commits with expandable diffs
- **Combine commits**: Squash adjacent AI commits into one history entry
- **Checkout**: Check out a previous commit to test that state; new AI changes continue from the checked-out commit
- **Revert**: Restore any file to its original state
- **Reapply**: Re-apply previously reverted changes
- **Export**: Select files and download a unified `.patch` file
- **Import**: Apply patch files to your installation
- **PHP Linting**: Automatic syntax checking for PHP files
- **Git-compatible**: Download your Playground zip and use standard git commands locally
- **Plugin ZIP downloads**: Modified plugins get a "Download ZIP" link on the Plugins page

### Using Git Locally

When you download your Playground as a ZIP, the `wp-content` folder contains a full git repository with two branches:

- **main**: The original state before AI modifications
- **ai-changes**: Each AI modification as a separate commit with a descriptive message

Each file change creates its own commit with a message describing why the change was made (e.g., "Add form validation", "Fix login redirect bug"). To explore:

```bash
cd wp-content
git log --oneline ai-changes   # See all AI commits
git diff main..ai-changes      # See all changes at once
git show <commit-sha>          # Inspect a specific change
```

This makes it safe to experiment—you can always undo what the AI did.

### Recovery Mode Integration

If the AI writes code that breaks WordPress (e.g., a PHP syntax error), the assistant detects consecutive failed requests and warns you that something is wrong—while the current page still works.

To recover:

1. Click the grid icon in the Playground top bar
2. Select **Recovery Mode** to boot into troubleshooting mode
3. Activate the AI Assistant plugin and go to **Tools → AI Changes**
4. Revert the problematic changes

The recovery screen highlights recently modified plugins to help identify the culprit.

## Plugin Integration

Other plugins can expose their functionality to the AI by registering **WordPress Abilities**. See the [WordPress Abilities API handbook](https://developer.wordpress.org/apis/abilities-api/) for the core API, and [docs/plugin-integration.md](docs/plugin-integration.md) for AI Assistant-specific hooks and guidance.

For best results, expose focused abilities with clear input/output schemas instead of requiring the AI to infer database structure or call plugin internals. Register `ai_assistant_ability_domains` keywords so the assistant considers your plugin's abilities specifically for relevant user requests. If your plugin works with images, prefer accepting a Media Library attachment ID when a local asset is required. The assistant can ask the user to choose or drop an image with `pick_image`, which uploads selected files from the browser and returns `attachment_id`, local `url`, attribution, and source metadata. If browser download or Media Library upload fails for a search result, the picker offers the remote image URL as a fallback.

Plugins can add contextual first-message tips with `ai_assistant_welcome_tips`. The returned array is keyed by first URL path component, with one or more tips for each route. Use tips to suggest natural next actions inside the welcome message on plugin-specific screens, and leave detailed behavior to ability descriptions and instructions.

Plugins with browser UI can also register JavaScript callbacks for completed tool calls. For example, a page script can listen for its own `ability` execution and refresh visible UI after the server-side ability succeeds.

### Tool Extension Hooks

High-risk development tools are registered through hooks so they can later move into a companion plugin. The optional `dev-tools.php` module currently adds file mutation, plugin installation, and raw PHP execution with:

- `ai_assistant_tool_definitions`
- `ai_assistant_tool_meta`
- `ai_assistant_client_tool_definitions`
- `ai_assistant_file_endpoint_tools`
- `ai_assistant_execute_tool`
- `ai_assistant_system_prompt`

The bundled dev tools module is loaded by an optional `require_once` in `ai-assistant.php`; comment out that include to disable it, or move the module into a companion plugin that includes the file. Tool modules register their schemas, metadata, endpoint routing, and execution handlers directly through the filters above.

Core keeps the read-only/context tools and permission UI; extensions provide their own schemas, metadata, endpoint routing, and execution handlers.

## Development

To run the plugin locally using WordPress Playground:

```bash
npx @wp-playground/cli server --auto-mount
```

This starts a local WordPress Playground instance and automatically mounts the plugin directory, so any changes you make to the source files are reflected immediately.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.
