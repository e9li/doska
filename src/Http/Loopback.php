<?php

declare(strict_types=1);

namespace Doska\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The toolbar, the note route, and the MCP route share one loopback check.
 * The Host header is not consulted.
 */
final class Loopback
{
    public static function allows(Request $request, bool $allowRemoteAccess): bool
    {
        if ($allowRemoteAccess) {
            return true;
        }

        return in_array($request->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
    }
}
