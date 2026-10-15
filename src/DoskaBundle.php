<?php

declare(strict_types=1);

namespace Doska;

use Doska\Controller\McpController;
use Doska\Controller\NoteController;
use Doska\Controller\ToolbarController;
use Doska\EventListener\InjectToolbarListener;
use Doska\Mcp\ToolRunner;
use Doska\Notes\NoteStore;
use Doska\Routing\RouteLoader;
use Doska\Twig\SourceMapExtension;
use Doska\Twig\SourceMapRuntime;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Dev-only source map and prompt toolbar. When kernel.debug is false the
 * Twig visitor is not registered and the routes are empty.
 */
final class DoskaBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->booleanNode('allow_remote_access')
                    ->defaultFalse()
                    ->info('Serve the toolbar and source comments to non-loopback clients. Leave this off.')
                ->end()
                ->arrayNode('exclude_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/_profiler', '/_wdt', '/_error', '/_fragment', '/_doska'])
                ->end()
                ->enumNode('dock')
                    ->values(['top', 'right', 'bottom', 'left'])
                    ->defaultValue('bottom')
                    ->info('Edge the toolbar sits on. The settings panel in the toolbar can change this for the current browser.')
                ->end()
                ->arrayNode('position')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('top')->defaultNull()->end()
                        ->integerNode('right')->defaultNull()->end()
                        ->integerNode('bottom')->defaultValue(52)->end()
                        ->integerNode('left')->defaultValue(16)->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $debug = $builder->hasParameter('kernel.debug') && (bool) $builder->getParameter('kernel.debug');
        $enabled = $config['enabled'] && $debug;
        $projectDir = (string) $builder->getParameter('kernel.project_dir');

        $container->services()
            ->set(RouteLoader::class)
                ->arg('$enabled', $enabled)
                ->tag('routing.route_loader');

        $container->services()
            ->set(InjectToolbarListener::class)
                ->arg('$urlGenerator', new Reference('router'))
                ->arg('$enabled', $enabled)
                ->arg('$projectDir', $projectDir)
                ->arg('$allowRemoteAccess', $config['allow_remote_access'])
                ->arg('$excludePaths', $config['exclude_paths'])
                ->arg('$position', $config['position'])
                ->arg('$dock', $config['dock'])
                ->tag('kernel.event_listener', [
                    'event' => 'kernel.response',
                    'method' => 'onKernelResponse',
                    'priority' => -128,
                ]);

        if (!$enabled) {
            return;
        }

        $container->services()
            ->set(ToolbarController::class)
                ->arg('$projectDir', $projectDir)
                ->tag('controller.service_arguments');

        $container->services()
            ->set(NoteStore::class)
                ->arg('$projectDir', $projectDir);

        $container->services()
            ->set(ToolRunner::class)
                ->arg('$notes', new Reference(NoteStore::class));

        $container->services()
            ->set(NoteController::class)
                ->arg('$notes', new Reference(NoteStore::class))
                ->arg('$allowRemoteAccess', $config['allow_remote_access'])
                ->tag('controller.service_arguments');

        $container->services()
            ->set(McpController::class)
                ->arg('$tools', new Reference(ToolRunner::class))
                ->arg('$allowRemoteAccess', $config['allow_remote_access'])
                ->tag('controller.service_arguments');

        $runtime = $container->services()
            ->set(SourceMapRuntime::class)
                ->arg('$projectDir', $projectDir)
                ->tag('twig.runtime');

        if (class_exists(\Symfony\UX\TwigComponent\ComponentFactory::class)) {
            $runtime->arg('$componentFactory', new Reference('ux.twig_component.component_factory', ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }

        $container->services()
            ->set(SourceMapExtension::class)
                ->arg('$projectDir', $projectDir)
                ->tag('twig.extension');
    }
}
