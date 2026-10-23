<?php

declare(strict_types=1);

namespace Doska\Kirby;

/**
 * True only for the browser request that should see source comments.
 * A cache warmer and the Panel leave it false, so their HTML stays clean.
 */
final class Runtime
{
    public static bool $on = false;
}
