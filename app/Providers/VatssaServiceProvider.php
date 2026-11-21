<?php

namespace App\Providers;

use App\Http\Controllers\TrainingActivityController;
use App\Http\Controllers\VatssaDiscordSyncController;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\RoleAssignment;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupMember;
use App\Services\VatssaTrainingFlags;
use App\Services\VatssaVatsimFacts;
use App\Tasks\Types\TheoreticalExam;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * VATSSA: behaviour added on top of upstream without editing upstream files.
 *
 * Everything here is an addition. See PATCHES.md, "VatssaServiceProvider".
 */
class VatssaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The training activity feed rejects types it does not know.
        TrainingActivityController::$activityTypes['THEORY'] = true;

        // Upstream's student "Mark pre-training as completed" is off. It gates
        // nothing and read as a theory pass. A before-callback overrides the
        // upstream policy method without editing it, admins included.
        Gate::before(function (User $user, string $ability) {
            return $ability === 'togglePreTrainingCompleted' ? false : null;
        });

        // Staff with training.update in the training's area mark the theory as
        // passed: Pipeline Coordinator, ATC Training Manager, admin. Not mentors,
        // not students. Open trainings only.
        Gate::define('toggleTheoryCompleted', function (User $user, Training $training) {
            return $training->status->isOpen() && $user->hasPermission('training.update', $training->area);
        });

        $this->registerTrainingFlags();

        // "Theoretical Exam Access" requests are not used: VATSSA's theory exam
        // runs in Moodle. Hidden from the training page's Request menu and
        // refused on create. The task type class stays, so tasks created before
        // still render.
        View::composer('training.show', function ($view) {
            $types = $view->getData()['requestTypes'] ?? null;
            if (is_array($types)) {
                $view->with('requestTypes', array_values(array_filter(
                    $types,
                    fn ($type) => ! $type instanceof TheoreticalExam,
                )));
            }
        });

        Task::creating(function (Task $task) {
            if ($task->type === TheoreticalExam::class) {
                throw ValidationException::withMessages(['type' => 'Theoretical exam access requests are not used in VATSSA.']);
            }
        });

        // "Sync Discord roles" on a profile: users.manage (admin, ATC Training
        // Manager, Pipeline Coordinator) for anyone; a mentor only for a member
        // they teach, the same people UserPolicy::view lets them see.
        Gate::define('vatssaDiscordSync', function (User $user, User $target) {
            return $user->hasPermission('users.manage')
                || ($user->hasPermission('training.mentor') && $user->isTeaching($target));
        });

        $this->registerDiscordSyncListeners();

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'activity', 'suspended'])
                ->group(base_path('routes/vatssa.php'));
            // Not the 'api' group: its throttle:60,1 counter is shared with /api/users,
            // and one batch of instant-sync results is up to 100 calls. Own counter.
            Route::prefix('api')->middleware(['throttle:600,1,vatssa-discord-bot', 'bindings'])
                ->group(base_path('routes/vatssa-api.php'));
        }
    }

    /**
     * Instant Discord role sync: when CC data that decides a role changes, queue
     * that member for the role sync bot (it polls every 5 seconds).
     *
     * A row moved to another member queues both. Training uses created + updated,
     * not saved: wasRecentlyCreated stays true for the whole request, so a later
     * save of a new training would queue again. Bulk query updates fire no events
     * (UpdateAtcActivity, UpdateAtcReactivate, UpdateMemberDetails,
     * Training::expireInterests); the bot's 15-minute sweep catches those. A
     * deleted or pseudonymised user loses their sync history and Discord link.
     */
    private function registerDiscordSyncListeners(): void
    {
        Event::listen(Login::class, function (Login $login) {
            if ($login->user instanceof User) {
                VatssaVatsimFacts::fromUser($login->user);
            }
            VatssaDiscordSyncController::queueAuto((int) $login->user->getAuthIdentifier(), 'login', true);
        });
        View::composer('vatssa.user.discord-sync', function ($view) {
            $target = $view->getData()['user'] ?? null;
            if ($target instanceof User && Auth::user()?->can('vatssaDiscordSync', $target)) {
                VatssaDiscordSyncController::queueOnView($target->id);
            }
        });
        // After upstream's member refresh (04:00), so a live login's data is this morning's.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('vatssa:vatsim-pull')->dailyAt('04:30');
            $schedule->command('vatssa:vatsim-pull --lookups')->everyMinute()->withoutOverlapping();
            $schedule->command('vatssa:discord-daily')->dailyAt('06:00');
        });

        $queue = function ($model): void {
            VatssaDiscordSyncController::queueAuto((int) $model->user_id);
            if ($model->wasChanged('user_id')) {
                VatssaDiscordSyncController::queueAuto((int) $model->getOriginal('user_id'));
            }
        };

        // Group membership: on the model, so every code path queues, not only the page.
        VatssaGroupMember::created($queue);
        VatssaGroupMember::updated(function (VatssaGroupMember $member) use ($queue) {
            if ($member->wasChanged(['removed_at', 'user_id'])) {
                $queue($member);
            }
        });
        VatssaGroupMember::deleted($queue);
        Endorsement::saved($queue);
        Endorsement::deleted($queue);
        RoleAssignment::saved($queue);
        RoleAssignment::deleted($queue);
        Training::created($queue);
        Training::updated(function (Training $training) use ($queue) {
            if ($training->wasChanged(['status', 'user_id'])) {
                $queue($training);
            }
        });
        Training::deleted($queue);
        AtcActivity::created($queue);
        AtcActivity::updated(function (AtcActivity $activity) use ($queue) {
            if ($activity->wasChanged(['atc_active', 'user_id'])) {
                $queue($activity);
            }
        });
        AtcActivity::deleted($queue);

        User::updated(function (User $user) {
            if ($user->wasChanged('email') && $user->email === VatssaDiscordSyncController::PSEUDONYMISED_EMAIL) {
                VatssaDiscordSyncController::forgetUser($user->id);
                VatssaGroup::forgetUser($user->id);
            } elseif ($user->wasChanged(['rating', 'division'])) {
                VatssaVatsimFacts::fromUser($user);
                VatssaDiscordSyncController::queueAuto($user->id);
            }
        });
        User::deleted(function (User $user) {
            VatssaDiscordSyncController::forgetUser($user->id);
            VatssaGroup::forgetUser($user->id);
        });
    }

    /**
     * Training flags (Requires attention, Stalled).
     *
     * The same people as the theory mark set and clear them: training.update
     * in the training's area, open trainings only. When a training closes, any
     * flag still set is cleared and the clearing is written to the timeline, so
     * a reopened training starts clean and a closed one never shows a flag
     * nobody can clear. A failure to clear is reported, never thrown: the
     * close itself (e-mail, endorsements, mentor detach) must always finish,
     * and a leftover flag on a closed training shows nowhere. See
     * VatssaTrainingFlags.
     */
    private function registerTrainingFlags(): void
    {
        Gate::define('toggleTrainingFlags', function (User $user, Training $training) {
            return $training->status->isOpen() && $user->hasPermission('training.update', $training->area);
        });

        Training::saved(function (Training $training) {
            if ($training->wasChanged('status') && ! $training->status->isOpen()) {
                try {
                    app(VatssaTrainingFlags::class)->clearOnClose($training);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
