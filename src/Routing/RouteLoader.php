<?php

declare(strict_types=1);

namespace Doska\Routing;

use Doska\Controller\McpController;
use Doska\Controller\NoteController;
use Doska\Controller\ToolbarController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers the toolbar routes only while the bundle is enabled.
 */
final class RouteLoader
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function __invoke(): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }

        $routes->add('_doska_toolbar', new Route('/_doska/toolbar.js', [
            '_controller' => ToolbarController::class.'::script',
            '_stateless' => true,
        ], methods: ['GET', 'HEAD']));

        $routes->add('_doska_config', new Route('/_doska/config', [
            '_controller' => ToolbarController::class.'::config',
            '_stateless' => true,
        ], methods: ['GET', 'HEAD']));

        $routes->add('_doska_notes', new Route('/_doska/notes', [
            '_controller' => NoteController::class.'::create',
            '_stateless' => true,
        ], methods: ['POST']));

        $routes->add('_doska_notes_list', new Route('/_doska/notes', [
            '_controller' => NoteController::class.'::index',
            '_stateless' => true,
        ], methods: ['GET', 'HEAD']));

        $routes->add('_doska_mcp', new Route('/_doska/mcp', [
            '_controller' => McpController::class.'::__invoke',
            '_stateless' => true,
        ], methods: ['GET', 'POST', 'DELETE']));

        return $routes;
    }
}
