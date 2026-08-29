<?php

namespace App\Console\Commands;

use anlutro\LaravelSettings\Facade as Setting;
use App\Models\AtcActivity;
use App\Models\Vatssa\RosterWarning;
use App\Notifications\Vatssa\RosterExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * VATSSA: tell somebody a week before they lose their roster place.
 *
 * Upstream already warns about inactivity, but it is not a countdown. Its
 * warning fires when hours fall below a threshold AND the grace period is
 * two-thirds elapsed -- which, on a twelve-month grace, is about four months
 * out -- and repeats monthly until either the hours recover or the place is
 * gone. Nobody is ever told "this is your last week".
 *
 * That final week is the one that changes behaviour. Four months out, a
 * controller means to fix it and forgets; seven days out, they book a session.
 *
 * ## What "losing roster" means here
 *
 * ALL areas, not one. VATSSA's rule is that active in one area is active
 * everywhere, so warning about a single area would be alarming and wrong. This
 * only fires when every one of somebody's areas is about to lapse.
 *
 * ## Sent once
 *
 * A row in `vatssa_roster_warnings` records that it went, so the daily run does
 * not send it seven times. Cleared when they become active again, so the next
 * cycle can warn afresh.
 */
class VatssaRosterExpiryWarning extends Command
{
    protected $signature = 'vatssa:roster-expiry-warning {--days=7}';

    protected $description = 'Warn controllers a week before their roster place lapses';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $graceMonths = (int) Setting::get('atcActivityGracePeriod', 12);
        $requirement = (float) Setting::get('atcActivityRequirement', 10);

        // The grace period ends `graceMonths` after it started. Somebody is in
        // their final week when that end date falls inside the window.
        $windowOpens = now()->subMonths($graceMonths);
        $windowCloses = now()->subMonths($graceMonths)->addDays($days);

        $atRisk = AtcActivity::where('atc_active', true)
            ->whereNotNull('start_of_grace_period')
            ->whereBetween('start_of_grace_period', [$windowOpens, $windowCloses])
            ->where('hours', '<', $requirement)
            ->with('user')
            ->get()
            // Every area, not one. Active anywhere is active everywhere here, so
            // a warning about a single area would be both alarming and untrue.
            ->groupBy('user_id')
            ->filter(function ($areas, $userId) use ($requirement) {
                $all = AtcActivity::where('user_id', $userId)->where('atc_active', true)->get();

                return $all->count() === $areas->count()
                    && $all->every(fn ($a) => $a->hours < $requirement);
            });

        $sent = 0;

        foreach ($atRisk as $userId => $areas) {
            $user = $areas->first()->user;
            if ($user === null) {
                continue;
            }

            if (RosterWarning::alreadyWarned($userId)) {
                continue;
            }

            $expiresOn = $areas->first()->start_of_grace_period->copy()->addMonths($graceMonths);

            if (! $this->option('no-interaction')) {
                $this->line("Warning {$user->name} ({$userId}) — lapses {$expiresOn->toDateString()}");
            }

            Notification::send($user, new RosterExpiringNotification(
                $expiresOn,
                $areas->first()->hours,
                $requirement
            ));

            RosterWarning::record($userId, $expiresOn);
            $sent++;
        }

        $this->info("Roster expiry warnings sent: {$sent}");

        return Command::SUCCESS;
    }
}
