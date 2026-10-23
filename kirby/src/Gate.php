<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Kirby\Cms\App;

/**
 * Doska stays off unless the site is in debug mode and the request is local.
 */
final class Gate
{
    public static function allowed(App $kirby): bool
    {
        if ($kirby->option('debug') !== true) {
            return false;
        }
        if ($kirby->option('doska.kirby.enabled', true) !== true) {
            return false;
        }
        if ($kirby->option('doska.kirby.allow_remote_access') === true) {
            return true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ('127.0.0.1' === $ip || '::1' === $ip) {
            return true;
        }

        $host = $kirby->request()->url()->host();

        return 'localhost' === $host || str_ends_with($host, '.test');
    }

    public static function webPage(App $kirby, string $path): bool
    {
        if (!self::allowed($kirby) || $kirby->request()->cli()) {
            return false;
        }

        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (str_contains($agent, 'Symfony HttpClient')) {
            return false;
        }

        $path = trim($path, '/');
        $prefixes = [
            (string) $kirby->option('panel.slug', 'panel'),
            (string) $kirby->option('api.slug', 'api'),
            'media',
            '_doska',
        ];
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Skip the pages cache for this request so a cached document cannot hide
     * the source comments, and so those comments are not stored.
     */
    public static function disablePageCache(App $kirby): void
    {
        $cache = $kirby->cache('pages');
        $property = new \ReflectionProperty($cache, 'options');
        $options = $property->getValue($cache);
        if (is_array($options)) {
            $options['active'] = false;
            $property->setValue($cache, $options);
        }
        $kirby->response()->cache(false);
        $kirby->response()->header('Cache-Control', 'no-store', true);
    }
}
