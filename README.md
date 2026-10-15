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

- Inspect points at an element and pins it in the prompt. Drag the grip to move the prompt. Copy prompt puts the Symfony-aware prompt on the clipboard.
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

## Later

An MCP endpoint that lets an agent pull notes, the way Annotai does, is not part of this version. Copy prompt is enough for any agent that can read the clipboard.
