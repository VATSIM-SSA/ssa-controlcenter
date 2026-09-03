<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: why somebody went into Terminal.
 *
 * A fixed list, not free text, and that is the whole point of the column. CERT
 * access has to be justifiable after the fact, and "because I needed to" is not
 * a justification. Five options is short enough that picking the right one is
 * easier than typing the wrong one.
 *
 * The five are the ones the Query Log sheet actually contains, so this is a
 * record of what VATSSA does rather than a guess at what it might.
 */
enum TerminalLogReason: string
{
    case RATING_UPDATE = 'rating-update';

    case TVCP_CHECK = 'tvcp-check';

    case STAFF_CHECK = 'staff-check';

    case TRANSFER = 'transfer';

    case DUPE_CHECK = 'dupe-check';

    public function label(): string
    {
        return match ($this) {
            self::RATING_UPDATE => 'Rating update',
            self::TVCP_CHECK => 'TVCP check',
            self::STAFF_CHECK => 'Staff check',
            self::TRANSFER => 'Transfer',
            self::DUPE_CHECK => 'Duplicate check',
        };
    }
}
