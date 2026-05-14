# AI Assistant - Developer Reference

See [README.md](README.md) for user-facing documentation, feature overview, and tool descriptions.

## Development Setup

To run the plugin locally using WordPress Playground:

```bash
npx @wp-playground/cli server --auto-mount
```

This starts a local WordPress Playground instance and automatically mounts the plugin directory, so any changes you make to the source files are reflected immediately.

## Testing with Playwright

Use the Playwright MCP to interact with the running WordPress Playground instance for manual/visual testing.

- **Base URL**: `http://127.0.0.1:9400`
- **Login**: username `admin`, password `password`
- **Settings page**: `http://127.0.0.1:9400/wp-admin/admin.php?page=ai-assistant-settings`

Use `browser_snapshot` (not screenshots) for interacting with page elements. Use `browser_navigate` to visit pages, `browser_click`/`browser_fill_form`/`browser_type` to interact, and `browser_evaluate` for direct JS when needed (e.g. clearing localStorage values).

## Directory Structure

```
ai-assistant/
├── assets/
│   ├── css/
│   │   └── chat.css              # All chat UI styles
│   └── js/
│       ├── chat-core.js          # Namespace, state, initialization
│       ├── chat-tools.js         # Tool definitions (Anthropic/OpenAI format)
│       ├── chat-providers.js     # LLM API calls, streaming, summarization
│       ├── chat-execution.js     # Tool execution, confirmations
│       ├── chat-ui.js            # DOM rendering, message display
│       └── chat-conversations.js # Persistence, drafts, sidebar
├── includes/
│   ├── class-chat-ui.php         # Script enqueue, panel HTML rendering
│   ├── class-conversations.php   # AJAX endpoints, CPT registration
│   ├── class-settings.php        # Settings page, options registration
│   ├── class-tools.php           # Server-side tool execution (run_php, etc.)
│   └── class-changes-admin.php   # Admin UI for viewing changes
├── skills/                       # AI skill definitions (markdown)
└── ai-assistant.php              # Main plugin file, singleton
```

## Design Decisions

### Why Custom Implementation (not wp-ai-client)

`wp-ai-client` routes all AI requests through WordPress REST endpoints (`/wp-ai/v1/generate`), meaning the PHP server communicates with AI providers.

This plugin makes AI calls directly from the browser (JavaScript → AI provider). This allows users to connect to local LLMs (like Ollama running on `localhost`) even when WordPress is hosted remotely. With `wp-ai-client`, a remote server would try to reach `localhost:11434` on itself — which wouldn't work.

The browser-side approach also enables full control over the tool calling loop, confirmation dialogs, and streaming responses. Tools can be a mix of client-side (e.g., `get_page_html`, `summarize_conversation`) and server-side via AJAX (e.g., `run_php`, `write_file`).

Note: `wp-ai-client` does work in WordPress Playground (where PHP runs in-browser), but breaks for local LLMs when WordPress is hosted elsewhere.

## JavaScript Architecture

All JS uses a shared namespace pattern via `window.aiAssistant`:

```javascript
// chat-core.js defines the namespace
window.aiAssistant = {
    messages: [],
    conversationId: null,
    isLoading: false,
    // ... state properties
};

// Other files extend it
$.extend(window.aiAssistant, {
    // module-specific methods
});
```

### File Responsibilities

| File | Key Methods |
|------|-------------|
| `chat-core.js` | `init()`, `bindEvents()`, `buildSystemPrompt()`, `setLoading()` |
| `chat-tools.js` | `getTools()`, `getToolsOpenAI()`, `getAllToolDefinitions()` |
| `chat-providers.js` | `sendMessage()`, `callLLM()`, `callAnthropic()`, `callOpenAI()`, `callLocalLLM()`, `generateConversationSummary()` |
| `chat-execution.js` | `processToolCalls()`, `executeTools()`, `executeSingleTool()`, `confirmAction()`, `handleToolResults()` |
| `chat-ui.js` | `addMessage()`, `startReply()`, `updateReply()`, `formatContent()`, `rebuildMessagesUI()` |
| `chat-conversations.js` | `saveConversation()`, `loadConversation()`, `newChat()`, `loadSidebarConversations()` |

### Script Load Order (dependencies in class-chat-ui.php)

1. `chat-core.js` (depends: jquery)
2. `chat-tools.js` (depends: chat-core)
3. `chat-providers.js` (depends: chat-core)
4. `chat-execution.js` (depends: chat-core)
5. `chat-ui.js` (depends: chat-core)
6. `chat-conversations.js` (depends: chat-core, chat-ui, chat-providers)

## Data Storage

### Conversations (Custom Post Type: `ai_conversation`)

