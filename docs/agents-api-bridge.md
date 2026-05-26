# Agents API Bridge — Proof of Concept

A demonstration of how AI Assistant could plug into [Automattic/agents-api](https://github.com/Automattic/agents-api) without changing the JS-owned loop, the chat UI, or the conversation storage.

## What this PoC adds

A single new class — `AI_Assistant\Agents_API_Bridge` — that implements the substrate's `WP_Agent_Tool_Executor` contract and adapts the existing `Executor`. AJAX tool calls can be routed through it; the substrate then handles:

- Parameter validation against the tool's schema (required fields, basic shape)
- Action policy resolution through the substrate's 8-layer resolver — your YOLO toggle becomes one layer (a `WP_Agent_Action_Policy_Provider` returning `direct`)
- Forward compatibility with future substrate primitives without further code changes:
  - Tool tiering for local LLMs ([agents-api#181](https://github.com/Automattic/agents-api/issues/181)) — your `core` / `extended` split plus `enable_tools` becomes a `WP_Agent_Tool_Tier_Resolver`
  - Approval boundary ([agents-api#94](https://github.com/Automattic/agents-api/issues/94)) — destructive tools can opt into preview / accept / reject via `WP_Agent_Pending_Action`
  - Substrate-wide telemetry through `agents_api_loop_event` and `agents_api_ability_executed` actions

## What it deliberately does NOT touch

- The JS loop (browser keeps owning turn orchestration and streaming)
- The chat panel UI and admin meta area
- The Conversations CPT and history storage
- Git-compatible change tracking, patch export, Playground portability
- The signed direct-file endpoint for mutating filesystem tools
- Provider connection setup (LLM proxy stays as-is)

## How to enable

1. Install the substrate as a sibling plugin (or via Composer when the canonical package ships):

   ```bash
   git clone https://github.com/Automattic/agents-api.git wp-content/plugins/agents-api
   ```

2. Wire the bridge in `ai-assistant.php`:

   ```php
   if ( class_exists( '\\AgentsAPI\\AI\\Tools\\WP_Agent_Tool_Executor' ) ) {
       $bridge      = new \AI_Assistant\Agents_API_Bridge( $executor, $tools );
       $api_handler = new \AI_Assistant\API_Handler( $tools, $bridge );
   } else {
       $api_handler = new \AI_Assistant\API_Handler( $tools, $executor );
   }
   ```

3. Update `API_Handler::handle_execute_tool()` to recognize the bridge:

   ```php
   $result = $this->executor instanceof \AI_Assistant\Agents_API_Bridge
       ? $this->executor->dispatch( $tool_name, $arguments, $permission, $conversation_id )
       : $this->executor->execute_tool( $tool_name, $arguments, $permission, $conversation_id );
   ```

Both paths produce the same shape, so the JS layer continues consuming results identically.

## Substrate ↔ AI Assistant mapping

| AI Assistant today | Substrate equivalent |
|---|---|
| `Executor::execute_tool()` | `WP_Agent_Tool_Executor::executeWP_Agent_Tool_Call()` (this bridge) |
| Conversation auto-summarization | `WP_Agent_Conversation_Compaction` (boundary-safe tool_call/tool_result splits) |
| Core / extended tool tiering + `enable_tools` | `WP_Agent_Tool_Tier_Resolver` + meta-abilities `agents/ability-search` / `agents/ability-call` ([#181](https://github.com/Automattic/agents-api/issues/181)) |
| YOLO mode | `WP_Agent_Action_Policy` (`direct` / `preview` / `forbidden`) |
| `ability` tool | Substrate consumes Abilities API directly + lifecycle filters ([#94](https://github.com/Automattic/agents-api/issues/94)) |
| `skill` tool | `WP_Agent_Memory_Registry` + `WP_Agent_Context_Section_Registry` with retrieval policies |
| `summarize_conversation` | `WP_Agent_Conversation_Compaction` + `WP_Agent_Markdown_Section_Compaction_Adapter` |
| Conversation streaming | `agents_api_loop_event` action sink |
| Conversation export (JSON / Markdown / HTML) | Stays consumer-side; substrate provides the transcript shape |
| Conversation CPT storage | Stays consumer-side; substrate provides the `WP_Agent_Conversation_Store` interface if a future adapter is desired |

## What stays yours

The product identity — chat UI, git-compatible change tracking, patch export, Playground portability, the concrete tool implementations (`read_file` / `write_file` / `edit_file` / `find` / `run_php`), and the LLM proxy.

## What you'd stop maintaining (over time)

Multi-turn loop bookkeeping, compaction policy, tool tiering, action policy resolution. The pieces that hurt most when an edge case appears in long-running conversations.
