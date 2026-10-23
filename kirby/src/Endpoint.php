<?php

declare(strict_types=1);

namespace Doska\Kirby;

use Doska\Mcp\ToolRunner;
use Doska\Notes\NoteException;
use Doska\Notes\NotePayload;
use Doska\Notes\NoteStore;

/**
 * The toolbar's note routes and the stateless MCP POST.
 * Kirby turns the result into a response. This class does not know Kirby.
 *
 * @phpstan-type Result array{status: int, body: array<string, mixed>|null}
 */
final class Endpoint
{
    /**
     * @return Result
     */
    public static function notes(NoteStore $notes, string $method, string $raw, ?string $id = null): array
    {
        $method = strtoupper($method);
        if ('GET' === $method && null === $id) {
            return ['status' => 200, 'body' => ['notes' => $notes->all()]];
        }

        if ('DELETE' === $method && null !== $id) {
            try {
                $notes->delete($id);
            } catch (NoteException $exception) {
                return ['status' => 404, 'body' => ['error' => $exception->getMessage()]];
            }

            return ['status' => 204, 'body' => null];
        }

        if ('POST' === $method && null === $id) {
            if (strlen($raw) > 100_000) {
                return ['status' => 413, 'body' => ['error' => 'Note is too large.']];
            }
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['status' => 400, 'body' => ['error' => 'Expected a JSON object.']];
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                return ['status' => 400, 'body' => ['error' => 'Expected a JSON object.']];
            }
            try {
                $note = $notes->add(NotePayload::fromBody($decoded));
            } catch (\InvalidArgumentException $exception) {
                return ['status' => 422, 'body' => ['error' => $exception->getMessage()]];
            }

            return ['status' => 201, 'body' => ['id' => $note['id'], 'status' => $note['status']]];
        }

        return ['status' => 405, 'body' => ['error' => 'Method not allowed.']];
    }

    /**
     * @return Result
     */
    public static function mcp(ToolRunner $tools, string $method, string $raw, string $accept): array
    {
        if ('POST' !== strtoupper($method)) {
            return self::rpc(null, -32600, 'Doska MCP is stateless HTTP. POST one JSON-RPC request. There is no session and no SSE stream.', 405);
        }
        if ('' !== $accept && !str_contains($accept, 'application/json') && !str_contains($accept, '*/*')) {
            return self::rpc(null, -32600, 'Doska MCP is stateless HTTP and answers with application/json. There is no session and no SSE stream.', 406);
        }
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::rpc(null, -32700, 'Parse error.', 400);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return self::rpc(null, -32600, 'Doska MCP accepts one JSON-RPC object per request.', 400);
        }

        $methodName = $payload['method'] ?? null;
        if (!is_string($methodName) || '' === $methodName) {
            return self::rpc(self::id($payload['id'] ?? null), -32600, 'Invalid request.', 400);
        }
        if (!array_key_exists('id', $payload)) {
            if (str_starts_with($methodName, 'notifications/')) {
                return ['status' => 202, 'body' => null];
            }

            return self::rpc(null, -32600, 'Invalid request.', 400);
        }

        $id = self::id($payload['id']);
        if ('initialize' === $methodName) {
            return self::result($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'doska', 'version' => '0.2.0'],
                'instructions' => 'List pending notes with doska_get_pending, acknowledge one, open its file and line, '
                    .'edit, then resolve with a one-line summary. '
                    .'On a Kirby note, mode is dev or content. Dev edits the PHP. Content edits the field named in the element stack. '
                    .'This endpoint is stateless HTTP: do not open a session.',
            ]);
        }
        if ('ping' === $methodName) {
            return self::result($id, new \stdClass());
        }
        if ('tools/list' === $methodName) {
            return self::result($id, ['tools' => $tools->definitions()]);
        }
        if ('tools/call' === $methodName) {
            $params = $payload['params'] ?? [];
            if (!is_array($params) || (array_is_list($params) && [] !== $params)) {
                return self::rpc($id, -32602, 'Invalid params.', 200);
            }

            return self::result($id, $tools->call($params));
        }

        return self::rpc(
            $id,
            -32601,
            'Method not found: '.$methodName.'. Doska MCP is stateless and implements initialize, tools/list, and tools/call.',
            200,
        );
    }

    /**
     * @param array<string, mixed>|object $result
     *
     * @return Result
     */
    private static function result(string|int|float|null $id, array|object $result): array
    {
        return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]];
    }

    /**
     * @return Result
     */
    private static function rpc(string|int|float|null $id, int $code, string $message, int $status): array
    {
        return [
            'status' => $status,
            'body' => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => $code, 'message' => $message],
            ],
        ];
    }

    private static function id(mixed $id): string|int|float|null
    {
        if (is_string($id) || is_int($id) || is_float($id) || null === $id) {
            return $id;
        }

        return null;
    }
}
