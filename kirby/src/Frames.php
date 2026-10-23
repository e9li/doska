<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Doska\Twig\Comments;
use Kirby\Cms\App;
use Kirby\Cms\Block;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

/**
 * HTML comments in the same grammar as the Symfony Twig visitor.
 * A PHP template has no tag line, so the comment names the snippet file
 * and the caller line, plus the content file when a block is in scope.
 */
final class Frames
{
    /**
     * @param array<string, mixed> $data
     */
    public static function around(App $kirby, string|array|null $name, array $data, string $html): string
    {
        if ('' === $html || !str_starts_with(ltrim($html), '<')) {
            return $html;
        }

        $file = \Kirby\Template\Snippet::file($name);
        if (!is_string($file)) {
            return $html;
        }

        $project = Paths::project($kirby);
        $label = is_array($name) ? (string) ($name[0] ?? 'snippet') : (string) $name;
        $caller = self::caller($kirby, $project);
        $open = '';
        if (null !== $caller) {
            $open .= Comments::caller($caller['file'], $caller['line'], $caller['scope']);
        }
        $open .= Comments::open(
            'snippet:'.$label,
            Paths::relative($file, $project),
            1,
            Paths::scope($file, $kirby),
            self::blockExtra($kirby, $data, $project),
        );

        return $open.$html.Comments::close('snippet:'.$label);
    }

    public static function template(App $kirby, Page $page, string $html): string
    {
        $project = Paths::project($kirby);
        $file = $page->template()->file();
        $relative = is_string($file) ? Paths::relative($file, $project) : $page->intendedTemplate()->name();
        $name = 'template:'.$page->intendedTemplate()->name();
        $open = Comments::open($name, $relative, 1, Paths::scope(is_string($file) ? $file : null, $kirby), self::pageExtra($kirby, $page, $project));
        $close = Comments::close($name);

        // The toolbar only walks comments inside the document element.
        // A comment wrapped around the whole page sits outside <html>.
        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);
            $html = substr_replace($html, "\n".$open, $at, 0);
            $closeAt = strripos($html, '</body>');
            if (false !== $closeAt && $closeAt >= $at) {
                return substr_replace($html, $close."\n", $closeAt, 0);
            }
        }

        return $open.$html.$close;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function blockExtra(App $kirby, array $data, string $project): ?string
    {
        $block = $data['block'] ?? null;
        if (!$block instanceof Block) {
            return null;
        }

        $file = self::contentRelative($kirby, $block->parent(), $project);
        if (null === $file) {
            return null;
        }

        $parts = ['content', $file];
        $field = $block->field()?->key();
        if (is_string($field) && '' !== $field) {
            $parts[] = 'field';
            $parts[] = $field;
        }
        $parts[] = 'block';
        $parts[] = $block->id();
        $parts[] = 'type';
        $parts[] = $block->type();

        return implode(' ', $parts);
    }

    public static function pageExtra(App $kirby, Page $page, string $project): ?string
    {
        $file = self::contentRelative($kirby, $page, $project);

        return null === $file ? null : 'content '.$file;
    }

    /**
     * @return array{file: string, line: int, scope: string}|null
     */
    private static function caller(App $kirby, string $project): ?array
    {
        $skip = [
            rtrim(str_replace('\\', '/', (string) $kirby->root('kirby')), '/'),
            rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'),
            $project.'/vendor',
        ];

        foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 24) as $frame) {
            $file = $frame['file'] ?? '';
            if (!is_string($file) || !str_ends_with($file, '.php')) {
                continue;
            }
            $normal = str_replace('\\', '/', $file);
            foreach ($skip as $prefix) {
                if (str_starts_with($normal, $prefix.'/')) {
                    continue 2;
                }
            }
            $line = $frame['line'] ?? 1;

            return [
                'file' => Paths::relative($normal, $project),
                'line' => is_int($line) && $line > 0 ? $line : 1,
                'scope' => Paths::scope($normal, $kirby),
            ];
        }

        return null;
    }

    private static function contentRelative(App $kirby, ModelWithContent $model, string $project): ?string
    {
        try {
            $language = $kirby->language() ?? 'default';
            $file = $model->version()->contentFile($language);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($file) || '' === $file) {
            return null;
        }

        return Paths::relative($file, $project);
    }
}
