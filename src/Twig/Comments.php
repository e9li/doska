<?php

declare(strict_types=1);

namespace Doska\Twig;

/**
 * Comment grammar the toolbar walks. Keep this in lockstep with assets/toolbar.js.
 *
 * Open:  <!-- <Name> path:line (app|vendor|external) | optional extra -->
 * Close: <!-- </Name> -->
 * Caller: <!-- @caller path:line (app|vendor|external) -->
 */
final class Comments
{
    public static function open(string $name, string $file, int $line, string $scope = 'app', ?string $extra = null): string
    {
        $comment = sprintf(
            '<!-- <%s> %s:%d (%s)',
            self::safe($name),
            self::safe($file),
            $line,
            $scope,
        );
        if (null !== $extra && '' !== $extra) {
            $comment .= ' | '.self::safe($extra);
        }

        return $comment.' -->';
    }

    public static function close(string $name): string
    {
        return sprintf('<!-- </%s> -->', self::safe($name));
    }

    public static function caller(string $file, int $line, string $scope = 'app'): string
    {
        return sprintf('<!-- @caller %s:%d (%s) -->', self::safe($file), $line, $scope);
    }

    /**
     * HTML comments end at the first "--".
     */
    private static function safe(string $value): string
    {
        return str_replace('--', '-', $value);
    }
}
