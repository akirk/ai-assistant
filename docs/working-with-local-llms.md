# Working with local LLMs

AI Assistant makes provider requests directly from the browser. A WordPress site can therefore use a model server running on the user's device, including LM Studio or Ollama. The chat runtime and model interaction loop run in JavaScript, while tools that access WordPress data or perform server-side actions execute in the site's PHP context through authenticated requests. Some tools run in the browser instead: `get_page_html` inspects the current page, and `inspect_tool_result` reads cached tool output. WordPress abilities can also provide browser-side implementations.

AI Assistant gives local models the same enabled tool set as cloud models. To help constrained models use those tools effectively, the plugin keeps requests focused and manages how much information each step adds to the conversation.

## Local LLMs often have smaller context windows

A model's advertised maximum context is different from the context its server allocates. For example, a server might load a model capable of 262,144 tokens with only 8,192 tokens. Larger context windows require more RAM or GPU memory, and model servers may use smaller preconfigured defaults. AI Assistant uses the loaded context size when available to adapt how much information it sends to the model.

AI Assistant combines several strategies to make effective use of the available context:

- **Keep the system prompt focused:** Provide brief guidance and plugin-topic hints, then load detailed instructions as needed.
- **Discover capabilities on demand:** Let the model look up relevant tool details.
- **Retrieve focused information:** Encourage requests for relevant records, pages, or file excerpts.
- **Delegate focused investigations:** Let subagents gather information separately and return concise findings.
- **Compact large results:** Send concise previews and let the model inspect omitted details.
- **Reshape repeated tool exchanges:** Keep the first request with a smaller result and remove duplicate exchanges from the next model request.
- **Keep useful working context:** Retain relevant results and remove outdated information.
- **Summarize older history:** Preserve goals, decisions, and findings as the conversation grows.
- **Adapt to the loaded window:** Size requests to the available context and trim older exchanges when necessary.
- **Recover and bound repeated work:** Retry context errors with smaller requests and stop unproductive tool loops.

## Keep the system prompt focused

AI Assistant gives the model core guidance and brief context about the site and current page. Plugins can contribute a short list of topics they handle, helping the model recognize when their capabilities could answer the user's question. For example, a plugin might advertise “invoices, billing, payments” without including its full documentation or every action it supports.

The plugin directs the model to use these hints to discover relevant abilities and fetch their details. It introduces skills through short summaries and lets the model load full instructions when a task calls for them. This approach leaves more context for the conversation and useful tool results. Plugins can also extend the prompt, so concise contributions matter as users add more plugins.

## Discover capabilities on demand

AI Assistant exposes WordPress capabilities through the `ability` meta-tool. It avoids sending every registered ability schema with every request. Instead, the model can request a compact discovery index, fetch the schema for a relevant ability, and execute it with focused arguments.

Users can further reduce tool overhead by choosing a narrower tool set in the plugin settings.

## Retrieve only what the task needs

AI Assistant encourages the model to gather focused information. Tools offer file reads by offset or search window, and plugin abilities can offer filters and paged results. These options help the model find relevant records or inspect an exact file range before editing.

For short tasks, focused retrieval can keep requests within a small context window throughout the exchange, without invoking summarization or overflow recovery.

## Delegate focused investigations to subagents

AI Assistant lets the model hand a focused investigation to a read-only subagent through the `delegate` tool. Subagents can inspect a codebase, recall earlier conversations, or examine the current page. The plugin gives each subagent a task-specific prompt, selected shared context, a restricted tool set, and a limit on tool-call rounds.

The subagent returns a concise report with findings and sources. AI Assistant keeps its full intermediate tool results out of the main conversation, so exploratory work consumes less of the main context window. Delegation still adds model requests, and each subagent must fit its work into the available context.

## Compact large results and inspect details later

AI Assistant compacts oversized tool results before sending them to the model. It includes previews, information about the result's structure, and markers that identify omitted details. When the server reports its loaded context size, the plugin also adjusts result limits to that budget, helping prevent one result from dominating the request.

The plugin keeps original results in an active browser cache, with 20 entries by default. The model can use `inspect_tool_result` to retrieve a narrow slice by tool-use ID, path, search, or offset. Reloading the page clears the cache, and new entries can evict older ones; the model may then need to read the original source again.

## Keep useful working context

AI Assistant removes older large tool results from future requests after the model has consumed them, while retaining useful small results such as skill instructions and ability schemas. It also keeps a recent working set of distinct file-read windows to help the model avoid reading the same chunks repeatedly. After a successful file change, the plugin invalidates affected read windows.

## Reshape repeated tool exchanges

When a model asks for the same information again, appending another copy of a large result can make context pressure worse. AI Assistant takes a different approach for eligible repeated read-only calls: it rebuilds the next outgoing request as though the model had asked only once. It keeps the first tool call, replaces that call's result with a compacted version, and removes the duplicate call/result pairs. The model gets another chance to use a smaller answer without carrying the repeated exchanges in its context.

The plugin can reuse a successful earlier result instead of executing the tool again. It keeps already-small results unchanged when compaction would add overhead, and supplies inspection hints for compacted results so the model can request specific details.

AI Assistant applies this within the same user turn, preserves changed results, and resets reuse after a potentially mutating call. It changes the outgoing model context while retaining the saved transcript. It also deduplicates identical calls within a single response, keeping tool calls and results aligned.

## Summarize and trim older history

As the conversation grows, AI Assistant can summarize older history and retain recent exchanges at a safe user-message boundary. When it knows the loaded context size, the plugin triggers summarization earlier relative to the budget and retains a shorter recent tail. It also checks whether a summary request fits the summarization model's known loaded window. If the history cannot fit that request, the plugin falls back to compaction and trimming.

If a request still exceeds its budget, AI Assistant removes older exchanges. For known local windows, it preserves the latest user turn and its tool exchanges. Trimming changes the outgoing request without deleting the saved transcript, though the plugin may already have compacted stored tool results.

If the current turn, system prompt, and tools still exceed the estimated allowance, AI Assistant stops before sending. It suggests reducing input or enabled tools, starting a fresh chat, or increasing the server's loaded context. A single long tool workflow can still reach this limit.

## Recover from errors and stop loops

When a provider returns a recognized HTTP context-limit error, AI Assistant retries once with stricter compaction and a smaller request budget. For local requests, it tries the OpenAI-compatible API first and falls back to Ollama's native chat API when the server reports an unsupported route.

AI Assistant warns when the model keeps repeating eligible tool requests after compaction. It also enforces configurable tool-round limits and stops workflows after consecutive failed rounds. These controls limit repeated work, but they cannot ensure correct reasoning or detect every case where a server silently truncates context.

## Practical limits

AI Assistant aims to give a constrained model enough relevant information to complete the next step. Focused tasks with concise tool results suit this approach well. Longer conversations and broad investigations require more selection, compaction, or a larger loaded context.

The plugin estimates context usage rather than counting tokens with the model's tokenizer. These strategies reduce pressure on the available window; they do not make every workflow fit, regardless of the model's parameter count or advertised maximum context.
