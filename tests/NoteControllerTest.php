<?php

declare(strict_types=1);

namespace Doska\Tests;

use Doska\Controller\NoteController;
use Doska\Notes\NoteStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NoteControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/doska-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
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

    public function testALocalPostStoresThePinnedElement(): void
    {
        $response = $this->controller()->create($this->request([
            'text' => ' Make this heading larger ',
            'url' => 'https://drehscheibe.test/login',
            'controller' => 'App\\Controller\\SecurityController::login',
            'source' => 'src/Controller/SecurityController.php:20',
            'route' => 'login',
            'ignored' => 'drop me',
            'elements' => [[
                'tag' => 'h3',
                'selector' => 'h3',
                'file' => 'templates/public/login.html.twig',
                'line' => 13,
                'text' => 'Anmelden',
                'stimulus' => ['controllers' => 'login', 'element' => 'form#login', 'values' => ['url']],
                'live' => ['name' => 'Login', 'props' => '{"ok":true}'],
                'turbo' => ['id' => 'modal', 'src' => '/login'],
                'stack' => [[
                    'name' => 'Button',
                    'file' => 'templates/components/Button.html.twig',
                    'line' => 1,
                    'scope' => 'app',
                    'extra' => null,
                    'caller' => ['file' => 'templates/public/login.html.twig', 'line' => 12, 'scope' => 'app'],
                ]],
            ]],
        ]));

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $body['status']);

        $note = (new NoteStore($this->dir))->get($body['id']);
        self::assertSame('Make this heading larger', $note['text']);
        self::assertArrayNotHasKey('ignored', $note);
        self::assertSame('templates/public/login.html.twig:13', $note['elements'][0]['location']);
        self::assertSame('login', $note['elements'][0]['stimulus']['controllers']);
        self::assertSame('Login', $note['elements'][0]['live']['name']);
        self::assertSame('modal', $note['elements'][0]['turbo']['id']);
        self::assertSame('templates/public/login.html.twig', $note['elements'][0]['stack'][0]['caller']['file']);
    }

    public function testTheListKeepsClosedNotes(): void
    {
        $created = $this->controller()->create($this->request(['text' => 'Make the heading larger']));
        $id = json_decode((string) $created->getContent(), true, 8, JSON_THROW_ON_ERROR)['id'];
        $store = new NoteStore($this->dir);
        $store->acknowledge($id);
        $store->resolve($id, 'Increased the heading size.');

        $response = $this->controller()->index(Request::create('/_doska/notes', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertSame(200, $response->getStatusCode());
        $notes = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR)['notes'];
        self::assertSame('resolved', $notes[0]['status']);
        self::assertSame('Increased the heading size.', $notes[0]['replies'][0]['text']);
    }

    public function testARemoteListIsRefused(): void
    {
        $request = Request::create('/_doska/notes', 'GET', server: ['REMOTE_ADDR' => '203.0.113.8']);

        self::assertSame(403, $this->controller()->index($request)->getStatusCode());
        self::assertSame(200, $this->controller(true)->index($request)->getStatusCode());
    }

    public function testDeleteRemovesTheNote(): void
    {
        $created = $this->controller()->create($this->request(['text' => 'Remove me']));
        $id = json_decode((string) $created->getContent(), true, 8, JSON_THROW_ON_ERROR)['id'];

        $response = $this->controller()->delete($this->deleteRequest($id), $id);

        self::assertSame(204, $response->getStatusCode());
        $list = $this->controller()->index(Request::create('/_doska/notes', 'GET', server: ['REMOTE_ADDR' => '127.0.0.1']));
        $notes = json_decode((string) $list->getContent(), true, 8, JSON_THROW_ON_ERROR)['notes'];
        self::assertSame([], $notes);
    }

    public function testDeleteOfAMissingNoteIsNotFound(): void
    {
        $id = '0123456789abcdef';
        $response = $this->controller()->delete($this->deleteRequest($id), $id);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testARemoteDeleteIsRefused(): void
    {
        $id = '0123456789abcdef';
        $request = $this->deleteRequest($id, '203.0.113.8');

        self::assertSame(403, $this->controller()->delete($request, $id)->getStatusCode());
        self::assertSame(404, $this->controller(true)->delete($request, $id)->getStatusCode());
    }

    public function testAnEmptyNoteIsRejected(): void
    {
        $response = $this->controller()->create($this->request(['text' => '   ', 'elements' => []]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $request = Request::create('/_doska/notes', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1'], content: '[');
        $response = $this->controller()->create($request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testARemoteClientIsRefusedUnlessRemoteAccessIsAllowed(): void
    {
        $request = $this->request(['text' => 'hello'], '203.0.113.8');

        self::assertSame(403, $this->controller()->create($request)->getStatusCode());
        self::assertSame(201, $this->controller(true)->create($request)->getStatusCode());
    }

    public function testIpv6LoopbackIsAllowed(): void
    {
        $response = $this->controller()->create($this->request(['text' => 'hello'], '::1'));

        self::assertSame(201, $response->getStatusCode());
    }

    private function controller(bool $allowRemoteAccess = false): NoteController
    {
        return new NoteController(new NoteStore($this->dir), $allowRemoteAccess);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function deleteRequest(string $id, string $address = '127.0.0.1'): Request
    {
        return Request::create('/_doska/notes/'.$id, 'DELETE', server: ['REMOTE_ADDR' => $address]);
    }

    private function request(array $body, string $address = '127.0.0.1'): Request
    {
        return Request::create(
            '/_doska/notes',
            'POST',
            server: ['REMOTE_ADDR' => $address],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
