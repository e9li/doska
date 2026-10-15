<?php

declare(strict_types=1);

namespace Doska\Tests;

use Doska\Routing\RouteLoader;
use PHPUnit\Framework\TestCase;

final class RouteLoaderTest extends TestCase
{
    public function testEnabledLoaderRegistersTheNoteAndMcpRoutes(): void
    {
        $routes = (new RouteLoader(true))();

        $notes = $routes->get('_doska_notes');
        $mcp = $routes->get('_doska_mcp');
        self::assertNotNull($notes);
        self::assertNotNull($mcp);
        $list = $routes->get('_doska_notes_list');
        self::assertNotNull($list);
        self::assertSame('/_doska/notes', $notes->getPath());
        self::assertSame(['POST'], $notes->getMethods());
        self::assertTrue($notes->getDefault('_stateless'));
        self::assertSame('/_doska/notes', $list->getPath());
        self::assertSame(['GET', 'HEAD'], $list->getMethods());
        self::assertTrue($list->getDefault('_stateless'));
        self::assertSame('/_doska/mcp', $mcp->getPath());
        self::assertContains('POST', $mcp->getMethods());
        self::assertTrue($mcp->getDefault('_stateless'));
    }

    public function testDisabledLoaderIsEmpty(): void
    {
        self::assertCount(0, (new RouteLoader(false))());
    }
}
