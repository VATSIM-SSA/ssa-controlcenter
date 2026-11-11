<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\Training;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: staff mark a training's theory as passed, or take the mark back.
 *
 * Kept out of TrainingController so the fork does not touch upstream's
 * largest file. Mirrors upstream's pre-training toggle, but POST only.
 */
class VatssaTheoryController extends Controller
{
    public function toggle(Training $training): RedirectResponse
    {
        $this->authorize('toggleTheoryCompleted', $training);

        $oldState = (bool) $training->theory_completed;
        $newState = ! $oldState;

        $training->theory_completed = $newState;
        $training->save();

        ActivityLogService::warning(LogName::Training, 'Theory marked as ' . ($newState ? 'completed' : 'not completed') . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'THEORY', (int) $newState, (int) $oldState, Auth::id());

        return redirect($training->path())->withSuccess('Theory marked as ' . ($newState ? 'completed' : 'not completed'));
    }
}
