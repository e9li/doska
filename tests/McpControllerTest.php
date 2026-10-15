<?php

declare(strict_types=1);

namespace Doska\Tests;

use Doska\Controller\McpController;
use Doska\Mcp\ToolRunner;
use Doska\Notes\NoteStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class McpControllerTest extends TestCase
{
    private string $dir;

    private NoteStore $store;

    private McpController $mcp;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/doska-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->store = new NoteStore($this->dir);
        $this->mcp = new McpController(new ToolRunner($this->store), false);
    }

    protected function tearDown(): void
    {
        $path = $this->dir.'/var/doska/notes.json';
        if (is_file($path)) {
            unlink($path);
        }
        @rmdir($this->dir.'/var/doska');
        @rmdir($this->dir.'/var');
        @rmdir($this->dir);
    }

    public function testToolsListAndToolsCallRoundTrip(): void
    {
        $note = $this->store->add([
            'text' => 'Make the heading larger',
            'url' => 'https://drehscheibe.test/login',
            'controller' => 'App\\Controller\\SecurityController::login',
            'source' => 'src/Controller/SecurityController.php:20',
            'route' => 'login',
            'elements' => [[
                'tag' => 'h3',
                'selector' => 'h3',
                'location' => 'templates/public/login.html.twig:13',
                'file' => 'templates/public/login.html.twig',
                'line' => 13,
                'stack' => [],
                'text' => 'Anmelden',
                'selectedText' => '',
                'stimulus' => null,
                'live' => null,
                'turbo' => null,
            ]],
        ]);

        $listed = $this->decode($this->mcp->__invoke($this->rpc('tools/list')));
        self::assertSame(200, $listed['status']);
        $names = array_column($listed['body']['result']['tools'], 'name');
        self::assertSame([
            'doska_get_pending',
            'doska_get_note',
            'doska_acknowledge',
            'doska_resolve',
            'doska_dismiss',
            'doska_reply',
        ], $names);

        $pending = $this->tool('doska_get_pending', []);
        self::assertFalse($pending['isError']);
        self::assertSame(1, $pending['payload']['count']);
        self::assertSame(
            'templates/public/login.html.twig:13',
            $pending['payload']['notes'][0]['elements'][0]['location'],
        );
        self::assertSame($note['id'], $pending['payload']['notes'][0]['id']);

        $early = $this->tool('doska_resolve', ['id' => $note['id'], 'summary' => 'Too soon.']);
        self::assertTrue($early['isError']);
        self::assertStringContainsString('acknowledged', $early['payload']['error']);

        $acknowledged = $this->tool('doska_acknowledge', ['id' => $note['id']]);
        self::assertFalse($acknowledged['isError']);
        self::assertSame('acknowledged', $acknowledged['payload']['status']);

        $resolved = $this->tool('doska_resolve', ['id' => $note['id'], 'summary' => 'Increased the heading size.']);
        self::assertFalse($resolved['isError']);
        self::assertSame('resolved', $resolved['payload']['status']);
        self::assertSame('Increased the heading size.', $resolved['payload']['replies'][0]['text']);

        $again = $this->tool('doska_dismiss', ['id' => $note['id'], 'reason' => 'Already done.']);
        self::assertTrue($again['isError']);
        self::assertStringContainsString('cannot change', $again['payload']['error']);

        $left = $this->tool('doska_get_pending', []);
        self::assertSame(0, $left['payload']['count']);
    }

    public function testInitializeNamesAStatelessServer(): void
    {
        $decoded = $this->decode($this->mcp->__invoke($this->rpc('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '0'],
        ])));

        self::assertSame('doska', $decoded['body']['result']['serverInfo']['name']);
        self::assertSame('2025-03-26', $decoded['body']['result']['protocolVersion']);
        self::assertStringContainsString('stateless', $decoded['body']['result']['instructions']);
    }

    public function testASessionClientIsToldToUseStatelessHttp(): void
    {
        $get = $this->decode($this->mcp->__invoke(Request::create(
            '/_doska/mcp',
            'GET',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
        )));
        self::assertSame(405, $get['status']);
        self::assertStringContainsString('stateless', $get['body']['error']['message']);

        $stream = $this->rpc('initialize');
        $stream->headers->set('Accept', 'text/event-stream');
        $accepted = $this->decode($this->mcp->__invoke($stream));
        self::assertSame(406, $accepted['status']);
        self::assertStringContainsString('application/json', $accepted['body']['error']['message']);

        $watch = $this->tool('doska_watch_notes', []);
        self::assertTrue($watch['isError']);
        self::assertStringContainsString('doska_get_pending', $watch['payload']['error']);
    }

    public function testANotificationHasNoBody(): void
    {
        $request = Request::create(
            '/_doska/mcp',
            'POST',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
            content: '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        );
        $response = $this->mcp->__invoke($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testARemoteClientIsRefused(): void
    {
        $request = $this->rpc('tools/list', address: '203.0.113.8');
        $decoded = $this->decode($this->mcp->__invoke($request));

        self::assertSame(403, $decoded['status']);
        self::assertStringContainsString('loopback', $decoded['body']['error']['message']);
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function rpc(string $method, ?array $params = null, string $address = '127.0.0.1'): Request
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method];
        if (null !== $params) {
            $payload['params'] = $params;
        }

        return Request::create(
            '/_doska/mcp',
            'POST',
            server: ['REMOTE_ADDR' => $address],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, payload: array<string, mixed>}
     */
    private function tool(string $name, array $arguments): array
    {
        $decoded = $this->decode($this->mcp->__invoke($this->rpc('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ])));
        self::assertSame(200, $decoded['status']);
        $result = $decoded['body']['result'];
        $payload = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return ['isError' => $result['isError'], 'payload' => $payload];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
