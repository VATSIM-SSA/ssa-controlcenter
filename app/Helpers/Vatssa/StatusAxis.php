<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: the two questions a member's standing answers.
 *
 * Kept as an enum rather than two bare strings because both the history table
 * and the sync write the value, and a typo in one of them would silently split
 * a member's history into two halves that never appear together.
 */
enum StatusAxis: string
{
    /** Where the member belongs: home, international, visiting, transferring. */
    case RELATIONSHIP = 'relationship';

    /** Whether they may control: approved controller, or not. */
    case ROSTER = 'roster';

    public function label(): string
    {
        return match ($this) {
            self::RELATIONSHIP => 'Divisional standing',
            self::ROSTER => 'Roster',
        };
    }
}
