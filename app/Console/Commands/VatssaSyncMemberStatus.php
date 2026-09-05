<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Vatssa\MemberStatus;
use Illuminate\Console\Command;

/**
 * VATSSA: notice when a member's standing has changed, and write the date down.
 *
 * ## Why this is a job rather than a page load
 *
 * The standing itself is derived and needs no job -- any page can work it out
 * from the division field, the roster and the transfer/visit system. What a
 * derivation cannot produce is the DATE it changed, and that has to be noticed
 * by something running whether or not anybody opens the profile.
 *
 * Doing it on profile view instead would have meant a member nobody looks at
 * has no history, and two staff opening the same profile at once racing to
 * write the same row.
 *
 * ## Not to be confused with sync:roster
 *
 * That one pushes the roster to the Division API, which for VATSSA resolves to
 * NoOpAdapter and does nothing -- we are a division, so there is nobody above
 * us to push to. "Roster" here means what it means to a member: are they an
 * approved controller.
 */
class VatssaSyncMemberStatus extends Command
{
    protected $signature = 'vatssa:sync:member-status';

    protected $description = 'Record changes in divisional standing and roster status';

    public function handle(MemberStatus $status): int
    {
        $written = 0;
        $seen = 0;

        // Chunked, because this is every member in the division and the
        // endorsements each one loads are what make isAtcActive() answerable.
        User::with(['endorsements', 'atcActivity'])
            ->chunkById(200, function ($users) use ($status, &$written, &$seen) {
                foreach ($users as $user) {
                    $written += $status->sync($user);
                    $seen++;
                }
            });

        // A quiet night writes nothing, and that is the normal result. Saying
        // so plainly stops the zero looking like a failure in a cron log.
        $this->info("Checked {$seen} members, recorded {$written} change(s).");

        return Command::SUCCESS;
    }
}
