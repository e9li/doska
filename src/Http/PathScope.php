<?php

declare(strict_types=1);

namespace Doska\Http;

/**
 * Project-relative path plus a scope token for the source comment.
 */
final class PathScope
{
    /**
     * @return array{0: string, 1: string} path and scope (app, vendor, or external)
     */
    public static function locate(string $path, string $projectDir): array
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $projectDir), '/');

        if ('' !== $root && str_starts_with($path, $root.'/')) {
            return [substr($path, strlen($root) + 1), 'app'];
        }

        if (str_starts_with($path, '@')) {
            return [$path, 'vendor'];
        }

        if (str_starts_with($path, '/')) {
            return [$path, 'external'];
        }

        return [$path, 'app'];
    }

    public static function isHtmlTemplate(string $name): bool
    {
        return str_ends_with($name, '.html.twig') || str_ends_with($name, '.svg.twig');
    }

    public static function isSkippedTemplate(string $name): bool
    {
        return str_starts_with($name, '@WebProfiler')
            || str_starts_with($name, '@Debug');
    }

    /**
     * Short frame name. Component templates become Button or Form:Row.
     */
    public static function frameName(string $template): string
    {
        if (preg_match('#(?:^|/)components/(.+)\.html\.twig$#', $template, $matches)) {
            return str_replace('/', ':', $matches[1]);
        }

        return $template;
    }
}
