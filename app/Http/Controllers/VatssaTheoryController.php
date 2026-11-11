<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\Training;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: staff mark a training's theory as passed, or take the mark back.
 *
 * The form sends the state it wants, not "flip it", so a double click or two
 * staff on stale pages cannot undo each other. Setting the state it already
 * has changes nothing and logs nothing.
 */
class VatssaTheoryController extends Controller
{
    public function update(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('toggleTheoryCompleted', $training);

        $newState = (bool) $request->validate(['completed' => 'required|boolean'])['completed'];
        $oldState = (bool) $training->theory_completed;
        $label = $newState ? 'completed' : 'not completed';

        if ($newState === $oldState) {
            return redirect($training->path())->withSuccess('Theory was already marked as ' . $label);
        }

        $training->theory_completed = $newState;
        $training->save();

        ActivityLogService::warning(LogName::Training, 'Theory marked as ' . $label . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'THEORY', (int) $newState, (int) $oldState, Auth::id());

        return redirect($training->path())->withSuccess('Theory marked as ' . $label);
    }
}
