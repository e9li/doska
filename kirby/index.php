<?php

declare(strict_types=1);

use Doska\Kirby\Plugin;
use Kirby\Cms\App;

// The shared classes live next to this plugin in the Doska repository.
// A path install symlinks this directory, so __DIR__ still reaches them
// when Composer's generated paths do not.
spl_autoload_register(static function (string $class): void {
    $map = [
        'Doska\\Kirby\\' => __DIR__.'/src/',
        'Doska\\Notes\\' => __DIR__.'/../src/Notes/',
        'Doska\\Mcp\\' => __DIR__.'/../src/Mcp/',
    ];
    foreach ($map as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;

            return;
        }
    }
    if ('Doska\\Twig\\Comments' === $class) {
        $file = __DIR__.'/../src/Twig/Comments.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

App::plugin('doska/kirby', Plugin::definition());
