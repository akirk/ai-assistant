# AI Assistant vs Haydi

This document compares the current `ai-assistant` plugin with the sibling
`../haydi` project. Both are WordPress admin AI assistants, but they optimize
for different deployment assumptions and risk profiles.

## Executive Summary

`ai-assistant` is a Playground- and local-LLM-oriented WordPress workbench. It
uses a hybrid provider path: Connector-backed Anthropic/OpenAI calls go through
a transparent PHP proxy so cloud API keys stay server-side, while pre-Connector
WordPress installs and local endpoints such as Ollama and LM Studio continue to
use the browser-direct path. It exposes a broad tool surface, integrates with
WordPress Abilities, and tracks AI file changes with a git-compatible history.

`Haydi` is a production-admin-oriented site management assistant. It routes AI
calls through WordPress 7.0 Connectors on the server, keeps API credentials out
of the browser, uses strict filesystem allowlists, requires approval for
mutating actions, creates backups, runs post-change health checks, and exposes
REST/MCP access for external tools.

In short:

- Choose `ai-assistant` when local models, WordPress Playground, hybrid
  browser/proxy provider support, Abilities, skills, and portable git-style
  change history matter most.
- Choose `Haydi` when production safety, server-side provider management,
  auditability, rollback, and remote MCP/REST access matter most.

## High-Level Comparison

| Area | AI Assistant | Haydi |
| --- | --- | --- |
| Primary target | WordPress Playground, local models, flexible admin/frontend assistant | Production WP admin automation through WordPress Connectors |
| Availability model | Ambient latch follows logged-in users across wp-admin and the frontend through the WordPress admin/master bar | Dedicated Haydi admin screen plus REST/MCP access |
| WordPress requirement | WordPress 6.0+; Connector proxy is used only when Connectors are available | WordPress 7.0+ because Haydi depends on WordPress Connectors |
| PHP requirement | PHP 7.4+ | PHP 8.0+ in `readme.txt` |
| AI provider path | Connector-backed Anthropic/OpenAI use a transparent PHP proxy; pre-Connector cloud calls and local LLM endpoints remain browser-direct | PHP calls WordPress AI Client / Connectors |
| Response streaming | True provider/token streaming for supported providers; Connector cloud calls stream through the PHP proxy when cURL streaming is available | Server-sent progress events, not provider token streaming |
| API key exposure | Connector cloud keys stay server-side; legacy/pre-Connector browser-direct keys remain client-side | Credentials stay in WordPress Connectors/server context |
| Agent loop | Mostly browser-side JavaScript | Server-side PHP loop |
| Tool execution | Mix of client tools, AJAX tools, and direct no-bootstrap file endpoint | Server-side tool dispatcher with proposal/approval flow |
| Playground role | First-class runtime/development target; useful for local/provider/browser workflows and recovery | Optional pre-apply safety sandbox for proposed PHP writes |
| Current page inspection | Can inspect live browser DOM with `get_page_html` | No equivalent live DOM tool; mainly works from its own admin screen plus files/data |
| Image picking | Client-side `pick_image` can search Openverse, accept dropped/uploaded images, and upload to Media Library | No equivalent picker found |
| Preflight testing | Can continue repairing through direct file endpoint after a break | Optional client-side WordPress Playground preflight can test proposed PHP writes before applying |
| File scope | `wp-content` relative paths | Explicit allowed roots: plugins, themes, optional scratch dir |
| Change recovery | Git-compatible change tracking, patch export/import, direct recovery endpoint | Backups, health checks, auto-revert for many mutations |
| Permission model | Custom full/read-only/chat-only capabilities plus configurable tool allowlist; Playground enables all tools | Primarily `manage_options` |
| Conversation storage | Custom post type `ai_conversation` | Per-user `wp_usermeta` chat store |
| Operations trace | Conversation transcript/export can include tool calls and results, but is per-chat and transcript-shaped | Dedicated audit log records actions/proposals as operational events |
| External automation | No comparable MCP/REST remote-agent surface found | First-class REST and MCP endpoints with bearer-token access |
| Extensibility | WordPress Abilities, skills, hooks, WpApp integration | Drop-in extensions, REST API, MCP endpoint |
| Plugin capability awareness | `docs/plugin-integration.md` defines ability domains, post-ability instructions, welcome tips, and browser callbacks so plugins can advertise what they can do | No equivalent semantic domain-routing contract found; extensions expose tools, but arbitrary plugin capabilities are less discoverable |
| Development setup | WordPress Playground | `wp-env`, Jest, Playwright, PHPUnit, PHPCS |

