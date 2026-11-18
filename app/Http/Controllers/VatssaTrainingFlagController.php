<?php

namespace App\Http\Controllers;

use App\Models\Training;
use App\Services\VatssaTrainingFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: staff set or clear a training flag ("Requires attention", "Stalled").
 *
 * The form sends the state it wants, not "flip it", so a double click or a
 * stale page cannot undo another change. The work, locking and history live in
 * VatssaTrainingFlags.
 */
class VatssaTrainingFlagController extends Controller
{
    public function update(Request $request, Training $training, string $flag, VatssaTrainingFlags $flags): RedirectResponse
    {
        abort_unless(isset(VatssaTrainingFlags::FLAGS[$flag]), 404);
        $this->authorize('toggleTrainingFlags', $training);

        $state = (bool) $request->validate([$flag => 'required|boolean'])[$flag];
        $config = VatssaTrainingFlags::FLAGS[$flag];

        $changed = $flags->set($training, $flag, $state, Auth::id());

        abort_if($changed === null, 403, 'This training is closed.');

        $message = rtrim($state ? $config['on'] : $config['off'], '.');

        return redirect($training->path())->withSuccess($changed ? $message : 'Nothing changed: ' . lcfirst($message) . ' already');
    }
}
