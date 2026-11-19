<?php

namespace App\Providers;

use App\Helpers\TrainingStatus;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Task;
use App\Models\Training;
use App\Models\User;
use App\Services\VatssaTrainingFlags;
use App\Tasks\Types\TheoreticalExam;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Throwable;
use WeakMap;

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

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'activity', 'suspended'])
                ->group(base_path('routes/vatssa.php'));
        }
    }

    /**
     * Training flags (Requires attention, Stalled).
     *
     * The same people as the theory mark set and clear them: training.update
     * in the training's area, open trainings only.
     *
     * Close: after the close is saved, every flag still set is cleared under
     * the row lock (a flag may have been set while the close was in flight).
     * Reopen: before the reopen is saved, any flag a failed close left behind
     * is cleared in the same UPDATE (nothing can be set while closed). Each
     * clearing is written to the timeline. Failures are reported, never
     * thrown: the status change itself (e-mail, endorsements, mentor detach)
     * must always finish. See VatssaTrainingFlags.
     */
    private function registerTrainingFlags(): void
    {
        Gate::define('toggleTrainingFlags', function (User $user, Training $training) {
            return $training->status->isOpen() && $user->hasPermission('training.update', $training->area);
        });

        $pendingReopenClears = new WeakMap;

        Training::saving(function (Training $training) use ($pendingReopenClears) {
            $before = $training->exists ? $training->getOriginal('status') : null;
            if (! $before instanceof TrainingStatus || ! $training->isDirty('status')
                || $before->isOpen() || ! $training->status->isOpen()) {
                return;
            }

            try {
                $pendingReopenClears[$training] = app(VatssaTrainingFlags::class)->clearBeforeReopen($training);
            } catch (Throwable $e) {
                report($e);
            }
        });

        Training::saved(function (Training $training) use ($pendingReopenClears) {
            if (! $training->wasChanged('status')) {
                return;
            }

            $flags = app(VatssaTrainingFlags::class);
            $texts = $pendingReopenClears[$training] ?? [];
            unset($pendingReopenClears[$training]);

            try {
                if ($texts !== []) {
                    $flags->recordReopenClears($training, $texts);
                } elseif (! $training->status->isOpen()) {
                    $flags->clearAfterClose($training);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