## AI Assistant Analysis

### Strengths

- The hybrid provider path keeps the original local-LLM advantage while reducing
  cloud-key exposure where WordPress Connectors exist. Connector-backed
  Anthropic/OpenAI requests are proxied through PHP; local LLMs and
  pre-Connector installs still use the browser-direct path.
- Local LLM use remains practical even when WordPress is hosted somewhere else.
  A remote PHP server cannot reach `localhost:11434` on the user's machine; the
  user's browser can.
- True response streaming remains a core advantage. Browser-direct providers
  stream directly to JavaScript, and Connector-backed cloud calls can stream
  through the transparent PHP proxy when the host supports cURL streaming.
- Strong Playground fit. The plugin is designed around browser-hosted WordPress,
  portable history, direct file tools, and recovery workflows that are useful
  when experimenting.
- Ambient availability is a product advantage. For logged-in users with access,
  the assistant can follow them around WordPress through the admin/master bar:
  wp-admin screens, standalone admin surfaces, and the frontend when enabled.
  That makes it feel like a site-wide assistant that is available at the point
  of need, not a separate destination the user has to remember to open.
- Broad tool surface. It includes file tools, PHP execution, database queries,
  WordPress REST API access, page HTML inspection, image picking, navigation,
  conversation summarization, skills, and WordPress Abilities.
- It is aware of the page where the assistant is mounted. The browser-side
  `get_page_html` tool can query the live DOM with CSS selectors, so the model
  can inspect what the user is currently seeing in wp-admin or on the frontend.
- It has a client-side image picker powered by Openverse. The `pick_image` tool
  opens a browser dialog, searches `api.openverse.org`, limits search to public
  domain/CC0-style results, lets the user pick or drop/upload an image, attempts
  to upload the selected image into the WordPress Media Library, and returns
  attachment ID, local URL, thumbnail, attribution, license, and source metadata
  when available.
- WordPress Abilities integration is a major extensibility advantage, and the
  plugin integration guide makes it more than generic ability discovery.
  Plugins can use `ai_assistant_ability_domains` to map natural user terms such
  as invoices, reservations, members, or galleries to their ability namespace,
  so the assistant reaches for the right plugin capability before generic tools
  like `run_php` or `db_query`. Ability annotations, post-execution
  instructions, contextual welcome tips, and browser callbacks further help the
  assistant use plugin capabilities intentionally.
- Skills provide lightweight, on-demand domain guidance for tasks such as
  Gutenberg blocks, WpApp-style plugins, and plugin creation.
- Git-compatible change tracking is excellent for experimentation. Users can
  inspect diffs, export patches, revert/reapply changes, and download a
  Playground zip with meaningful git history.
- Conversation logs also preserve a useful transcript of tool activity. The
  saved `ai_conversation` messages include the chat history, and exports can
  include tool calls/results for a fuller technical transcript. This can show
  which tools were called and what happened in that conversation, including
  declined tool actions being fed back as skipped tool results.
- The direct file tool endpoint can keep working when WordPress bootstrap is
  broken, which is valuable after a bad PHP edit.
- Because file repair can continue through the browser/direct file endpoint,
  the assistant may get a few more tool calls to fix its own mistake after a
  bad intermediate state. This is especially useful for multi-file changes
  where one file may temporarily reference code that is introduced in a later
  edit.
- Emergency plugin disabling gives the assistant another recovery path when a
  plugin edit or activation breaks WordPress. Instead of relying only on a
  pre-write denial or immediate rollback, it can guard the problematic plugin
  and continue toward a repaired state.
