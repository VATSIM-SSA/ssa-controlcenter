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
 * The form sends the state it wants, not "flip it", and the training's
 * flags_version it was rendered with (required), so a double click, a stale
 * page, an old tab or a retried request cannot undo someone else's later
 * change. The work, locking and history live in VatssaTrainingFlags.
 */
class VatssaTrainingFlagController extends Controller
{
    public function update(Request $request, Training $training, string $flag, VatssaTrainingFlags $flags): RedirectResponse
    {
        abort_unless(isset(VatssaTrainingFlags::FLAGS[$flag]), 404);
        $this->authorize('toggleTrainingFlags', $training);

        $input = $request->validate([
            $flag => 'required|boolean',
            'version' => 'required|integer|min:0',
        ], [
            'version' => 'This page is out of date. Reload it and try again.',
        ]);
        $state = (bool) $input[$flag];
        $config = VatssaTrainingFlags::FLAGS[$flag];
        $message = rtrim($state ? $config['on'] : $config['off'], '.');

        $result = $flags->set($training, $flag, $state, Auth::id(), (int) $input['version']);

        return match ($result) {
            VatssaTrainingFlags::CHANGED => redirect($training->path())->withSuccess($message),
            VatssaTrainingFlags::UNCHANGED => redirect($training->path())->withSuccess('Nothing changed: ' . lcfirst($message) . ' already'),
            VatssaTrainingFlags::STALE => redirect($training->path())->withErrors('This training changed after you opened the page, so nothing was changed. Check the timeline and try again.'),
            default => abort(403, 'This training is closed.'),
        };
    }
}
