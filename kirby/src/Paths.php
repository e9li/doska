<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Kirby\Cms\App;

/**
 * Project root and a path the toolbar can show.
 */
final class Paths
{
    public static function project(App $kirby): string
    {
        $index = self::canonical((string) $kirby->root('index'));
        $site = self::canonical((string) $kirby->root('site'));
        // Speedpepper stores site next to public, and the configured root
        // still contains "public/../site". Compare the resolved directories.
        if (str_starts_with($site, $index.'/')) {
            return $index;
        }

        return dirname($index);
    }

    public static function relative(string $path, string $project): string
    {
        $path = self::canonical($path);
        $root = rtrim(self::canonical($project), '/');
        if ('' !== $root && str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    public static function scope(?string $path, App $kirby): string
    {
        if (null === $path || '' === $path) {
            return 'app';
        }
        $path = self::canonical($path);
        $kirbyRoot = rtrim(self::canonical((string) $kirby->root('kirby')), '/');
        $vendor = self::project($kirby).'/vendor';
        if (str_starts_with($path, $kirbyRoot.'/') || str_starts_with($path, $vendor.'/')) {
            return 'vendor';
        }

        return 'app';
    }

    /**
     * Kirby roots on a public/index.php setup keep the ".." segment.
     * realpath removes it. A missing file keeps the original path.
     */
    private static function canonical(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = realpath($path);

        return is_string($real) ? str_replace('\\', '/', $real) : $path;
    }
}
