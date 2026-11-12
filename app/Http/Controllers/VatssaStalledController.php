<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\Training;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: staff flag a training as stalled, or clear the flag.
 *
 * Same shape as the theory mark: the form sends the state it wants, so a
 * double click or a stale page cannot flip it back. The change is recorded
 * as a staff comment on the timeline, which students do not see, so no
 * upstream view needs a new activity type.
 */
class VatssaStalledController extends Controller
{
    public function update(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('toggleTrainingStalled', $training);

        $newState = (bool) $request->validate(['stalled' => 'required|boolean'])['stalled'];
        $oldState = $training->stalled_at !== null;

        if ($newState === $oldState) {
            return redirect($training->path())->withSuccess($newState ? 'Training was already marked as stalled' : 'Training was already not marked as stalled');
        }

        $training->stalled_at = $newState ? now() : null;
        $training->save();

        $text = $newState ? 'Training marked as stalled' : 'Training no longer marked as stalled';
        ActivityLogService::warning(LogName::Training, $text . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'COMMENT', null, null, Auth::id(), $text . '.');

        return redirect($training->path())->withSuccess($text);
    }
}
