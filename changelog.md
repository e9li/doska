# Changelog

## 2026-10-23

- 0.2.0. Kirby plugin `doska/kirby`, in this repository next to the Symfony bundle. It annotates PHP snippets with the same HTML comments, injects the toolbar, and serves notes and MCP under `/_doska`. The Symfony bundle is unchanged. The release is git tag `v0.2.0`. Both packages record `0.2.0` in `composer.json`.
- A Kirby page has one Dev / Content toggle on the toolbar. The button shows the current mode. Dev leads the prompt with the PHP snippet. Content leads with the content file, field, block id, and type. The default is Dev. Both addresses stay on the note. Symfony prompts have no mode.
- The prompt grows upward when the bar is on the bottom, left, or right, and downward when the bar is on top. The footer and the bar stay on the page. Extra text scrolls inside the prompt.
- Toolbar buttons have tooltips: Inspect, Accessibility, Notes, Position, and on Kirby the Dev or Content toggle.
- A Kirby browser request does not read or write the pages cache. The cache warmer is left alone. Notes are stored in `site/cache/doska/notes.json`.

## 2026-10-16

- Notes panel lists every stored note, newest first, and filters by pending, acknowledged, resolved, and dismissed. A note on this page scrolls to its element. A note on another page opens that page. The clipboard icon opens the panel.
- The prompt button is Add. It stores the note. Delete on an open note removes it after a confirmation. Copy prompt is unchanged.

## 2026-10-15

- 0.1.0. Dev-only Symfony toolbar. Point at an element, write a note, and copy a prompt with the controller, Twig file and line, and UX component.
- Source map on `.html.twig` and `.svg.twig`: `data-doska-loc` on literal tags, plus comments for the template, blocks, includes, Twig components, and the controller action. `script`, `style`, `textarea`, and `title` are left unchanged.
- The prompt uses the Tidewave XML shape. Stimulus controllers, Live Component name and props, and the surrounding Turbo frame are included when they are on the element.
- Shadow-DOM icon bar, so the host application's CSS does not change it. The prompt, accessibility list, and position panel stay separate from the bar.
- Inspect pins the element. The grip drags the prompt. Escape closes the open tool.
- Accessibility scan for missing names, labels, and alt text, heading order, duplicate ids, positive tabindex, untitled frames, a missing `lang`, and low contrast. Copy findings includes the Twig file and line.
- The gear docks the bar to the top, right, bottom, or left. The choice is saved in the browser. `dock` in the bundle config is the default, and `position` is the pixel inset.
- The toolbar is injected only when `kernel.debug` is true. Routes stay stateless and answer on loopback unless `allow_remote_access` is turned on.
- Renamed the package from promptbar to doska. The bundle namespace is `Doska\`, the config key is `doska`, and the routes live under `/_doska`.
- Send stores the current note for a connected agent. `POST /_doska/mcp` exposes `doska_get_pending`, `doska_get_note`, `doska_acknowledge`, `doska_resolve`, `doska_dismiss`, and `doska_reply`. Notes live in `var/doska/notes.json`. Copy prompt is unchanged.
- Connect instructions for Claude Code, Codex, Grok (`grok mcp add --transport http`), and Kimi Code (`url` in `~/.kimi-code/mcp.json`, HTTP with no `transport`). The same steps are in `skills/fix-notes/SKILL.md`.
