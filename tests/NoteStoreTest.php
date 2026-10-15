<?php

declare(strict_types=1);

namespace Doska\Tests;

use Doska\Notes\NoteException;
use Doska\Notes\NoteStatus;
use Doska\Notes\NoteStore;
use PHPUnit\Framework\TestCase;

final class NoteStoreTest extends TestCase
{
    private string $dir;

    private NoteStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/doska-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->store = new NoteStore($this->dir);
    }

    protected function tearDown(): void
    {
        $paths = [
            $this->dir.'/child.php',
            $this->dir.'/var/doska/notes.json',
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        @rmdir($this->dir.'/var/doska');
        @rmdir($this->dir.'/var');
        @rmdir($this->dir);
    }

    public function testPendingNotesStayOldestFirstAndClosedNotesStayInTheFile(): void
    {
        $first = $this->store->add($this->payload('first'));
        $second = $this->store->add($this->payload('second'));
        $this->store->acknowledge($first['id']);
        $this->store->resolve($first['id'], 'Made the heading larger.');

        $pending = $this->store->pending();
        self::assertSame(['second'], array_column($pending, 'text'));

        $closed = $this->store->get($first['id']);
        self::assertSame(NoteStatus::Resolved->value, $closed['status']);
        self::assertSame('Made the heading larger.', $closed['replies'][0]['text']);

        $again = new NoteStore($this->dir);
        self::assertSame($second['id'], $again->pending()[0]['id']);
        self::assertSame(NoteStatus::Resolved->value, $again->get($first['id'])['status']);
    }

    public function testAPendingNoteCannotBeResolvedUntilItIsAcknowledged(): void
    {
        $note = $this->store->add($this->payload('fix the label'));

        $this->expectException(NoteException::class);
        $this->expectExceptionMessage('acknowledged');
        $this->store->resolve($note['id'], 'Done.');
    }

    public function testResolvedAndDismissedNotesCannotChange(): void
    {
        $resolved = $this->store->add($this->payload('resolved'));
        $this->store->acknowledge($resolved['id']);
        $this->store->resolve($resolved['id'], 'Done.');

        $dismissed = $this->store->add($this->payload('dismissed'));
        $this->store->dismiss($dismissed['id'], 'Out of scope.');

        foreach ([$resolved['id'], $dismissed['id']] as $id) {
            try {
                $this->store->acknowledge($id);
                self::fail('A closed note was acknowledged.');
            } catch (NoteException $exception) {
                self::assertStringContainsString('cannot change', $exception->getMessage());
            }
            try {
                $this->store->reply($id, 'More?');
                self::fail('A closed note accepted a reply.');
            } catch (NoteException $exception) {
                self::assertStringContainsString('cannot change', $exception->getMessage());
            }
        }

        self::assertSame('Dismissed: Out of scope.', $this->store->get($dismissed['id'])['replies'][0]['text']);
    }

    public function testReplyKeepsAnOpenNoteOpen(): void
    {
        $note = $this->store->add($this->payload('question'));
        $replied = $this->store->reply($note['id'], 'Which heading?');

        self::assertSame(NoteStatus::Pending->value, $replied['status']);
        self::assertSame('Which heading?', $replied['replies'][0]['text']);
        self::assertCount(1, $this->store->pending());
    }

    public function testDeleteRemovesANoteOfAnyStatus(): void
    {
        $open = $this->store->add($this->payload('keep'));
        $gone = $this->store->add($this->payload('remove'));
        $this->store->acknowledge($gone['id']);
        $this->store->resolve($gone['id'], 'Done.');

        $this->store->delete($gone['id']);

        self::assertSame([$open['id']], array_column($this->store->all(), 'id'));
        $this->expectException(NoteException::class);
        $this->expectExceptionMessage('Note not found');
        $this->store->delete($gone['id']);
    }

    public function testAcknowledgeIsOnlyValidOnce(): void
    {
        $note = $this->store->add($this->payload('once'));
        $this->store->acknowledge($note['id']);

        $this->expectException(NoteException::class);
        $this->expectExceptionMessage('already acknowledged');
        $this->store->acknowledge($note['id']);
    }

    public function testAMissingNoteAndAnEmptySummaryFail(): void
    {
        $note = $this->store->add($this->payload('needs a summary'));
        $this->store->acknowledge($note['id']);

        try {
            $this->store->resolve($note['id'], '   ');
            self::fail('An empty summary was accepted.');
        } catch (NoteException $exception) {
            self::assertSame('summary is required.', $exception->getMessage());
        }

        $this->expectException(NoteException::class);
        $this->expectExceptionMessage('Note not found');
        $this->store->get('missing');
    }

    public function testACorruptFileIsReplacedOnTheNextWrite(): void
    {
        $directory = $this->dir.'/var/doska';
        mkdir($directory, 0775, true);
        file_put_contents($directory.'/notes.json', '{');

        $note = $this->store->add($this->payload('recovered'));
        self::assertSame([$note['id']], array_column($this->store->pending(), 'id'));
        $raw = (string) file_get_contents($directory.'/notes.json');
        self::assertNotFalse(json_decode($raw, true));
    }

    public function testASecondProcessWaitsForTheFileLock(): void
    {
        $this->store->add($this->payload('first'));
        $handle = fopen($this->dir.'/var/doska/notes.json', 'c+');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX));

        $script = $this->dir.'/child.php';
        file_put_contents($script, sprintf(
            <<<'PHP'
            <?php
            require %s;
            $store = new Doska\Notes\NoteStore(%s);
            $store->add([
                'text' => 'second',
                'url' => null,
                'controller' => null,
                'source' => null,
                'route' => null,
                'elements' => [],
            ]);
            echo 'done';
            PHP,
            var_export(dirname(__DIR__).'/vendor/autoload.php', true),
            var_export($this->dir, true),
        ));

        $pipes = [];
        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $failed = null;
        try {
            $status = ['running' => false];
            $deadline = microtime(true) + 1.0;
            do {
                usleep(50_000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $deadline);
            self::assertTrue($status['running'], 'The second write should wait while the note file is locked.');
        } catch (\Throwable $exception) {
            $failed = $exception;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (null !== $failed) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw $failed;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr.$stdout);
        self::assertSame('done', $stdout);
        self::assertSame(['first', 'second'], array_column($this->store->pending(), 'text'));
    }

    /**
     * @param list<array<string, mixed>> $elements
     *
     * @return array{
     *     text: string,
     *     url: ?string,
     *     controller: ?string,
     *     source: ?string,
     *     route: ?string,
     *     elements: list<array<string, mixed>>
     * }
     */
    private function payload(string $text, array $elements = []): array
    {
        return [
            'text' => $text,
            'url' => 'https://drehscheibe.test/login',
            'controller' => 'App\\Controller\\SecurityController::login',
            'source' => 'src/Controller/SecurityController.php:20',
            'route' => 'login',
            'elements' => $elements,
        ];
    }
}