- The custom role model supports more nuanced access than a single admin-only
  capability.
- Tool permissions are configurable outside Playground. The default non-Playground
  allowlist enables read/context tools and guarded semantic actions, but leaves
  high-risk tools such as file writes, file edits, file deletion, plugin
  installation, and raw PHP execution disabled until explicitly enabled. In
  Playground, all tools are enabled automatically and the settings UI is
  read-only because the environment is meant for experimentation.
- Role capabilities and tool permissions stack. Full-access users can use all
  enabled tools; read-only users are limited to non-dangerous enabled tools plus
  abilities annotated as read-only; chat-only users cannot execute tools.

### Weaknesses

- Legacy browser-direct cloud provider calls expose more sensitive runtime
  material to the client than a server-side Connector model. Connector-backed
  Anthropic/OpenAI calls avoid that by keeping API keys in PHP, but
  pre-Connector WordPress installs still rely on the older client-side key path.
- Safety depends heavily on the client loop and confirmation UX. The YOLO mode
  is useful for fast iteration but raises the blast radius if used carelessly.
- File scope is broader: tools operate within `wp-content`, while Haydi limits
  access to plugins/themes and an optional scratch directory.
- It lacks Haydi's systematic post-mutation health-check rollback model. It has
  recovery and git history, but not the same immediate "apply, probe, revert"
  safety loop for every mutation.
- It has more moving parts in JavaScript. Provider handling, streaming, tools,
  UI, conversations, file direct calls, and approval state are spread across
  several browser modules.
- Production distribution is harder to reason about than Haydi because the
  plugin can combine direct provider calls, local endpoints, optional dangerous
  tools, and broad filesystem access. That said, outside Playground the dangerous
  tools are not simply always-on: they can be relaxed or restricted through Tool
  Permissions and role capabilities.

## Haydi Analysis

### Strengths

- Server-side AI calls through WordPress Connectors keep API keys out of the
  browser and align with a production WordPress architecture. `ai-assistant`
  now does this for Connector-backed Anthropic/OpenAI transport, but Haydi goes
  further by owning the whole model/tool loop server-side.
- The agent loop runs in PHP, so read-tool results can flow back to the model
  without repeated browser orchestration.
- Haydi does support a streaming chat endpoint, but it streams coarse progress
  events from PHP, such as status updates, tool start/done events, assistant text
  after a model call completes, and the final response. It does not stream raw
  provider tokens from the AI connector to the browser.
- Strong guardrails. Filesystem operations are restricted to explicit allowed
  roots, file extensions are limited, dotfiles are skipped, and file sizes are
  capped.
- Mutating actions are proposals. The model must call a tool to create an
  approval panel, and the user sees parameters or diffs before anything changes.
- Backups and post-change health checks provide a practical production safety
  net. Many file operations can auto-restore if the site is confirmed broken.
- Haydi has an optional WordPress Playground preflight path for proposed PHP
  file writes. Before applying to the live filesystem, the browser can spin up a
  Playground sandbox, mirror the relevant plugin/theme files, apply the proposed
  write, and verify the result. That is a distinct safety mechanism from the
  post-write health check.
- Audit logging gives administrators a dedicated trace of actions and proposals.
  This is more structured for oversight than a chat transcript because it is an
  operations log rather than provider-message history.
- REST and MCP support make Haydi useful outside the browser. External tools
  can connect with bearer tokens and discover/use site tools remotely.
- Haydi has useful context-window ergonomics: token/session usage counters,
  model-limit based context-pressure indicators, manual chat compaction, and
  automatic compaction before continuing when context pressure gets high.
- Jetpack integration is deeper than a generic site-info block. When connected,
  Haydi can enrich the prompt with stats, top posts, referrers, search terms,
  active modules, plan data, social connections, speed scores, and security/sync
  state.
- Its file-linking workflow is production-friendly. The assistant is instructed
  to link plugin/theme files to WordPress core editors when available, and to
  fall back to `wpc-view:` inline read-only viewers when file editing is disabled
  or inappropriate.