- `post_title`: Auto-generated conversation title
- `post_content`: Base64-encoded JSON of full message history
- `post_excerpt`: LLM-generated summary for context management
- `post_status`: `publish`
- `post_author`: User who created the conversation

### Options (wp_options)

| Option | Description |
|--------|-------------|
| `ai_assistant_provider` | `anthropic`, `openai`, or `local` |
| `ai_assistant_model` | Model identifier (e.g., `claude-sonnet-4-20250514`) |
| `ai_assistant_summarization_model` | Optional different model for summarization |
| `ai_assistant_anthropic_api_key` | Encrypted API key |
| `ai_assistant_openai_api_key` | Encrypted API key |
| `ai_assistant_local_endpoint` | Ollama endpoint (default: `http://localhost:11434`) |
| `ai_assistant_system_prompt` | Custom system prompt |
| `ai_assistant_show_on_frontend` | Enable on frontend (0/1) |

## Tool System

Tools are defined in `chat-tools.js` and executed via `chat-execution.js`. Server-side execution happens in `class-tools.php`. See README.md for the full tool list.

### Tool Architecture

Tools are either client-side (executed in the browser) or server-side (executed via AJAX):

- **Client-side**: `get_page_html`, `summarize_conversation`
- **Server-side**: everything else, via `ai_assistant_execute_tool` AJAX action

The `ability` tool is a meta-tool: the LLM calls `ability: list` to discover registered WordPress abilities, then `ability: execute` to run one. This gives access to the entire WordPress Abilities API ecosystem without adding per-ability tool definitions.

### Confirmation Flow

1. Tool call received from LLM
2. `processToolCalls()` splits calls into immediate-execute vs needs-confirmation
3. `confirmAction()` / `confirmAllActions()` shows pending actions UI
4. `executeTools()` runs approved tools via `Promise.all` (parallel)
5. Results sent back to LLM via `handleToolResults()`

YOLO mode (`#ai-assistant-yolo` checkbox) skips confirmations for destructive tools.

### Tool Call Limit

After 10 rounds of tool calls, the loop stops. `handleToolResults()` in `chat-execution.js` enforces this.

## AJAX Endpoints

Registered in `class-conversations.php`:

| Action | Method | Description |
|--------|--------|-------------|
| `ai_assistant_save_conversation` | POST | Save conversation to CPT |
| `ai_assistant_load_conversation` | GET | Load conversation by ID |
| `ai_assistant_delete_conversation` | POST | Delete conversation |
| `ai_assistant_rename_conversation` | POST | Update conversation title |
| `ai_assistant_list_conversations` | GET | List user's conversations |
| `ai_assistant_get_conversation_for_summary` | GET | Get conversation text for summarization |
| `ai_assistant_save_summary` | POST | Save summary to post_excerpt |

Registered in `class-tools.php`:

| Action | Method | Description |
|--------|--------|-------------|
| `ai_assistant_execute_tool` | POST | Execute a tool server-side |

## LLM API Integration

All LLM calls happen client-side (browser) for WordPress Playground compatibility.

### Anthropic

- Direct fetch to `https://api.anthropic.com/v1/messages`
- Uses `anthropic-dangerous-direct-browser-access: true` header
- Streaming via SSE
- Tools sent in Anthropic format (`input_schema`)

### OpenAI

- Direct fetch to `https://api.openai.com/v1/chat/completions`
- Streaming via SSE with `stream: true`
- Tools sent in OpenAI format (`parameters`)

### Local (Ollama / LM Studio)

- Configurable endpoint (default `http://localhost:11434`)
- Tries `/v1/chat/completions` first, falls back to `/api/chat` (Ollama native)
- Context overflow on 500 triggers automatic message trimming

## UI Integration

The chat panel integrates with WordPress admin in two modes:

1. **Screen Meta Mode**: Injects into `#screen-meta` alongside Help/Screen Options tabs
2. **Standalone Mode**: Floating panel when screen-meta not available (frontend)

Panel HTML is generated in `class-chat-ui.php::get_panel_html()`.

## Skills System

Skills are markdown files in `/skills/` loaded into the system prompt on demand via the `skill` tool. Current skills:

- `conversation-context.md` - How to find/use context from past conversations
- `blocks-no-build.md` - How to create Gutenberg blocks without a build step
- `wp-app.md` - How to create app-like WordPress plugins through WpApp
- `my-wordpress.md` - Context for My WordPress / personal WordPress

## Key Patterns

### Message Format (internal)

```javascript
{
    role: 'user' | 'assistant',
    content: 'string' | [{ type: 'text', text: '...' }, { type: 'tool_use', ... }]
}
```

### Streaming Response Handling

1. `callLLM()` initiates request
2. `readSSEStream()` processes chunks
3. `updateReply()` renders incrementally
4. On complete, `processToolCalls()` checks for tool usage
