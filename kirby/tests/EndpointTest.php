<?php

declare(strict_types=1);

namespace Doska\Kirby\Tests;

use Doska\Kirby\Endpoint;
use Doska\Mcp\ToolRunner;
use Doska\Notes\NoteStore;
use PHPUnit\Framework\TestCase;

final class EndpointTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/doska-kirby-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $file = $this->dir.'/notes.json';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testAContentNoteKeepsTheModeAndTheBlockAddress(): void
    {
        $notes = new NoteStore($this->dir, 'notes.json');
        $created = Endpoint::notes($notes, 'POST', json_encode([
            'text' => 'change the title to xyz',
            'url' => 'https://speedpepper.test/',
            'mode' => 'content',
            'elements' => [[
                'tag' => 'h2',
                'file' => 'site/snippets/blocks/sectionhero.php',
                'line' => 1,
                'stack' => [[
                    'name' => 'snippet:blocks/sectionhero',
                    'file' => 'site/snippets/blocks/sectionhero.php',
                    'line' => 1,
                    'scope' => 'app',
                    'extra' => 'content content/1_home/home.de.txt field blocks block abc type sectionhero',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(201, $created['status']);

        $list = Endpoint::notes($notes, 'GET', '');
        self::assertSame('content', $list['body']['notes'][0]['mode']);
        self::assertSame('abc', $this->extra($list['body']['notes'][0])['block'] ?? null);
    }

    public function testMcpListsThePendingNote(): void
    {
        $notes = new NoteStore($this->dir, 'notes.json');
        Endpoint::notes($notes, 'POST', '{"text":"tighten the hero"}');
        $tools = new ToolRunner($notes);
        $response = Endpoint::mcp($tools, 'POST', json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'doska_get_pending', 'arguments' => new \stdClass()],
        ], JSON_THROW_ON_ERROR), 'application/json');

        self::assertSame(200, $response['status']);
        $text = $response['body']['result']['content'][0]['text'];
        self::assertStringContainsString('tighten the hero', $text);
    }

    /**
     * @param array<string, mixed> $note
     *
     * @return array<string, string>
     */
    private function extra(array $note): array
    {
        $raw = $note['elements'][0]['stack'][0]['extra'] ?? '';
        $parts = explode(' ', (string) $raw);
        $mapped = [];
        for ($i = 0; $i < count($parts) - 1; $i += 2) {
            $mapped[$parts[$i]] = $parts[$i + 1];
        }

        return $mapped;
    }
}
