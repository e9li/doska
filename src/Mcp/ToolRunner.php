<?php

declare(strict_types=1);

namespace Doska\Mcp;

use Doska\Notes\NoteException;
use Doska\Notes\NoteStore;

/**
 * Maps the six Doska tools onto the note file.
 * Tool failures stay inside the MCP result as isError. They are not JSON-RPC errors.
 */
final class ToolRunner
{
    public function __construct(private readonly NoteStore $notes)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $id = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Note id from doska_get_pending.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];

        return [
            [
                'name' => 'doska_get_pending',
                'description' => 'List open Doska notes (pending or acknowledged), oldest first. '
                    .'Resolved and dismissed notes are omitted. Each note has the person\'s text, the page URL, '
                    .'the controller and route, and one entry per pinned element: tag, selector, '
                    .'data-doska-loc file and line, component stack, Stimulus, Live Component, Turbo frame, '
                    .'and visible text.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'doska_get_note',
                'description' => 'Fetch one note by id, including its reply thread.',
                'inputSchema' => $id,
            ],
            [
                'name' => 'doska_acknowledge',
                'description' => 'Mark a pending note as acknowledged before editing it.',
                'inputSchema' => $id,
            ],
            [
                'name' => 'doska_resolve',
                'description' => 'Mark an acknowledged note resolved, with a one-line summary of the change. '
                    .'A pending note must be acknowledged first. A resolved or dismissed note cannot change.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'summary' => ['type' => 'string', 'description' => 'One line describing the change.'],
                    ],
                    'required' => ['id', 'summary'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'doska_dismiss',
                'description' => 'Dismiss a pending or acknowledged note that will not be acted on. '
                    .'A reason is required.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'reason'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'doska_reply',
                'description' => 'Add a message while the note is pending or acknowledged. The status does not change.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'message'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $params
     *
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    public function call(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || '' === $name) {
            return $this->failure('Tool name is required.');
        }
        if (!is_array($arguments) || (array_is_list($arguments) && [] !== $arguments)) {
            return $this->failure('arguments must be an object.');
        }

        try {
            $payload = match ($name) {
                'doska_get_pending' => $this->pending(),
                'doska_get_note' => $this->notes->get($this->argument($arguments, 'id')),
                'doska_acknowledge' => $this->notes->acknowledge($this->argument($arguments, 'id')),
                'doska_resolve' => $this->notes->resolve(
                    $this->argument($arguments, 'id'),
                    $this->argument($arguments, 'summary'),
                ),
                'doska_dismiss' => $this->notes->dismiss(
                    $this->argument($arguments, 'id'),
                    $this->argument($arguments, 'reason'),
                ),
                'doska_reply' => $this->notes->reply(
                    $this->argument($arguments, 'id'),
                    $this->argument($arguments, 'message'),
                ),
                default => throw new NoteException(
                    'doska_watch_notes' === $name
                        ? 'doska_watch_notes is not available. Poll doska_get_pending.'
                        : 'Unknown tool: '.$name.'.',
                ),
            };
        } catch (NoteException $exception) {
            return $this->failure($exception->getMessage());
        }

        return $this->success($payload);
    }

    /**
     * @return array{count: int, notes: list<array<string, mixed>>}
     */
    private function pending(): array
    {
        $notes = $this->notes->pending();

        return ['count' => count($notes), 'notes' => $notes];
    }

    /**
     * @param array<mixed> $arguments
     */
    private function argument(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!is_string($value) || '' === trim($value)) {
            throw new NoteException($name.' is required.');
        }

        return trim($value);
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    private function success(mixed $payload): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ]],
            'isError' => false,
        ];
    }

    /**
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    private function failure(string $message): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(
                    ['error' => $message],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            ]],
            'isError' => true,
        ];
    }
}
