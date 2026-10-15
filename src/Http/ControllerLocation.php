<?php

declare(strict_types=1);

namespace Doska\Http;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

/**
 * File and line of the controller that produced a request.
 */
final class ControllerLocation
{
    /**
     * @return array{controller: string, file: string, line: int, route: ?string, scope: string}|null
     */
    public static function fromRequest(Request $request, string $projectDir): ?array
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) || '' === $controller) {
            return null;
        }

        $route = $request->attributes->get('_route');
        $route = is_string($route) ? $route : null;

        $method = self::reflect($controller);
        if (null === $method) {
            return [
                'controller' => $controller,
                'file' => $controller,
                'line' => 1,
                'route' => $route,
                'scope' => 'app',
            ];
        }

        $file = $method->getFileName();
        [$path, $scope] = PathScope::locate(is_string($file) ? $file : $controller, $projectDir);

        return [
            'controller' => $controller,
            'file' => $path,
            'line' => $method->getStartLine(),
            'route' => $route,
            'scope' => $scope,
        ];
    }

    private static function reflect(string $controller): ?ReflectionMethod
    {
        try {
            if (str_contains($controller, '::')) {
                [$class, $method] = explode('::', $controller, 2);

                return new ReflectionMethod($class, $method);
            }

            if (class_exists($controller)) {
                $class = new ReflectionClass($controller);
                if ($class->hasMethod('__invoke')) {
                    return $class->getMethod('__invoke');
                }
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }
}