- The extension model keeps the core plugin safer. File writes, SQL execution,
  and PHP execution can live in separately installed extension files.
- SSRF-protected `fetch_url` is more robust than a naive fetch tool: it validates
  schemes, resolves A/AAAA records, blocks private/internal IPs, disables
  redirects, and pins cURL resolution.
- The development toolchain is more complete for production release: wp-env,
  Playwright, Jest, PHPUnit, PHPCS, ESLint, and distribution scripts.

### Weaknesses

- It requires WordPress 7.0 because it depends on WordPress Connectors, which
  narrows the install base. By contrast, `ai-assistant` can still run on
  pre-Connector WordPress installs using its legacy browser-direct provider
  path.
- It does not solve the remote-host/local-LLM problem the same way. If the AI
  provider must be reached from PHP, local-only endpoints are harder unless a
  Connector can reach them from the server environment.
- The permission model is simpler but less flexible: it is primarily
  administrator-focused through `manage_options`.
- The extension split improves safety and distribution, but adds operational
  friction. Users must install extra PHP files for file writes, SQL, and PHP
  execution.
- Backups are practical, but not as developer-friendly as git-compatible change
  history. Inspecting a sequence of AI changes, exporting a patch, or merging
  changes into a local workflow is stronger in `ai-assistant`.
- Immediate health checks can reject or revert an intermediate state that is
  temporarily broken but expected during a coordinated multi-file change. That
  is safer for production, but less forgiving when codependent files need to
  land together before the site is healthy.
- If the server is already broken, Haydi's normal WordPress-backed approval and
  tool flow is more likely to be unavailable. `ai-assistant` has an advantage
  here because its direct file endpoint is designed to keep working without a
  full WordPress bootstrap.
- Because the tool loop is server-side, UI-specific tools such as inspecting the
  current page DOM, image picking, or tight browser context are less central.
- Haydi is less ambient in the WordPress UI. It is powerful from its own admin
  page and from external MCP/REST clients, but it does not follow the user
  across wp-admin and the frontend through a persistent admin-bar latch.
- Haydi does not have an equivalent to `get_page_html`. It can fetch public
  URLs, read files, list WordPress data, and show file contents inline, but it
  cannot inspect the authenticated live DOM of the page the user is looking at.
- Haydi does not appear to have an Openverse/image-picker workflow. It can
  attach text files to chat, but there is no equivalent tool that asks the user
  to select an image and returns a Media Library attachment ID.
- It does not have the same WordPress Abilities, skills, or plugin integration
  layer that `ai-assistant` uses for plugin-specific capability awareness and
  task guidance. Haydi extensions can expose tools, but there is no comparable
  ability-domain contract for a plugin to say "these user-facing concepts belong
  to my capabilities."

## Design Tradeoffs

### Hybrid Proxy vs Server-Owned AI Loop

`ai-assistant` now uses a hybrid provider model. When WordPress Connectors are
available and a supported cloud provider is configured, JavaScript sends the
provider-shaped request body to a constrained WordPress AJAX proxy. PHP resolves
the Connector endpoint and API key, forwards the original JSON body unchanged,
and streams the provider response back to the browser. The JavaScript client
still owns request construction, provider-specific streaming parsers, the
AI/tool loop, approval state, summaries, and title generation.

When Connectors are not available, such as on pre-Connector WordPress installs,
`ai-assistant` keeps the existing browser-direct cloud provider path. That means
older WordPress versions still work with the same client-side CORS/direct API
requests and localStorage keys as before. Local LLM endpoints also remain
browser-direct, which preserves the remote-WordPress-to-user-localhost use case.

This hybrid path keeps local models and Playground first-class while improving
the production story for Connector-backed cloud providers. It still leaves more
provider orchestration in JavaScript than a fully server-side assistant, but it
no longer requires exposing Connector API keys to the browser for supported
cloud providers.

