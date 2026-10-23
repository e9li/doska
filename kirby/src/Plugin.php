<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Doska\Mcp\ToolRunner;
use Doska\Notes\NoteStore;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Http\Response;

/**
 * Kirby plugin definition. The Symfony bundle is a separate package.
 */
final class Plugin
{
    public static function definition(): array
    {
        return [
            'options' => [
                'enabled' => true,
                'allow_remote_access' => false,
                'dock' => 'bottom',
            ],
            'hooks' => [
                // Kirby binds the closure to the app. self:: would call App.
                'route:before' => function (string $path = ''): void {
                    Plugin::before($path);
                },
                'page.render:after' => function (string $html, Page $page, string $contentType = 'html'): string {
                    return Plugin::after($html, $page, $contentType);
                },
            ],
            'components' => [
                'snippet' => function (App $kirby, string|array|null $name, array $data = [], bool $slots = false): mixed {
                    return Plugin::snippet($kirby, $name, $data, $slots);
                },
            ],
            'routes' => self::routes(),
        ];
    }

    public static function before(string $path): void
    {
        $kirby = App::instance();
        if (!Gate::webPage($kirby, $path)) {
            return;
        }
        Runtime::$on = true;
        Gate::disablePageCache($kirby);
    }

    public static function after(string $html, Page $page, string $contentType): string
    {
        if (!Runtime::$on || 'html' !== $contentType || '' === $html) {
            return $html;
        }

        $kirby = $page->kirby();
        $html = Frames::template($kirby, $page, $html);
        $file = $page->template()->file();
        $source = is_string($file) ? Paths::relative($file, Paths::project($kirby)) : null;

        return Toolbar::inject($kirby, $html, $source, $page->id());
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function snippet(App $kirby, string|array|null $name, array $data, bool $slots): mixed
    {
        $result = self::components($kirby)['snippet']($kirby, $name, $data, $slots);
        if (!Runtime::$on || $slots || !is_string($result)) {
            return $result;
        }

        return Frames::around($kirby, $name, $data, $result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function routes(): array
    {
        return [
            [
                'pattern' => '_doska/toolbar.js',
                'method' => 'GET',
                'action' => function (): Response {
                    $kirby = App::instance();
                    if (!Gate::allowed($kirby)) {
                        return new Response('Not found', 'text/plain', 404);
                    }
                    $file = dirname(__DIR__, 2).'/assets/toolbar.js';
                    if (!is_file($file)) {
                        return new Response('Not found', 'text/plain', 404);
                    }

                    return new Response((string) file_get_contents($file), 'application/javascript', 200, [
                        'Cache-Control' => 'no-store',
                    ]);
                },
            ],
            [
                'pattern' => '_doska/notes',
                'method' => 'GET|POST',
                'action' => function (): Response {
                    return Plugin::notes(null);
                },
            ],
            [
                'pattern' => '_doska/notes/(:any)',
                'method' => 'DELETE',
                'action' => function (string $id): Response {
                    return Plugin::notes($id);
                },
            ],
            [
                'pattern' => '_doska/mcp',
                'method' => 'POST',
                'action' => function (): Response {
                    $kirby = App::instance();
                    if (!Gate::allowed($kirby)) {
                        return new Response('Not found', 'text/plain', 404);
                    }
                    $raw = Plugin::raw($kirby);
                    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
                    $result = Endpoint::mcp(new ToolRunner(Plugin::notesStore($kirby)), $kirby->request()->method(), $raw, $accept);

                    return Plugin::respond($result['status'], $result['body']);
                },
            ],
        ];
    }

    public static function notes(?string $id): Response
    {
        $kirby = App::instance();
        if (!Gate::allowed($kirby)) {
            return new Response('Not found', 'text/plain', 404);
        }
        $result = Endpoint::notes(self::notesStore($kirby), $kirby->request()->method(), self::raw($kirby), $id);

        return self::respond($result['status'], $result['body']);
    }

    public static function notesStore(App $kirby): NoteStore
    {
        return new NoteStore(Paths::project($kirby), 'site/cache/doska/notes.json');
    }

    public static function raw(App $kirby): string
    {
        $contents = $kirby->request()->body()->contents();
        if (is_array($contents)) {
            return json_encode($contents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return $contents;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    public static function respond(int $status, ?array $body): Response
    {
        if (204 === $status || 202 === $status) {
            return new Response('', 'text/plain', $status);
        }

        return new Response(
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'application/json',
            $status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function components(App $kirby): array
    {
        static $components = null;

        return $components ??= require $kirby->root('kirby').'/config/components.php';
    }
}
