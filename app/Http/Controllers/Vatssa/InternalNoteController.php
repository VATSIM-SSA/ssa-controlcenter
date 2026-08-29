<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\InternalNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * VATSSA: writing and removing internal notes.
 *
 * Every action authorises on the SCOPE's own permission rather than a single
 * "notes" permission, because the two scopes have different audiences on
 * purpose: a training note is for the ATC training manager and admins, a member
 * note is for admins alone.
 */
class InternalNoteController extends Controller
{
    public function storeUserNote(Request $request, User $user): RedirectResponse
    {
        $this->authorize(InternalNote::permissionFor(InternalNote::SCOPE_USER));

        $data = $request->validate(['body' => 'required|string|max:5000']);

        InternalNote::create([
            'scope' => InternalNote::SCOPE_USER,
            'user_id' => $user->id,
            'body' => $data['body'],
            'author_id' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Member note saved. Admins only.');
    }

    public function storeTrainingNote(Request $request, Training $training): RedirectResponse
    {
        $this->authorize(InternalNote::permissionFor(InternalNote::SCOPE_TRAINING));

        $data = $request->validate(['body' => 'required|string|max:5000']);

        InternalNote::create([
            'scope' => InternalNote::SCOPE_TRAINING,
            'user_id' => $training->user_id,
            'training_id' => $training->id,
            'body' => $data['body'],
            'author_id' => Auth::id(),
        ]);

        return redirect()->back()->with('success', 'Training note saved. Training manager and admins only.');
    }

    /**
     * Remove a note.
     *
     * Authorised against the note's OWN scope, not the page it was deleted
     * from -- otherwise a training-note permission would be enough to delete a
     * member note that happened to be listed nearby.
     */
    public function destroy(InternalNote $note): RedirectResponse
    {
        $this->authorize(InternalNote::permissionFor($note->scope));

        $note->delete();

        return redirect()->back()->with('success', 'Note deleted.');
    }
}
