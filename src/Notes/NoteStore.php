<?php

declare(strict_types=1);

namespace Doska\Notes;

/**
 * Notes live in {project}/var/doska/notes.json.
 *
 * A static array would vanish between php-fpm workers. The file is locked
 * for each read-modify-write so two requests cannot drop each other's notes.
 */
final class NoteStore
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $relativePath = 'var/doska/notes.json',
    ) {
    }

    /**
     * @param array{
     *     text: string,
     *     url: ?string,
     *     controller: ?string,
     *     source: ?string,
     *     route: ?string,
     *     mode: ?string,
     *     elements: list<array<string, mixed>>
     * } $input
     *
     * @return array<string, mixed>
     */
    public function add(array $input): array
    {
        $note = [
            'id' => bin2hex(random_bytes(8)),
            'status' => NoteStatus::Pending->value,
            'text' => $input['text'],
            'url' => $input['url'],
            'controller' => $input['controller'],
            'source' => $input['source'],
            'route' => $input['route'],
            'mode' => $input['mode'] ?? null,
            'elements' => $input['elements'],
            'replies' => [],
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        return $this->withLock(function (array $notes) use ($note): array {
            $notes[] = $note;

            return [$notes, $note];
        }, true);
    }

    /**
     * Every note, oldest first. The toolbar reverses this for its list.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * Remove one note of any status. A missing id fails.
     */
    public function delete(string $id): void
    {
        $this->withLock(function (array $notes) use ($id): array {
            foreach ($notes as $index => $note) {
                if (($note['id'] ?? '') !== $id) {
                    continue;
                }
                array_splice($notes, $index, 1);

                return [$notes, null];
            }

            throw new NoteException('Note not found: '.$id);
        }, true);
    }

    /**
     * Open notes, oldest first. Resolved and dismissed notes stay in the file.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $open = [];
        foreach ($this->read() as $note) {
            $status = NoteStatus::tryFrom((string) ($note['status'] ?? ''));
            if (null !== $status && $status->isOpen()) {
                $open[] = $note;
            }
        }

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        foreach ($this->read() as $note) {
            if (($note['id'] ?? '') === $id) {
                return $note;
            }
        }

        throw new NoteException('Note not found: '.$id);
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(string $id): array
    {
        return $this->transition($id, function (array $note): array {
            $status = $this->status($note);
            if (NoteStatus::Pending !== $status) {
                throw new NoteException($this->blocked($status));
            }
            $note['status'] = NoteStatus::Acknowledged->value;

            return $note;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $id, string $summary): array
    {
        $summary = trim($summary);
        if ('' === $summary) {
            throw new NoteException('summary is required.');
        }

        return $this->transition($id, function (array $note) use ($summary): array {
            $status = $this->status($note);
            if (NoteStatus::Pending === $status) {
                throw new NoteException('A pending note must be acknowledged before it can be resolved.');
            }
            if (NoteStatus::Acknowledged !== $status) {
                throw new NoteException($this->blocked($status));
            }
            $note['status'] = NoteStatus::Resolved->value;
            $note['replies'][] = $this->message($summary);

            return $note;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function dismiss(string $id, string $reason): array
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new NoteException('reason is required.');
        }

        return $this->transition($id, function (array $note) use ($reason): array {
            if (!$this->status($note)->isOpen()) {
                throw new NoteException($this->blocked($this->status($note)));
            }
            $note['status'] = NoteStatus::Dismissed->value;
            $note['replies'][] = $this->message('Dismissed: '.$reason);

            return $note;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(string $id, string $message): array
    {
        $message = trim($message);
        if ('' === $message) {
            throw new NoteException('message is required.');
        }

        return $this->transition($id, function (array $note) use ($message): array {
            if (!$this->status($note)->isOpen()) {
                throw new NoteException($this->blocked($this->status($note)));
            }
            $note['replies'][] = $this->message($message);

            return $note;
        });
    }

    /**
     * @param array<string, mixed> $note
     */
    private function status(array $note): NoteStatus
    {
        return NoteStatus::tryFrom((string) ($note['status'] ?? '')) ?? NoteStatus::Pending;
    }

    private function blocked(NoteStatus $status): string
    {
        return match ($status) {
            NoteStatus::Resolved => 'A resolved note cannot change.',
            NoteStatus::Dismissed => 'A dismissed note cannot change.',
            NoteStatus::Acknowledged => 'This note is already acknowledged.',
            NoteStatus::Pending => 'A pending note must be acknowledged before it can be resolved.',
        };
    }

    /**
     * @return array{role: string, text: string, at: string}
     */
    private function message(string $text): array
    {
        return [
            'role' => 'agent',
            'text' => $text,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function transition(string $id, callable $change): array
    {
        return $this->withLock(function (array $notes) use ($id, $change): array {
            foreach ($notes as $index => $note) {
                if (($note['id'] ?? '') !== $id) {
                    continue;
                }
                $notes[$index] = $change($note);

                return [$notes, $notes[$index]];
            }

            throw new NoteException('Note not found: '.$id);
        }, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(): array
    {
        if (!is_file($this->path())) {
            return [];
        }

        return $this->withLock(static fn (array $notes): array => [$notes, $notes], false);
    }

    /**
     * @param callable(list<array<string, mixed>>): array{0: list<array<string, mixed>>, 1: mixed} $fn
     */
    private function withLock(callable $fn, bool $write): mixed
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the Doska note directory.');
        }

        $handle = fopen($path, 'c+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Doska note file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the Doska note file.');
            }

            $raw = stream_get_contents($handle);
            $notes = $this->decode(is_string($raw) ? $raw : '');
            [$notes, $result] = $fn($notes);
            if ($write) {
                $json = json_encode(
                    ['notes' => array_values($notes)],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
                rewind($handle);
                ftruncate($handle, 0);
                rewind($handle);
                if (false === fwrite($handle, $json."\n")) {
                    throw new \RuntimeException('Cannot write the Doska note file.');
                }
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || !isset($decoded['notes']) || !is_array($decoded['notes'])) {
            return [];
        }

        $notes = [];
        foreach ($decoded['notes'] as $note) {
            if (is_array($note)) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    private function path(): string
    {
        return rtrim($this->projectDir, '/').'/'.ltrim($this->relativePath, '/');
    }
}
