<?php

declare(strict_types=1);

namespace Doska\Notes;

enum NoteStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return self::Pending === $this || self::Acknowledged === $this;
    }
}
