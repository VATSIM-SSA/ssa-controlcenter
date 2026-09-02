<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\Vatssa\RequestDesk;
use App\Models\Vatssa\TrainingType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * VATSSA: the three lists a training manager should be able to change.
 *
 * ## Why this page exists
 *
 * Ratings and endorsements were database rows with no interface. Training types
 * and request desks were not even rows -- they were a static array and a class
 * constant, both in files a training manager cannot edit and one of them
 * upstream's, so every change was a merge conflict waiting on a release.
 *
 * The result was that the shape of VATSSA's training programme was fixed at
 * whatever it happened to be the day somebody wrote it down, and changing it
 * needed a developer. That is the wrong dependency: the people who know what a
 * new endorsement should be called are the people running the training, and
 * they should not have to ask.
 *
 * ## One page, three tables
 *
 * Ratings (including endorsements), training types and request desks. They are
 * different tables but the same job -- "what kinds of thing exist here" -- and
 * splitting them across three pages would mean three places to look for the
 * setting somebody half-remembers.
 *
 * ## Nothing is deleted
 *
 * A rating is referenced by trainings and endorsements; a training type is
 * stamped on every training that used it; a desk labels every request sitting
 * on it. Deleting any of them corrupts history that people rely on, so the
 * verb here is "retire" and it means `active = false`: gone from the forms,
 * still on the records.
 *
 * Ratings are the exception and deliberately so -- upstream's `ratings` table
 * has no `active` column, and adding one would change a table five upstream
 * models join against. So a rating can be edited and added but not removed; if
 * one truly must go, that is a considered database change, not a button.
 */
class TrainingSetupController extends Controller
{
    public function index(): View
    {
        $this->authorize('system.settings.manage');

        return view('vatssa.admin.training-setup', [
            // Ratings first, endorsements after, because that is how somebody
            // reads the list: the ladder, then the extras hanging off it.
            'ratings' => Rating::orderByRaw('vatsim_rating IS NULL')
                ->orderBy('vatsim_rating')
                ->orderBy('name')
                ->get(),
            'types' => TrainingType::orderBy('sort_order')->orderBy('id')->get(),
            'desks' => RequestDesk::orderBy('sort_order')->orderBy('key')->get(),
            'nextTypeId' => TrainingType::nextId(),
        ]);
    }

    /**
     * Add a rating or an endorsement.
     *
     * The only difference between the two is whether `vatsim_rating` is set.
     * NULL means "an endorsement rather than a rating VATSIM issues", which is
     * upstream's own convention and the reason MAE and Oceanic rows sit in the
     * same table as S1.
     */
    public function storeRating(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'required|string|max:100',
            'kind' => ['required', Rule::in(['rating', 'endorsement'])],
            // Only meaningful for a rating. Unique, because two rows claiming
            // the same VATSIM rating makes every "what is this person's
            // training for" query ambiguous.
            'vatsim_rating' => [
                'nullable',
                'integer',
                'min:1',
                'max:12',
                Rule::unique('ratings', 'vatsim_rating'),
                Rule::requiredIf(fn () => $request->input('kind') === 'rating'),
            ],
        ]);

        // Assigned rather than mass-assigned: upstream's Rating model sets
        // neither $fillable nor $guarded, so Eloquent's default silently
        // discards everything and would write an empty row.
        $rating = new Rating;
        $rating->name = $data['name'];
        $rating->description = $data['description'];
        $rating->vatsim_rating = $data['kind'] === 'rating' ? (int) $data['vatsim_rating'] : null;
        $rating->save();

        return back()->with('success', $data['name'] . ' added.');
    }

    public function updateRating(Request $request, Rating $rating): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'name' => 'required|string|max:50',
            'description' => 'required|string|max:100',
        ]);

        // Name and description only. `vatsim_rating` is the join key half the
        // application uses to decide what somebody may control; changing it on
        // a live row silently rewrites who is qualified for what.
        //
        // Assigned, not mass-assigned. See storeRating().
        $rating->name = $data['name'];
        $rating->description = $data['description'];
        $rating->save();

        return back()->with('success', $rating->name . ' saved.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'name' => 'required|string|max:60',
            'icon' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);

        TrainingType::create([
            // The id is the value stored on trainings.type, so it is assigned
            // rather than chosen. See the model.
            'id' => TrainingType::nextId(),
            'name' => $data['name'],
            'icon' => $data['icon'] ?: 'fas fa-circle',
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 99,
            'active' => true,
        ]);

        return back()->with('success', $data['name'] . ' added.');
    }

    public function updateType(Request $request, TrainingType $type): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'name' => 'required|string|max:60',
            'icon' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'active' => 'nullable|boolean',
        ]);

        $type->update([
            'name' => $data['name'],
            'icon' => $data['icon'] ?: 'fas fa-circle',
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? $type->sort_order,
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return back()->with('success', $type->name . ' saved.');
    }

    public function storeDesk(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            // The key is what gets stored on every task routed here, so it is
            // fixed at creation and never editable afterwards.
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('vatssa_request_desks', 'key')],
            'label' => 'required|string|max:80',
            'hint' => 'nullable|string|max:255',
            'per_rating' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);

        RequestDesk::create([
            'key' => $data['key'],
            'label' => $data['label'],
            'hint' => $data['hint'] ?? null,
            'per_rating' => (bool) ($data['per_rating'] ?? false),
            'sort_order' => $data['sort_order'] ?? 99,
            'active' => true,
        ]);

        return back()->with('success', $data['label'] . ' added. Staff it under Request routing.');
    }

    public function updateDesk(Request $request, RequestDesk $desk): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'label' => 'required|string|max:80',
            'hint' => 'nullable|string|max:255',
            'per_rating' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'active' => 'nullable|boolean',
        ]);

        $desk->update([
            'label' => $data['label'],
            'hint' => $data['hint'] ?? null,
            'per_rating' => (bool) ($data['per_rating'] ?? false),
            'sort_order' => $data['sort_order'] ?? $desk->sort_order,
            'active' => (bool) ($data['active'] ?? false),
        ]);

        return back()->with('success', $desk->label . ' saved.');
    }
}
