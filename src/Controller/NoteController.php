<?php

declare(strict_types=1);

namespace Doska\Controller;

use Doska\Http\Loopback;
use Doska\Notes\NotePayload;
use Doska\Notes\NoteStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Accepts the toolbar's Send action. The toolbar does not speak MCP.
 */
final class NoteController
{
    public function __construct(
        private readonly NoteStore $notes,
        private readonly bool $allowRemoteAccess,
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        if (!Loopback::allows($request, $this->allowRemoteAccess)) {
            return new JsonResponse(['error' => 'Doska answers only on loopback.'], 403);
        }

        if (strlen($request->getContent()) > 100_000) {
            return new JsonResponse(['error' => 'Note is too large.'], 413);
        }

        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Expected a JSON object.'], 400);
        }

        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Expected a JSON object.'], 400);
        }

        try {
            $note = $this->notes->add(NotePayload::fromBody($body));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'id' => $note['id'],
            'status' => $note['status'],
        ], 201);
    }
}
