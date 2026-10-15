<?php

declare(strict_types=1);

namespace Doska\Twig;

use Doska\Http\PathScope;
use ReflectionClass;
use ReflectionProperty;

/**
 * Renders the open and close comments around a Twig template.
 *
 * When Symfony UX Twig Component is installed, a class component also carries
 * the PHP file that defines it.
 */
final class SourceMapRuntime
{
    /** @var array<string, string>|null template name => "path:line" */
    private ?array $classByTemplate = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly ?object $componentFactory = null,
    ) {
    }

    public function openTemplate(string $name): string
    {
        if (!$this->maps($name)) {
            return '';
        }

        [$file, $scope] = PathScope::locate($name, $this->projectDir);
        $extra = $this->classFile($name);

        return Comments::open(PathScope::frameName($file), $file, 1, $scope, $extra);
    }

    public function closeTemplate(string $name): string
    {
        if (!$this->maps($name)) {
            return '';
        }

        [$file] = PathScope::locate($name, $this->projectDir);

        return Comments::close(PathScope::frameName($file));
    }

    private function maps(string $name): bool
    {
        return PathScope::isHtmlTemplate($name) && !PathScope::isSkippedTemplate($name);
    }

    private function classFile(string $name): ?string
    {
        [$file] = PathScope::locate($name, $this->projectDir);

        return $this->classMap()[$name] ?? $this->classMap()[$file] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function classMap(): array
    {
        if (null !== $this->classByTemplate) {
            return $this->classByTemplate;
        }

        $this->classByTemplate = [];
        if (null === $this->componentFactory) {
            return $this->classByTemplate;
        }

        try {
            $property = new ReflectionProperty($this->componentFactory, 'config');
            $config = $property->getValue($this->componentFactory);
        } catch (\ReflectionException) {
            return $this->classByTemplate;
        }

        if (!is_array($config)) {
            return $this->classByTemplate;
        }

        foreach ($config as $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $template = $meta['template'] ?? null;
            $class = $meta['class'] ?? null;
            if (!is_string($template) || !is_string($class) || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $filename = $reflection->getFileName();
            if (!is_string($filename)) {
                continue;
            }

            [$path] = PathScope::locate($filename, $this->projectDir);
            $location = $path.':'.$reflection->getStartLine();
            $this->classByTemplate[$template] = $location;
            [$relativeTemplate] = PathScope::locate($template, $this->projectDir);
            $this->classByTemplate[$relativeTemplate] = $location;
        }

        return $this->classByTemplate;
    }
}
