<?php

declare(strict_types=1);

use Doska\Routing\RouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(RouteLoader::class, 'service');
};
