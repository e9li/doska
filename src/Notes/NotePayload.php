<?php

declare(strict_types=1);

namespace Doska\Notes;

/**
 * Turns the toolbar's JSON body into the fields stored on a note.
 * Unknown keys are dropped. Strings are clipped so one request cannot fill the note file.
 */
final class NotePayload
{
    /**
     * @param array<mixed> $body
     *
     * @return array{
     *     text: string,
     *     url: ?string,
     *     controller: ?string,
     *     source: ?string,
     *     route: ?string,
     *     elements: list<array<string, mixed>>
     * }
     */
    public static function fromBody(array $body): array
    {
        if (array_is_list($body)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        }

        $text = self::clip($body['text'] ?? '', 8000);
        $elements = self::elements($body['elements'] ?? []);
        if ('' === $text && [] === $elements) {
            throw new \InvalidArgumentException('Write a note or pin an element.');
        }

        return [
            'text' => $text,
            'url' => self::nullable($body['url'] ?? null, 2000),
            'controller' => self::nullable($body['controller'] ?? null, 500),
            'source' => self::nullable($body['source'] ?? null, 500),
            'route' => self::nullable($body['route'] ?? null, 200),
            'elements' => $elements,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function elements(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $elements = [];
        foreach ($value as $element) {
            if (count($elements) >= 20 || !is_array($element)) {
                continue;
            }

            $file = self::nullable($element['file'] ?? null, 500);
            $line = self::line($element['line'] ?? null);
            $location = self::nullable($element['location'] ?? null, 600);
            if (null === $location && null !== $file && null !== $line) {
                $location = $file.':'.$line;
            }

            $elements[] = [
                'tag' => self::clip($element['tag'] ?? $element['tagName'] ?? '', 80),
                'selector' => self::clip($element['selector'] ?? '', 500),
                'location' => $location,
                'file' => $file,
                'line' => $line,
                'stack' => self::stack($element['stack'] ?? []),
                'text' => self::clip($element['text'] ?? $element['textContent'] ?? '', 500),
                'selectedText' => self::clip($element['selectedText'] ?? '', 500),
                'stimulus' => self::stimulus($element['stimulus'] ?? null),
                'live' => self::live($element['live'] ?? null),
                'turbo' => self::turbo($element['turbo'] ?? null),
            ];
        }

        return $elements;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stack(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $frames = [];
        foreach ($value as $frame) {
            if (count($frames) >= 40 || !is_array($frame)) {
                continue;
            }
            $frames[] = [
                'name' => self::clip($frame['name'] ?? '', 200),
                'file' => self::nullable($frame['file'] ?? null, 500),
                'line' => self::line($frame['line'] ?? null),
                'scope' => self::scope($frame['scope'] ?? null),
                'extra' => self::nullable($frame['extra'] ?? null, 500),
                'caller' => self::caller($frame['caller'] ?? null),
            ];
        }

        return $frames;
    }

    /**
     * @return array{file: ?string, line: ?int, scope: ?string}|null
     */
    private static function caller(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $caller = [
            'file' => self::nullable($value['file'] ?? null, 500),
            'line' => self::line($value['line'] ?? null),
            'scope' => self::scope($value['scope'] ?? null),
        ];

        return null === $caller['file'] ? null : $caller;
    }

    /**
     * @return array{controllers: string, element: string, values: list<string>}|null
     */
    private static function stimulus(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $controllers = self::clip($value['controllers'] ?? '', 300);
        if ('' === $controllers) {
            return null;
        }

        return [
            'controllers' => $controllers,
            'element' => self::clip($value['element'] ?? '', 200),
            'values' => self::words($value['values'] ?? []),
        ];
    }

    /**
     * @return array{name: string, props: ?string}|null
     */
    private static function live(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $name = self::clip($value['name'] ?? '', 200);
        if ('' === $name) {
            return null;
        }

        return [
            'name' => $name,
            'props' => self::nullable($value['props'] ?? null, 500),
        ];
    }

    /**
     * @return array{id: ?string, src: ?string}|null
     */
    private static function turbo(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $turbo = [
            'id' => self::nullable($value['id'] ?? null, 200),
            'src' => self::nullable($value['src'] ?? null, 2000),
        ];

        return null === $turbo['id'] && null === $turbo['src'] ? null : $turbo;
    }

    /**
     * @return list<string>
     */
    private static function words(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $words = [];
        foreach ($value as $word) {
            if (count($words) >= 20) {
                break;
            }
            $word = self::clip($word, 80);
            if ('' !== $word) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private static function scope(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['app', 'vendor', 'external'], true) ? $value : null;
    }

    private static function line(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private static function nullable(mixed $value, int $max): ?string
    {
        $text = self::clip($value, $max);

        return '' === $text ? null : $text;
    }

    private static function clip(mixed $value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $text = trim((string) $value);
        if (!preg_match('//u', $text)) {
            return '';
        }
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max);
        }

        return $text;
    }
}
