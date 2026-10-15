# Changelog

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
