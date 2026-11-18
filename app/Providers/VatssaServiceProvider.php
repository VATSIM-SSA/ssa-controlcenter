<?php

namespace App\Providers;

use App\Http\Controllers\TrainingActivityController;
use App\Http\Controllers\VatssaDiscordSyncController;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\RoleAssignment;
use App\Http\Controllers\VatssaTrainingFlagController;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use App\Tasks\Types\TheoreticalExam;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

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

        // The same people set and clear the training flags (Requires
        // attention, Stalled).
        Gate::define('toggleTrainingFlags', function (User $user, Training $training) {
            return $training->status->isOpen() && $user->hasPermission('training.update', $training->area);
        });

        // A training that closes loses its flags, so a reopened one starts
        // clean and a closed one never shows a flag nobody can clear.
        Training::saving(function (Training $training) {
            if ($training->isDirty('status') && ! $training->status->isOpen()) {
                foreach (VatssaTrainingFlagController::FLAGS as $flag) {
                    $training->{$flag['column']} = null;
                }
            }
        });

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

        // "Sync Discord roles" on a profile: admin, ATC Training Manager and
        // Pipeline Coordinator (users.manage), and mentors in any area.
        Gate::define('vatssaDiscordSync', function (User $user) {
            return $user->hasPermission('users.manage') || $user->hasPermission('training.mentor');
        });

        // Instant Discord role sync: when CC data that decides a role changes,
        // queue that member for the role sync bot (it polls every 10 seconds).
        // Bulk query updates fire no events (UpdateAtcActivity, UpdateAtcReactivate,
        // UpdateMemberDetails, Training::expireInterests); the bot's 15-minute
        // sweep catches those.
        $queue = fn ($model) => VatssaDiscordSyncController::queueAuto((int) $model->user_id);
        Endorsement::saved($queue);
        Endorsement::deleted($queue);
        RoleAssignment::saved($queue);
        RoleAssignment::deleted($queue);
        // created + updated, not saved: wasRecentlyCreated stays true for the whole
        // request, so a later save of a new row would queue again.
        Training::created($queue);
        Training::updated(function (Training $training) use ($queue) {
            if ($training->wasChanged('status')) {
                $queue($training);
            }
        });
        Training::deleted($queue);
        AtcActivity::created($queue);
        AtcActivity::updated(function (AtcActivity $activity) use ($queue) {
            if ($activity->wasChanged('atc_active')) {
                $queue($activity);
            }
        });
        AtcActivity::deleted($queue);
        // A user is saved on every page view; only rating and division matter.
        User::updated(function (User $user) {
            if ($user->wasChanged(['rating', 'division'])) {
                VatssaDiscordSyncController::queueAuto($user->id);
            }
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'activity', 'suspended'])
                ->group(base_path('routes/vatssa.php'));
            // Not the 'api' group: its throttle:60,1 counter is shared with /api/users,
            // and one batch of instant-sync results is up to 100 calls. Own counter.
            Route::prefix('api')->middleware(['throttle:600,1,vatssa-discord-bot', 'bindings'])
                ->group(base_path('routes/vatssa-api.php'));
        }
    }
}
