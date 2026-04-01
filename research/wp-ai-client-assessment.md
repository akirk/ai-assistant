# wp-ai-client Assessment

Evaluated `wp-ai-client` (v0.4.0, 2026-03-01) and `php-ai-client` (v1.3.1, being merged into WordPress 7.0 core) as of 2026-03-31.

## Decision: Not adopting wp-ai-client

Original reasons still valid:

1. **Server-side only** — all AI calls route through PHP REST endpoints (`/wp-ai/v1/generate`). No browser-direct option exists.
2. **No streaming** — still an open issue (#11). WordPress HTTP API doesn't support it; streaming scaffolding was removed from the PHP SDK.
3. **Local LLMs broken for remote WP** — Ollama provider exists (`Fueled/ai-provider-for-ollama`) but connects from the server, so remote WordPress can't reach `localhost:11434` on the user's machine.
4. **Tool calling is basic** — "Abilities API" provides function declarations but no iterative tool loop, no confirmation flow, no client-side execution.

## What's new in wp-ai-client since initial assessment

- PHP SDK partially merged into WordPress 7.0 core
- Provider plugins unbundled (separate plugins for Anthropic, Google, OpenAI)
- Image generation support
- Provider-agnostic model preferences with fallbacks
- `prompt_ai` capability system for access control
- PSR-14 event dispatching

## Why we use browser-direct calls instead

Our browser-side architecture (direct API calls, SSE streaming, full tool execution loop with confirmations, local LLM support) is fundamentally incompatible with wp-ai-client's server-routed design.

## Next steps

Re-evaluate if wp-ai-client adds browser-direct calls or streaming support in a future version. Until then, maintain the custom implementation.
