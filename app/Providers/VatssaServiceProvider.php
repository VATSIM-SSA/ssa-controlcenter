<?php

namespace App\Providers;

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
