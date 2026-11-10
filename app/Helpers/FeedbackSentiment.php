<?php

namespace App\Helpers;

/**
 * Staff's reading of a feedback entry's tone, set when it is actioned.
 *
 * Kept apart from the status so a negative entry can still be forwarded when
 * it is constructive, and so integrations can, for example, share only
 * positive forwarded feedback.
 */
enum FeedbackSentiment: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';

    public function label(): string
    {
        return match ($this) {
            self::POSITIVE => 'Positive',
            self::NEGATIVE => 'Negative',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::POSITIVE => 'success',
            self::NEGATIVE => 'danger',
        };
    }
}
