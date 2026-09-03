<?php

namespace App\Helpers;

/**
 * How staff read a piece of feedback: positive or negative.
 *
 * Deliberately separate from FeedbackStatus, because they answer different
 * questions. The status is what we DID with it; the sentiment is what it WAS.
 * Negative feedback is often worth forwarding — that is how somebody improves —
 * and positive feedback is sometimes not, so tying the two together would force
 * a choice the division should be free to make either way.
 *
 * ## What it is for
 *
 * Two things, and neither is a filing habit. It gives a division statistics
 * worth having over a season, and it gives an integration something safe to key
 * on: a bot that reposts compliments to Discord needs to know a piece of
 * feedback is both FORWARDED and POSITIVE before it publishes anything.
 *
 * ## Why it is nullable on the model
 *
 * It is set by staff when they action feedback, not by the submitter. Open
 * feedback has no sentiment because nobody has read it yet, and guessing one
 * from the text would be a machine judgement standing in for a human one.
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

    /** Bootstrap contextual colour for badges. */
    public function color(): string
    {
        return match ($this) {
            self::POSITIVE => 'success',
            self::NEGATIVE => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::POSITIVE => 'fa-thumbs-up',
            self::NEGATIVE => 'fa-thumbs-down',
        };
    }
}
