<?php

declare(strict_types=1);

namespace Doska\Tests;

use PHPUnit\Framework\TestCase;
use Doska\EventListener\InjectToolbarListener;
use Doska\Tests\Fixture\SampleController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InjectToolbarListenerTest extends TestCase
{
    public function testFullHtmlPageReceivesTheToolbarAndControllerComment(): void
    {
        $response = $this->dispatch($this->htmlRequest(), $this->htmlResponse());
        $content = (string) $response->getContent();

        self::assertStringContainsString('id="doska-toolbar"', $content);
        self::assertStringContainsString('src="/_doska/toolbar.js"', $content);
        self::assertStringContainsString('Doska\\Tests\\Fixture\\SampleController::index route:sample', $content);
        self::assertStringContainsString('<!-- </controller> -->', $content);
        self::assertStringContainsString('&quot;route&quot;:&quot;sample&quot;', $content);
        self::assertGreaterThan(
            strpos($content, '<!-- </controller> -->'),
            strpos($content, 'id="doska-toolbar"'),
        );
    }

    public function testRedirectIsUntouched(): void
    {
        $response = new Response('<html></html>', 302, ['Location' => '/next', 'Content-Type' => 'text/html']);
        $content = (string) $this->dispatch($this->htmlRequest(), $response)->getContent();

        self::assertSame('<html></html>', $content);
    }

    public function testTurboFrameDoesNotReceiveTheToolbar(): void
    {
        $request = $this->htmlRequest();
        $request->headers->set('Turbo-Frame', 'modal');
        $content = (string) $this->dispatch($request, $this->htmlResponse())->getContent();

        self::assertStringNotContainsString('id="doska-toolbar"', $content);
        self::assertStringContainsString('<!-- <controller>', $content);
    }

    public function testJsonIsUntouched(): void
    {
        $response = new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);
        $content = (string) $this->dispatch($this->htmlRequest(), $response)->getContent();

        self::assertSame('{"ok":true}', $content);
    }

    public function testRemoteClientIsIgnored(): void
    {
        $request = $this->htmlRequest();
        $request->server->set('REMOTE_ADDR', '203.0.113.8');
        $content = (string) $this->dispatch($request, $this->htmlResponse())->getContent();

        self::assertStringNotContainsString('doska-toolbar', $content);
        self::assertStringNotContainsString('<!-- <controller>', $content);
    }

    public function testProfilerPathIsIgnored(): void
    {
        $request = Request::create('/_profiler/abc', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $content = (string) $this->dispatch($request, $this->htmlResponse())->getContent();

        self::assertStringNotContainsString('doska-toolbar', $content);
    }

    public function testSubRequestWrapsTheFragmentWithoutTheToolbar(): void
    {
        $response = $this->dispatch($this->htmlRequest(), $this->htmlResponse('<p>Part</p>'), HttpKernelInterface::SUB_REQUEST);
        $content = (string) $response->getContent();

        self::assertStringStartsWith('<!-- <controller>', $content);
        self::assertStringEndsWith("<!-- </controller> -->", trim($content));
        self::assertStringNotContainsString('id="doska-toolbar"', $content);
    }

    private function htmlRequest(): Request
    {
        $request = Request::create('/sample', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->attributes->set('_controller', SampleController::class.'::index');
        $request->attributes->set('_route', 'sample');

        return $request;
    }

    private function htmlResponse(string $body = '<html><head></head><body><p>Hi</p></body></html>'): Response
    {
        return new Response($body, 200, ['Content-Type' => 'text/html']);
    }

    private function dispatch(Request $request, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $name): string {
            if ('_doska_toolbar' !== $name) {
                throw new RouteNotFoundException($name);
            }

            return '/_doska/toolbar.js';
        });

        $listener = new InjectToolbarListener(
            $urls,
            true,
            dirname(__DIR__),
            false,
            ['/_profiler', '/_wdt'],
            ['top' => null, 'right' => null, 'bottom' => 52, 'left' => 16],
        );

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, $type, $response);
        $listener->onKernelResponse($event);

        return $response;
    }
}
