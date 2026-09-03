<?php

namespace App\Services\Vatssa;

/**
 * VATSSA: one line of a requirement list — a tick or a cross, and what to do.
 *
 * Deliberately dumb. It carries no logic and knows nothing about who is being
 * checked; MembershipCheck builds these and the views render them, which is
 * what lets the same list appear on the dashboard, the application page and a
 * membership request without three renderings that drift apart.
 *
 * `$blocking` is the difference between "you cannot apply" and "staff will look
 * at this". A rule that blocks silently is a rule nobody can appeal, so most of
 * them do not block: they show as a cross and let the person deciding decide.
 */
final class Requirement
{
    public function __construct(
        /** What the rule is, in the reader's words. */
        public readonly string $label,
        /** Whether this person meets it. */
        public readonly bool $met,
        /**
         * What to do about it, or the value behind the answer.
         *
         * Written as an instruction rather than a verdict: "join the VATSSA
         * Discord server" rather than "you are not on Discord".
         */
        public readonly ?string $detail = null,
        /** Whether failing this actually stops the application. */
        public readonly bool $blocking = false,
        /**
         * True when we have not been able to check, as opposed to having
         * checked and found nothing.
         *
         * The gate treats both as unmet; the MESSAGE must not. Telling somebody
         * who joined Discord an hour ago that they are not on Discord is how a
         * correct rule gets a reputation for being broken.
         */
        public readonly bool $unknown = false,
    ) {}

    public function icon(): string
    {
        return match (true) {
            $this->met => 'fas fa-check text-success',
            $this->unknown => 'fas fa-clock text-muted',
            $this->blocking => 'fas fa-times text-danger',
            default => 'fas fa-times text-warning',
        };
    }
}
