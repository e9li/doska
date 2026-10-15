<?php

declare(strict_types=1);

namespace Doska\Controller;

use Doska\Http\Loopback;
use Doska\Mcp\ToolRunner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless MCP endpoint. One JSON-RPC POST, answered with application/json.
 * There is no session id and no SSE stream.
 */
final class McpController
{
    public function __construct(
        private readonly ToolRunner $tools,
        private readonly bool $allowRemoteAccess,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!Loopback::allows($request, $this->allowRemoteAccess)) {
            return $this->failure(null, -32001, 'Doska answers only on loopback.', 403);
        }

        if ('POST' !== $request->getMethod()) {
            return $this->failure(
                null,
                -32600,
                'Doska MCP is stateless HTTP. POST one JSON-RPC request. There is no session and no SSE stream.',
                405,
            );
        }

        $accept = (string) $request->headers->get('Accept', '');
        if ('' !== $accept && !str_contains($accept, 'application/json') && !str_contains($accept, '*/*')) {
            return $this->failure(
                null,
                -32600,
                'Doska MCP is stateless HTTP and answers with application/json. There is no session and no SSE stream.',
                406,
            );
        }

        try {
            $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->failure(null, -32700, 'Parse error.', 400);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return $this->failure(null, -32600, 'Doska MCP accepts one JSON-RPC object per request.', 400);
        }

        $method = $payload['method'] ?? null;
        if (!is_string($method) || '' === $method) {
            return $this->failure($this->id($payload['id'] ?? null), -32600, 'Invalid request.', 400);
        }

        if (!array_key_exists('id', $payload)) {
            if (str_starts_with($method, 'notifications/')) {
                return new Response('', 202);
            }

            return $this->failure(null, -32600, 'Invalid request.', 400);
        }

        $id = $this->id($payload['id']);

        if ('initialize' === $method) {
            return $this->success($id, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'doska', 'version' => '0.1.0'],
                'instructions' => 'List pending notes with doska_get_pending, acknowledge one, open its file and line, '
                    .'edit, then resolve with a one-line summary. '
                    .'This endpoint is stateless HTTP: do not open a session.',
            ]);
        }

        if ('ping' === $method) {
            return $this->success($id, new \stdClass());
        }

        if ('tools/list' === $method) {
            return $this->success($id, ['tools' => $this->tools->definitions()]);
        }

        if ('tools/call' === $method) {
            $params = $payload['params'] ?? [];
            if (!is_array($params) || (array_is_list($params) && [] !== $params)) {
                return $this->failure($id, -32602, 'Invalid params.', 200);
            }

            return $this->success($id, $this->tools->call($params));
        }

        return $this->failure(
            $id,
            -32601,
            'Method not found: '.$method
                .'. Doska MCP is stateless and implements initialize, tools/list, and tools/call.',
            200,
        );
    }

    private function success(string|int|float|null $id, mixed $result): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function failure(string|int|float|null $id, int $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    private function id(mixed $id): string|int|float|null
    {
        if (is_string($id) || is_int($id) || is_float($id) || null === $id) {
            return $id;
        }

        return null;
    }
}
