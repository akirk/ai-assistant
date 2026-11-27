# Working with local LLMs

AI Assistant makes provider requests directly from the browser. A WordPress site can therefore use a model server running on the user's device, including LM Studio or Ollama. Local models receive the same enabled tool set as cloud models; several strategies keep the information sent to them focused and manageable.

## Budget against the loaded context

A model's advertised maximum context is different from the context allocated by its server. For example, a model capable of 262,144 tokens may be loaded with only 8,192 tokens. When LM Studio exposes loaded-instance context sizes through browser discovery, AI Assistant uses that allocation for request budgeting. It does not substitute the theoretical maximum when the allocation is unknown.

The chat runtime retains the discovered metadata and refreshes server status through the existing discovery cache, which expires after 60 seconds. If several loaded instances match the selected model, it uses the smallest reported context. Selecting a specific instance uses that instance's size.

The budget reserves up to 1,024 tokens for the response (one quarter of the window for smaller allocations), estimates input at four characters per token, and subtracts serialized tool definitions. The system prompt and serialized messages share the remaining allowance. Existing configured request limits still act as upper bounds.

This is an estimate, not a tokenizer measurement: language, code, tool templates, and server formatting affect actual token usage. Without valid loaded-context metadata, the existing configured limits remain in effect. This currently benefits LM Studio discovery; it does not infer Ollama allocation from a model's name or theoretical capacity.

## Discover capabilities on demand

The `ability` meta-tool exposes WordPress capabilities without inserting every registered ability schema into every request. The assistant can request a compact discovery index, fetch the schema for a relevant ability, and execute it with focused arguments. Short descriptions reduce discovery overhead while full schemas remain available when needed.

A narrower enabled tool set also reduces schema overhead. Users can choose available tools in the plugin settings.

## Retrieve only what is needed

Tools support focused access rather than requiring entire datasets or files. Examples include paged ability results, filters supplied by an ability, and file reads by offset or search window. Exact file ranges can be inspected before editing.

A short successful exchange may never need history summarization or overflow recovery: targeted retrieval can keep each request within a small window from the outset.

## Compact large results and inspect details later

Oversized tool results undergo structured compaction. Previews, shape information, and omission markers tell the model that more detail exists. Known loaded-context budgets also reduce per-result and string limits, so one result consumes only a portion of the request allowance.

The `inspect_tool_result` tool lets the model retrieve narrow slices of the original result by tool-use ID, path, search, or offset. Raw results are kept in an active browser cache, with 20 entries by default. That cache is not persisted and disappears after reload or eviction; original sources may need to be read again.

## Keep useful working context

Consumed large results can be pruned from future requests. Small full results, including skill instructions and ability schemas, remain useful working context. A recent working set of distinct file-read windows helps prevent repeated reading of the same chunks. Successful file mutations invalidate affected read windows.

Repeated eligible tool requests can reuse earlier results, and redundant exchanges can be collapsed. Identical calls within a response are deduplicated while keeping assistant calls and tool results aligned.

## Summarize and trim older history

Older conversation history can be summarized while retaining a recent tail at a safe user-message boundary. For known loaded windows, summarization triggers earlier relative to the available budget and targets a shorter recent tail. Summary requests are checked against the summarization model's known loaded window; if older history cannot fit, deterministic request compaction remains available.

If the request still exceeds its budget, older exchanges can be dropped. For known local windows, trimming preserves the latest user turn and its tool exchanges. Request trimming does not delete the saved transcript, although stored tool results may already have been compacted.

When the retained current turn, system prompt, and tools still exceed the estimated allowance, AI Assistant stops before sending and suggests reducing input or enabled tools, starting a fresh chat, or increasing the server's loaded context. A single long tool workflow can therefore still reach the allocation limit.

## Recover from errors and stop loops

Recognized HTTP context-limit errors trigger one retry with stricter compaction and a smaller request budget. Local requests try the OpenAI-compatible API first and fall back to Ollama's native chat API on unsupported-route responses.

Repeated requests after compaction produce a context-size warning. Tool workflows also have configurable round limits and stop after consecutive failed rounds. These controls bound repeated work; they cannot guarantee that a model will reason correctly or detect silent server-side context truncation.

## Practical limits

Small context windows suit focused tasks when tools return concise, relevant information. Longer conversations and broad tool output require more aggressive selection, compaction, or a larger loaded allocation. The strategies above reduce context pressure, but neither the model's parameter count nor its advertised maximum guarantees that a particular workflow fits the allocation currently loaded.
