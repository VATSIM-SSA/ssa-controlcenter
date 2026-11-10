<?php

namespace App\Helpers;

/**
 * Where a feedback entry is in staff review. Open entries wait in the queue;
 * closed ones are dealt with. Whether the controller received anything is
 * tracked separately (see Feedback::wasSent()), so reopening never changes it.
 */
enum FeedbackStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::CLOSED => 'secondary',
        };
    }
}
