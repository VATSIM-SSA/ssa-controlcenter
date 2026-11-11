<?php

namespace App\Providers;

use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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

        if (! $this->app->routesAreCached()) {
            Route::middleware(['web', 'auth', 'activity', 'suspended'])
                ->group(base_path('routes/vatssa.php'));
        }
    }
}
