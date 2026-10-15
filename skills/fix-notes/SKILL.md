---
name: fix-notes
description: Work through notes a person sent from the Doska toolbar on a running Symfony app. List them with the doska MCP tools, open the Twig file and line on each pinned element, edit, and resolve. Use when the user says "check Doska", "fix the notes", "pending notes", or wants the agent to pick up a note from the page.
---

# Fix Doska notes

Doska is a dev-only toolbar on the Symfony app. Send stores a note. The MCP tools are how an agent reads it. Copy prompt is a separate clipboard path and is not this loop.

The tools are `doska_get_pending`, `doska_get_note`, `doska_acknowledge`, `doska_resolve`, `doska_dismiss`, and `doska_reply`.

If those tools are not in this session, the app has to be running and the endpoint has to be registered on the machine that serves it. Use the app's own dev origin. There is no separate Doska server. Tools show up after a new session. Say so and stop.

Claude Code:

```bash
claude mcp add --transport http doska https://drehscheibe.test/_doska/mcp
```

Codex, in `~/.codex/config.toml`:

```toml
[mcp_servers.doska]
url = "https://drehscheibe.test/_doska/mcp"
```

Grok:

```bash
grok mcp add --transport http doska https://drehscheibe.test/_doska/mcp
```

Kimi Code, in `~/.kimi-code/mcp.json`. A `url` with no `transport` is HTTP:

```json
{
  "mcpServers": {
    "doska": {
      "url": "https://drehscheibe.test/_doska/mcp"
    }
  }
}
```

The README section "Connect your agent" has the project-scoped files as well (`.grok/config.toml`, `.kimi-code/mcp.json`).

## Loop

1. Call `doska_get_pending`. It returns pending and acknowledged notes, oldest first. Resolved and dismissed notes are omitted.
2. If the list is empty, say so and stop. Do not poll forever.
3. Call `doska_acknowledge` with the note id before editing.
4. Open each element's `file` and `line`. `location` is the same `file:line` from `data-doska-loc`. The path is relative to the project when the frame scope is `app`. Do not search the project for the element when `file` and `line` are present.
5. Make the smallest change that matches the note. Follow the surrounding code.
6. Call `doska_resolve` with a one-line `summary` of what changed.
7. Take the next note.

A pending note cannot be resolved. A resolved or dismissed note cannot change again.

## When not to resolve

- Call `doska_reply` with `message` when the note is a question. Leave it pending or acknowledged.
- Call `doska_dismiss` with `reason` when it should not be implemented. Give a concrete reason.
- Call `doska_get_note` when the reply thread matters.

`doska_watch_notes` does not exist. Poll `doska_get_pending` only when the user asks to check again.
