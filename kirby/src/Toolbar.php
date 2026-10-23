<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Kirby\Cms\App;

/**
 * Injects the toolbar into a full HTML document.
 */
final class Toolbar
{
    public static function inject(App $kirby, string $html, ?string $source, ?string $route): string
    {
        if (str_contains($html, 'id="doska-toolbar"')) {
            return $html;
        }
        if (!preg_match('/<body\b[^>]*>/i', $html, $open, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $openAt = $open[0][1] + strlen($open[0][0]);
        $closeAt = strripos($html, '</body>');
        if (false === $closeAt || $closeAt < $openAt) {
            return $html;
        }

        $dock = $kirby->option('doska.kirby.dock', 'bottom');
        if (!is_string($dock) || !in_array($dock, ['top', 'right', 'bottom', 'left'], true)) {
            $dock = 'bottom';
        }
        $config = [
            'engine' => 'kirby',
            'controller' => null,
            'source' => $source,
            'route' => $route,
            'root' => Paths::project($kirby),
            'dock' => $dock,
            'position' => ['bottom' => 16, 'left' => 16, 'top' => null, 'right' => null],
            'notes' => '/_doska/notes',
        ];
        $configAttr = htmlspecialchars(
            json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $script = "\n".'<script type="module" id="doska-toolbar-script" src="/_doska/toolbar.js"></script>'."\n";
        $element = "\n".'<doska-toolbar id="doska-toolbar" data-config="'.$configAttr.'"></doska-toolbar>'."\n";
        if (!str_contains($html, 'id="doska-toolbar-script"')) {
            $html = substr_replace($html, $script, $openAt, 0);
            $closeAt += strlen($script);
        }

        return substr_replace($html, $element, $closeAt, 0);
    }
}
