<?php

declare(strict_types=1);

namespace Doska\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves the toolbar script and a tiny config document. Both are debug-only
 * because the route loader omits them when the kernel is not in debug.
 */
final class ToolbarController
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function script(Request $request): BinaryFileResponse
    {
        $path = dirname(__DIR__, 2).'/assets/toolbar.js';
        $response = new BinaryFileResponse($path, headers: [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], public: false);
        $response->setEtag(hash_file('xxh128', $path) ?: null);
        $response->isNotModified($request);

        return $response;
    }

    public function config(): JsonResponse
    {
        return new JsonResponse([
            'root' => $this->projectDir,
        ]);
    }
}
