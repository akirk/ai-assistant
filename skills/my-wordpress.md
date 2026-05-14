---
title: Using My WordPress
description: Use when the user is on my.wordpress.net, mentions My WordPress, or asks what a personal WordPress can be used for; inspect installed plugins before recommending uses
category: context
---

# Using My WordPress And Personal WordPress

Use this when the user mentions My WordPress, my.wordpress.net, browser-based WordPress, or asks what they can use "this WordPress" for in a personal context.

Also use it when the current WordPress is not on my.wordpress.net but the user treats it as a personal workspace rather than a public website. This includes hosted, local, NAS, family, intranet, or migrated WordPress installs used mainly for personal data, personal publishing, experimentation, or small personal apps.

## Short Definition

My WordPress is a full WordPress running persistently in the user's browser at https://my.wordpress.net/. It needs no server, hosting plan, domain, account, or sign-up. Data stays on the user's device in browser storage.

More generally, a personal WordPress can be a personal workspace: a place to store, organize, customize, and work with the user's own data, whether it runs in the browser or on hosted/local infrastructure.

## Start With What Is Installed

When the user asks what this WordPress can be used for, do not answer from the generic list alone if tools are available.

First inspect the current environment:

- Use the site URL and current page from the system prompt to notice whether this is my.wordpress.net, local, hosted, intranet, or another personal setup.
- Use `environment_info` to see active plugin titles and descriptions; include inactive plugins or use `get_plugins` when looking for unused capabilities or gaps.
- Use `ability` with action `list` when available to discover app-specific actions exposed by installed plugins.

Then tailor the answer:

- "You can already do..." for workflows supported by installed plugins or active abilities.
- "A good next step would be..." for gaps that need a plugin, custom app, configuration, or migration to hosted WordPress.
- "This may need hosted WordPress..." for public URLs, federation, inbound webhooks, shared remote access, or other features that browser-only My WordPress cannot receive from the public web.

Infer cautiously from plugin names and descriptions. If a plugin probably supports a use case but details are unclear, say that and offer to inspect its settings or relevant admin screen.

## Best Use Cases

Suggest practical, personal uses:

- Private journal, diary, or family log.
- Personal CRM for remembering people, notes, and follow-ups.
- RSS/feed reader with the Friends plugin.
- Private knowledge base or family wiki.
- Recipe collection, meal planning, and cooking notes.
- Article clipping and reading workflows.
- Lightweight personal apps built as WordPress plugins.
- Safe experimentation with plugins, themes, blocks, and AI-made changes.

## How To Frame It

Treat this WordPress as a personal workspace unless the user is clearly building a public site.

Good answers should help the user choose something useful to build, install, or organize now. Prefer concrete next steps, for example:

- "I can set up a private reading app."
- "I can create a family wiki."
- "I can build a small plugin for tracking this."
- "I can help move this from browser-only My WordPress to hosted WordPress."

If the user already moved from my.wordpress.net to hosted or local WordPress, do not frame that as leaving the idea behind. The same personal WordPress workflows still apply; the hosted/local install just changes the technical limits.

## Limits To Mention When Relevant

For browser-only My WordPress:

- Data is local to this browser/device.
- The user should download backups regularly.
- It can fetch data from the web, but the web cannot reach into it.
- Public websites, federation, inbound webhooks, shared multi-device access, and being followed by others require hosted WordPress or another reachable server.
- Storage starts around 100 MB, so large media-heavy uses need care.

For hosted or otherwise reachable personal WordPress:

- It can support public URLs, federation, inbound webhooks, remote access, and multi-device use if configured.
- Privacy then depends on hosting, access control, plugin behavior, and backups, not just browser storage.
- Recommend keeping private data behind login, using trusted plugins, and maintaining backups.

## Building Guidance

When creating things here, prefer small, self-contained plugins that can work in WordPress Playground and move to hosted WordPress later.

Use the `-mywp` suffix for new plugin slugs when creating custom plugins for this environment.
