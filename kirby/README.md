# Doska for Kirby

Dev-only toolbar for a Kirby site. Point at an element, write a note, and copy a prompt that names the PHP snippet and, in content mode, the content file and block.

This package is `doska/kirby` 0.2.1. It lives in the [Doska](https://github.com/e9li/doska) repository, next to `doska/symfony-bundle`, which is also 0.2.1. Both packages set `version` in `composer.json`. The repository version is the git tag. The Kirby field is what a path install and the Kirby Panel read. The install notes, the dev and content modes, and the agent setup are in the repository README.

The plugin does nothing unless Kirby's `debug` option is true. Install it with a path repository and `"symlink": true`. The plugin then reads `assets/toolbar.js` and the shared note classes from the repository root. Those files sit next to the `kirby` directory, so a copied plugin folder cannot see them.

A Kirby page has one Dev / Content toggle on the toolbar. The button shows the current mode. Dev leads with the PHP snippet. Content leads with the content file, field, block id, and block type. The prompt grows upward when the bar is on the bottom, left, or right, so the bar stays on the page. Toolbar buttons have tooltips.
