<?php

declare(strict_types=1);

namespace Doska\Tests;

use PHPUnit\Framework\TestCase;
use Doska\Twig\SourceMapExtension;
use Doska\Twig\SourceMapRuntime;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

final class SourceMapRenderTest extends TestCase
{
    public function testLiteralTagsCarryTheLineOfThatTag(): void
    {
        $html = $this->render('page.html.twig', <<<'TWIG'
            <div>
                <span>Hi</span>
            </div>
            TWIG);

        self::assertStringContainsString('<div data-doska-loc="page.html.twig:1">', $html);
        self::assertStringContainsString('<span data-doska-loc="page.html.twig:2">', $html);
        self::assertStringContainsString('<!-- <page.html.twig> page.html.twig:1 (app) -->', $html);
        self::assertStringContainsString('<!-- </page.html.twig> -->', $html);
    }

    public function testSameLineTagsBothReceiveTheSameLine(): void
    {
        $html = $this->render('row.html.twig', '<div><span>Hi</span></div>');

        self::assertStringContainsString('<div data-doska-loc="row.html.twig:1">', $html);
        self::assertStringContainsString('<span data-doska-loc="row.html.twig:1">', $html);
    }

    public function testBlockAndIncludeComments(): void
    {
        $html = $this->renderMany([
            'parent.html.twig' => <<<'TWIG'
                <section>
                {% include 'child.html.twig' %}
                {% block title %}<h1>Title</h1>{% endblock %}
                </section>
                TWIG,
            'child.html.twig' => '<p>Child</p>',
        ], 'parent.html.twig');

        self::assertStringContainsString('<!-- @caller parent.html.twig:2 (app) -->', $html);
        self::assertStringContainsString('<!-- <child.html.twig> child.html.twig:1 (app) -->', $html);
        self::assertStringContainsString('<!-- </child.html.twig> -->', $html);
        self::assertStringContainsString('<!-- <block:title> parent.html.twig:3 (app) -->', $html);
        self::assertStringContainsString('<h1 data-doska-loc="parent.html.twig:3">', $html);
        self::assertStringContainsString('<!-- </block:title> -->', $html);
    }

    public function testRawTextRegionsAreNotRewritten(): void
    {
        $html = $this->renderMany([
            'parent.html.twig' => <<<'TWIG'
                <script>
                {% include 'child.js.twig' %}
                var html = "<span>no</span>";
                {% block params %}var inner = "<i class=\"x\"></i>";{% endblock %}
                </script>
                <style>.a > div { color: red; } /* <b>x</b> */</style>
                <textarea><span>keep</span></textarea>
                <!-- <div>note</div> -->
                <p>Yes</p>
                TWIG,
            'child.js.twig' => 'var child = "<em>x</em>";',
        ], 'parent.html.twig');

        self::assertStringContainsString('var child = "<em>x</em>";', $html);
        self::assertStringContainsString('var html = "<span>no</span>";', $html);
        self::assertStringContainsString('.a > div { color: red; } /* <b>x</b> */', $html);
        self::assertStringNotContainsString('<!-- @caller', $html);
        self::assertStringContainsString('var inner = "<i class=\"x\"></i>";', $html);
        self::assertStringNotContainsString('<!-- <block:params>', $html);
        self::assertStringContainsString('<span>keep</span>', $html);
        self::assertStringContainsString('<!-- <div>note</div> -->', $html);
        self::assertStringContainsString('<p data-doska-loc="parent.html.twig:', $html);
        self::assertDoesNotMatchRegularExpression('/<span data-doska-loc=/', $html);
    }

    public function testTitleBlockStaysReadable(): void
    {
        $html = $this->render('base.html.twig', <<<'TWIG'
            <title>{% block title %}Anmelden{% endblock %} // app</title>
            <div>Body</div>
            TWIG);

        self::assertMatchesRegularExpression('/<title data-doska-loc="base\.html\.twig:\d+">Anmelden \/\/ app<\/title>/', $html);
        self::assertStringContainsString('<div data-doska-loc="base.html.twig:', $html);
    }

    public function testJsonTemplatesAreLeftAlone(): void
    {
        $html = $this->render('data.json.twig', '{"ok": true}');

        self::assertSame('{"ok": true}', $html);
    }

    public function testComponentTemplatesUseTheShortName(): void
    {
        $html = $this->render('components/Button.html.twig', '<button>Save</button>');

        self::assertStringContainsString('<!-- <Button> components/Button.html.twig:1 (app) -->', $html);
        self::assertStringContainsString('<button data-doska-loc="components/Button.html.twig:1">', $html);
    }

    private function render(string $name, string $source): string
    {
        return $this->renderMany([$name => $source], $name);
    }

    /**
     * @param array<string, string> $templates
     */
    private function renderMany(array $templates, string $name): string
    {
        $twig = new Environment(new ArrayLoader($templates), ['cache' => false]);
        $twig->addExtension(new SourceMapExtension('/project'));
        $twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
            public function load(string $class): ?object
            {
                if (SourceMapRuntime::class === $class) {
                    return new SourceMapRuntime('/project');
                }

                return null;
            }
        });

        return $twig->render($name);
    }
}