`Haydi` chooses server-side Connector calls. That is cleaner for production,
centralizes provider configuration, and avoids exposing keys to JavaScript, but
it depends on WordPress 7.0 Connectors and is less naturally suited to local
LLM endpoints running on the user's machine. Haydi's server-sent events improve
perceived progress, but they are not equivalent to token-by-token model
streaming because each `generate_result()` call still completes server-side
before assistant text is emitted.

### Playground Runtime vs Playground Preflight

Both projects mention WordPress Playground, but they use it for different
reasons.

`ai-assistant` treats Playground as a first-class runtime and development
environment. Its normal architecture already assumes the browser is important:
the browser calls providers, streams model output, can call local LLM endpoints,
can inspect the current page DOM, and can use direct recovery tools.

`Haydi` uses client-side Playground primarily as a preflight safety check. When
enabled for PHP file writes, the browser can prepare a temporary Playground
sandbox, mirror the relevant plugin/theme files, apply the proposed write there,
and verify it before the live site is changed. That is not the same as moving
Haydi's assistant loop into the browser; the main agent loop, approval flow,
file guard, health checks, backups, audit log, REST API, and MCP endpoint remain
server-side WordPress features.

Haydi is designed to be used from outside the browser, but that conclusion comes
from its MCP and REST API, not from the Playground preflight. A bearer token can
let external MCP/REST clients use Haydi tools without the browser. In that mode,
the token is effectively the approval gate, and operations still go through
Haydi's guard, backup, health-check, and audit machinery.

### Ambient Latch vs Dedicated Console

`ai-assistant` is designed to be present while the user moves around the site.
Its latch/panel can appear below the WordPress admin/master bar in wp-admin and
on the frontend for logged-in users with access. Combined with `get_page_html`,
that makes the assistant contextual: the user can ask about the page they are
currently viewing, whether that is an admin screen, a plugin UI, or a frontend
view.

`Haydi` is closer to a dedicated operations console. The user opens Haydi to
manage the site, or an external client connects over MCP/REST. That is stronger
for deliberate admin automation and remote-agent access, but it is not the same
always-available companion model.

### Git History vs Backups and Health Checks

`ai-assistant` focuses on inspectable, portable change history. This is ideal
for experimentation, patch export/import, and local developer workflows.
It also gives the assistant a better chance to recover from bad intermediate
states because file repair can continue even when WordPress-backed tools are
failing.

`Haydi` focuses on immediate operational recovery. It creates backups before
mutations and probes WordPress afterward, reverting when possible. This is a
better production safety pattern, but not as rich for reviewing a project-level
change history. It can also be too eager for coordinated edits where the site is
only healthy after several files are updated together.

### Chat Transcript vs Audit Log vs Git History

`ai-assistant` has two useful records:

- The conversation transcript, stored as full message history and exportable as
  Markdown, HTML, or JSON. With "Include tool calls" enabled, it can show tool
  calls and tool results, so it can answer many "what happened in this chat?"
  questions.
- The git-compatible change history, which answers "what exactly changed in the
  files?" and supports diff/revert/reapply/patch workflows.

Those records are valuable, but they are not the same as Haydi's audit log.
The transcript is per conversation and shaped like provider messages. It is
excellent context for the user and for continuing work, but it is not a global,
normalized operations table with action/path/reason/status/user/time columns.
The git history is excellent for code review, but it does not record every read,
proposal, declined action, settings change, or token event.

Haydi's audit log fills that operations-led role. It is clearer for admin
oversight and security review, while `ai-assistant`'s transcript plus git
history is stronger for reconstructing the conversation and reviewing code
changes.

### Broad Tools vs Narrow Guardrails

`ai-assistant` gives the assistant a wider set of ways to help: Abilities,
skills, REST, page HTML, image picking, PHP, database queries, file operations,
and plugin creation workflows.

`Haydi` narrows access more aggressively. It treats mutation as a proposal,
separates dangerous capabilities into extensions, and limits filesystem reach.
This lowers risk, but makes some advanced workflows less seamless. `ai-assistant`
is not unrestricted by default outside Playground; it uses an allowlist and role
capabilities, while preserving the option to relax those limits for trusted
workflows.

