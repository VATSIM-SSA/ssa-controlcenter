<?php

namespace App\Helpers\Vatssa;

/**
 * VATSSA: what a member is to the division.
 *
 * One of the two axes of a member's standing. The other is the roster -- are
 * they an approved controller -- and the two are answered separately, because a
 * member has an answer to both at once. A visiting controller holding
 * approved-controller permissions is ordinary, not a contradiction, and a
 * single five-value field could not have expressed it.
 *
 * Every value is derived. Where an exception is needed it is made in the system
 * that owns the fact -- the transfer/visit system -- never on the profile.
 */
enum DivisionalRelationship: string
{
    /** VATSIM division is ours. No exceptions, no judgement. */
    case HOME = 'home';

    /**
     * Division is not ours, and nothing is in progress.
     *
     * The default for the rest of VATSIM, so most of the network is this and
     * the profile says so without it meaning anything is wrong.
     */
    case INTERNATIONAL = 'international';

    /** An outside member with a visiting request. */
    case VISITING = 'visiting';

    /** An outside member with a transfer request. */
    case TRANSFERRING = 'transferring';

    public function label(): string
    {
        return match ($this) {
            self::HOME => 'Home member',
            self::INTERNATIONAL => 'International',
            self::VISITING => 'Visitor',
            self::TRANSFERRING => 'Transferring',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::HOME => 'success',
            self::INTERNATIONAL => 'secondary',
            self::VISITING => 'info',
            self::TRANSFERRING => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::HOME => 'fa-house',
            self::INTERNATIONAL => 'fa-earth-africa',
            self::VISITING => 'fa-plane-arrival',
            self::TRANSFERRING => 'fa-right-left',
        };
    }

    /**
     * What this means, for somebody reading a profile who does not already
     * know the rule.
     */
    public function description(): string
    {
        return match ($this) {
            self::HOME => 'Their VATSIM division is ours.',
            self::INTERNATIONAL => 'Their VATSIM division is elsewhere, with nothing in progress here.',
            self::VISITING => 'Controlling here on a visiting request, division still elsewhere.',
            self::TRANSFERRING => 'Moving their division to ours. Home once the transfer completes.',
        };
    }

    /**
     * Whether this is a state somebody is passing THROUGH.
     *
     * Visiting and transferring both end -- one on the roster and still
     * international, the other as a home member. Home and international are
     * where people rest.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::VISITING, self::TRANSFERRING], true);
    }
}
