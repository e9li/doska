<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Doska\\Kirby\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__).'/src/'.$relative.'.php';
    if (is_file($file)) {
        require $file;
    }
});
