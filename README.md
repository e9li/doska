# Doska

Dev-only Symfony toolbar. Point at an element, write a note, and copy a prompt that names the controller, the Twig file and line, and the Symfony UX component.

The prompt uses the same XML shape as the [Tidewave toolbar](https://tidewave.ai/toolbar). The source walk follows [Annotai](https://github.com/andrielfn/annotai): HTML comments are a stack of frames, and `data-doska-loc` on the element is the line. There is no account and no desktop app. The script is served by the app.

The bundle does nothing unless `kernel.debug` is true. Register it for the `dev` and `test` environments only.

## Install

```bash
composer require --dev doska/symfony-bundle
```

```php
// config/bundles.php
Doska\DoskaBundle::class => ['dev' => true, 'test' => true],
```

```yaml
# config/routes/doska.yaml
when@dev:
    doska:
        resource: '@DoskaBundle/config/routes.php'
```

If the app firewall starts a session for every URL, exclude `/_doska` the same way as `/_profiler` and `/_wdt`. A session on these routes throws, because they are stateless.

Clear the cache once so Twig recompiles with the source map:

```bash
php bin/console cache:clear
```

Open a page on the local debug server. The toolbar sits on the bottom edge, above the Symfony web debug toolbar. Its styles are inside a shadow root, so the host application's CSS does not change it.

- Inspect points at an element and pins it in the prompt. Drag the grip to move the prompt. Copy prompt puts the Symfony-aware prompt on the clipboard. Add stores that same note for a connected agent. Delete on an open note removes it.
- Notes lists every stored note, newest first. Filter by status, open a note to read its thread, and click it to scroll to the element. A note from another page opens that page.
- Accessibility scans the page for missing names, labels, alt text, heading order, duplicate ids, and low contrast.
- The gear sets the toolbar edge: top, right, bottom, or left. That choice is saved in the browser.

## What the prompt contains

- The page URL, controller action, source file and line, and route.
- For each clicked element: tag, a short CSS selector, the Twig file and line (`data-doska-loc`), the frame stack (component, block, include, embedded controller), and the visible text.
- Stimulus controller, Live Component name and props, and the surrounding Turbo frame, when those attributes are on the element.

Hold Ctrl (Cmd on macOS) while hovering to show the component or template name instead of the tag.

## Configuration

```yaml
# config/packages/doska.yaml
when@dev:
    doska:
        allow_remote_access: false
        dock: bottom
        position:
            bottom: 52
            left: 16
```

`allow_remote_access` stays off. The toolbar and its routes answer only when `REMOTE_ADDR` is loopback. `dock` is `top`, `right`, `bottom`, or `left`. `position` is the pixel inset for that edge. The gear in the toolbar overrides `dock` for the current browser.

## How the source map works

On `.html.twig` and `.svg.twig` templates the bundle:

- adds `data-doska-loc="template:line"` to each literal opening tag
- wraps the template, each block, and each include or Twig component in HTML comments
- wraps the controller action, including embedded `render(controller(...))` fragments
- leaves the contents of `script`, `style`, `textarea`, and `title` unchanged, including includes that sit inside them. Text-only blocks, such as a page title, get no comments, because a browser shows those comments as title text

`@WebProfiler` and `@Debug` templates are left alone. Templates that are not HTML, such as `.json.twig`, are left alone.

A tag built only from `{{ }}` has no `data-doska-loc`. The prompt then uses the nearest frame.

## Connect your agent

Add stores the note in `var/doska/notes.json`. Copy prompt still puts the XML on the clipboard, for an agent that is not connected. A connected agent lists the note itself, opens the Twig file and line, and reports back when the change is done. Delete removes a note from that file.

The endpoint is `POST /_doska/mcp`. It speaks stateless JSON-RPC (`initialize`, `tools/list`, `tools/call`) and answers with `application/json`. It does not issue a session id and it does not stream events. The route exists only while `kernel.debug` is true, and only for loopback unless `allow_remote_access` is on.

The agent and the browser have to be on the machine that serves the app. Use the origin of that app in place of `https://drehscheibe.test`. Register the server, then start a new agent session so the tools load. `skills/fix-notes/SKILL.md` is the loop: list pending notes, acknowledge one, open its file and line, edit, then resolve with a one-line summary.

The tools are `doska_get_pending`, `doska_get_note`, `doska_acknowledge`, `doska_resolve`, `doska_dismiss`, and `doska_reply`. Acknowledge a note before resolving it. A resolved or dismissed note cannot change again.

`doska_watch_notes` is not available. Poll `doska_get_pending`. A blocking wait would hold a PHP worker.

### Claude Code

```bash
claude mcp add --transport http doska https://drehscheibe.test/_doska/mcp
```

### Codex

Add this to `~/.codex/config.toml`:

```toml
[mcp_servers.doska]
url = "https://drehscheibe.test/_doska/mcp"
```

### Grok

```bash
grok mcp add --transport http doska https://drehscheibe.test/_doska/mcp
```

That writes `~/.grok/config.toml`. Pass `--scope project` to write `.grok/config.toml` in the repo instead. The same block by hand:

```toml
[mcp_servers.doska]
url = "https://drehscheibe.test/_doska/mcp"
```

Use the HTTP transport. `grok mcp doctor doska` checks the handshake. `/mcps` lists the server in the session.

### Kimi Code

Kimi Code CLI reads `mcp.json`. A `url` with no `transport` is HTTP. Leave `transport` unset.

User-wide, `~/.kimi-code/mcp.json` (or `$KIMI_CODE_HOME/mcp.json`):

```json
{
  "mcpServers": {
    "doska": {
      "url": "https://drehscheibe.test/_doska/mcp"
    }
  }
}
```

For one repo, put the same file at `.kimi-code/mcp.json`. The project entry wins when both exist. A project server stays off until that workspace is trusted. In the TUI, `/mcp-config` edits the file and `/mcp` shows whether the server connected.
