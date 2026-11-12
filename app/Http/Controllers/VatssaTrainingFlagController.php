<?php

namespace App\Http\Controllers;

use App\Helpers\LogName;
use App\Models\Training;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: staff flags on a training, "Stalled" and "Requires attention".
 *
 * Same shape as the theory mark: the form sends the state it wants, so a
 * double click or a stale page cannot flip it back. Each flag is a nullable
 * timestamp (when it was set). The change is recorded as a staff comment on
 * the timeline, which students do not see, so no upstream view needs a new
 * activity type.
 */
class VatssaTrainingFlagController extends Controller
{
    /**
     * Flag key (route and form field) => column and the words used for it.
     */
    public const FLAGS = [
        'stalled' => [
            'column' => 'stalled_at',
            'on' => 'Training marked as stalled',
            'off' => 'Training no longer marked as stalled',
        ],
        'attention' => [
            'column' => 'attention_at',
            'on' => 'Training marked as requiring attention',
            'off' => 'Training no longer marked as requiring attention',
        ],
    ];

    public function update(Request $request, Training $training, string $flag): RedirectResponse
    {
        abort_unless(isset(self::FLAGS[$flag]), 404);
        $this->authorize('toggleTrainingFlags', $training);

        $config = self::FLAGS[$flag];
        $newState = (bool) $request->validate([$flag => 'required|boolean'])[$flag];
        $oldState = $training->{$config['column']} !== null;

        if ($newState === $oldState) {
            return redirect($training->path())->withSuccess('Nothing changed: ' . lcfirst($newState ? $config['on'] : $config['off']) . ' already');
        }

        $training->{$config['column']} = $newState ? now() : null;
        $training->save();

        $text = $newState ? $config['on'] : $config['off'];
        ActivityLogService::warning(LogName::Training, $text . ' on training ' . $training->id);
        TrainingActivityController::create($training->id, 'COMMENT', null, null, Auth::id(), $text . '.');

        return redirect($training->path())->withSuccess($text);
    }
}
