<?php

namespace App\Helpers;

/**
 * The version of a feedback the controller is sent. A controller holds at
 * most one at a time; changing it means withdrawing it and sending again.
 */
enum FeedbackDelivery: string
{
    /** The submitter's own words, without their name. */
    case ORIGINAL = 'original';

    /** Staff's own version, for example a respectful rewording. */
    case REWRITTEN = 'rewritten';

    public function label(): string
    {
        return match ($this) {
            self::ORIGINAL => 'As written',
            self::REWRITTEN => 'Rewritten version',
        };
    }
}
