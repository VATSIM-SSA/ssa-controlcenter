<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * VATSSA: what Control Center would look like migrated to Tailwind.
 *
 * ## This is a look, not a rewrite
 *
 * Every page here is a PARALLEL copy under `/vatssa/preview`. It reads real
 * data and touches nothing: the upstream dashboard, profile, training page and
 * tables are untouched and keep working exactly as they do today.
 *
 * That is the entire point. Restyling the real pages means editing 554 blades
 * that upstream also edits, which is a merge conflict on every one, for ever,
 * maintained by one person who also has school. This shows the destination
 * without paying for the journey.
 *
 * ## How to revert
 *
 * Delete this controller, `resources/views/vatssa/preview/`, and the preview
 * route group in `routes/vatssa-web.php`. That is the whole footprint. The
 * Tailwind entry point and layout stay, because the availability tool uses
 * them for real.
 *
 * ## Why it is behind a permission
 *
 * Not because the data is sensitive -- it is the same data the real pages show
 * to the same people. Because a half-finished parallel copy of the dashboard
 * appearing in the sidebar teaches members that Control Center has two of
 * everything, and that impression outlives the experiment.
 */
class PreviewController extends Controller
{
    public function dashboard(): View
    {
        $user = Auth::user();

        return view('vatssa.preview.dashboard', [
            'user' => $user,
            'training' => Training::where('user_id', $user->id)->latest()->first(),
            // The three numbers a coordinator opens the dashboard to see. On
            // the real dashboard they are three separate pages.
            'queueDepth' => Training::where('status', TrainingStatus::IN_QUEUE)->count(),
            'awaitingMentor' => Training::where('status', TrainingStatus::AWAITING_MENTOR)->count(),
            'inTraining' => Training::where('status', TrainingStatus::ACTIVE_TRAINING)->count(),
        ]);
    }

    public function profile(User $user): View
    {
        return view('vatssa.preview.profile', [
            // roleAssignments, not roles -- User has no roles relation, and an
            // eager load of one that does not exist throws at render time.
            'user' => $user->load('endorsements.ratings', 'roleAssignments'),
            'trainings' => Training::where('user_id', $user->id)
                ->with('ratings')->latest()->get(),
        ]);
    }

    /**
     * The table page, which is the one that decides how the whole app feels.
     *
     * Control Center is mostly tables. Bootstrap-table renders them as a grey
     * grid with a border on every cell, and that single component is doing more
     * to make the application look dated than the colours are.
     */
    public function trainings(): View
    {
        return view('vatssa.preview.trainings', [
            'trainings' => Training::with('user', 'ratings', 'mentors')
                ->whereNotIn('status', [TrainingStatus::COMPLETED])
                ->orderByRaw('CASE status WHEN 4 THEN 2 WHEN 2 THEN 3 WHEN 3 THEN 4 ELSE status END')
                ->limit(60)
                ->get(),
        ]);
    }
}