## What AI Assistant Could Borrow From Haydi

- Consider an optional production-mode health check for high-risk mutations,
  with batch/change-set support. It should preserve `ai-assistant`'s current
  repair advantage: codependent file edits may need to land together, and the
  direct file endpoint should still be able to fix a broken intermediate state
  instead of immediately reverting every failing single-file edit.
- Add an audit log that records tool proposals, approvals, rejections, and
  completed actions.
- Tighten filesystem controls with explicit allowlists, extension caps, file
  size caps, and hidden-file rejection for production mode.
- Add an SSRF-hardened `fetch_url` tool if external URL fetching becomes a core
  capability.
- Add REST/MCP token access so external coding agents can use the assistant's
  tools without the browser.
- Expand the Connector proxy beyond Anthropic/OpenAI if additional Connector
  providers become important, while keeping local/browser-direct providers for
  the remote-WordPress-to-user-localhost case.

## What Haydi Could Borrow From AI Assistant

- Add git-compatible change history or patch export for users who want to move
  AI-made changes into a developer workflow.
- Add a no-bootstrap emergency file endpoint or equivalent recovery mechanism
  for cases where WordPress cannot load after a bad edit.
- Add an explicit batch/change-set mode so codependent file edits can be applied
  together, with the health check running after the whole set rather than after
  each individual file.
- Add first-class WordPress Abilities support and an ability-domain mechanism so
  other plugins can advertise their semantic capabilities without custom Haydi
  extensions or blind ability/tool searching.
- Add a skills system for loading task-specific guidance on demand.
- Add current-page DOM/context tools for workflows where the user's visible
  admin screen matters.
- Add an ambient admin-bar/frontend latch so the assistant is available wherever
  the logged-in user is already working.
- Add image-picking/media-library workflows for plugins that need local
  attachment IDs or selected images.
- Add more granular capabilities beyond `manage_options`.
- Add a no-bootstrap repair path that complements, rather than replaces, its
  Playground preflight and post-write health checks.

## Decision Matrix

| Need | Better Fit |
| --- | --- |
| Local Ollama or LM Studio from a remote WordPress site | AI Assistant |
| WordPress Playground experimentation | AI Assistant |
| Production admin safety and rollback | Haydi |
| Pre-apply PHP write testing in a sandbox | Haydi |
| API keys managed server-side | Haydi; AI Assistant for supported Connector cloud providers |
| Plugin-specific semantic actions | AI Assistant |
| Plugin capability awareness without blind ability/tool search | AI Assistant |
| External MCP clients such as Claude Code or other agents | Haydi |
| Git-style diffs, patch export, portable history | AI Assistant |
| Strict path/file-type allowlists and extension-gated mutation | Haydi |
| Granular in-plugin tool allowlist and role tiers | AI Assistant |
| Simple admin-only production permission gate | Haydi |
| Context pressure tracking and chat compaction UX | Haydi |
| Jetpack-powered site intelligence | Haydi |
| Always-available assistant in wp-admin and frontend | AI Assistant |
| Current-page UI inspection / live page HTML | AI Assistant |
| Openverse image search and Media Library image selection | AI Assistant |
| WordPress.org-friendly core with optional dangerous extensions | Haydi |

## Bottom Line

The two projects are not duplicates. They represent two different product
centers of gravity.

`ai-assistant` is the better base for a personal, experimental, Playground, or
local-model WordPress agent. Its strongest ideas are hybrid browser/proxy
provider reach, an ambient admin-bar/frontend latch, Abilities integration with
plugin-domain awareness, skills, rich tool coverage, and git-compatible change
history.

`Haydi` is the better base for a production-safe WordPress admin agent. Its
strongest ideas are server-side Connectors, restricted file access, proposal
approval, backups, health checks, audit logs, and MCP/REST access.

The ideal long-term assistant would combine both: Haydi's production guardrails,
server-owned loop options, and remote access with AI Assistant's local-model
flexibility, plugin-aware Abilities layer, ambient latch, browser context,
transparent Connector proxy, and git-grade change tracking.
