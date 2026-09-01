<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\User;
use App\Models\Vatssa\MentorCapacity;
use App\Models\Vatssa\MentorCeiling;
use App\Models\Vatssa\MessageTemplate;
use App\Models\Vatssa\MoodleCourse;
use App\Models\Vatssa\RequestTarget;
use App\Models\Vatssa\Resource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * VATSSA: the two things about the pipeline that should be editable without a
 * deploy -- what it says, and which Moodle course each rating sits.
 *
 * Both used to be files in the bot's container. That meant a wording change was
 * a rebuild and a restart, and a new Moodle course was a code change, which is
 * absurd for values that change more often than the code does.
 */
class SettingsController extends Controller
{
    public function templates(): View
    {
        $this->authorize('system.settings.manage');

        return view('vatssa.admin.templates', [
            'templates' => MessageTemplate::orderBy('key')->get(),
        ]);
    }

    public function updateTemplate(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:20000',
        ]);

        $template->update($data + ['updated_by' => Auth::id()]);

        return redirect()->back()->with('success', "Template {$template->key} saved.");
    }

    /**
     * Who sits at each request desk.
     *
     * The coordinator desk is per rating, because VATSSA's pipelines are per
     * rating. Everything else is one list. Several people per desk is normal --
     * the request goes to whichever of them has the fewest open tasks.
     */
    public function routing(): View
    {
        $this->authorize('system.settings.manage');

        return view('vatssa.admin.routing', [
            'tiers' => RequestTarget::TIERS,
            'ratings' => Rating::whereNotNull('vatsim_rating')
                ->orderBy('vatsim_rating')->get(),
            'targets' => RequestTarget::with('user')->get(),
            // Anybody who could plausibly staff a desk. Not filtered by role:
            // VATSSA1 and VATSSA2 are people, not a Control Center role, and
            // hard-coding which role may sit where would defeat the point of
            // the page.
            'candidates' => User::whereHas('roleAssignments')->orderBy('first_name')->get(),
        ]);
    }

    public function updateRouting(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'targets' => 'sometimes|array',
            'targets.*' => 'array',
            'targets.*.*' => 'integer|exists:users,id',
        ]);

        // A BROWSER OMITS AN EMPTY MULTI-SELECT ENTIRELY. So a form submitted
        // with nothing chosen -- a mis-click on Save, a stale page, a partial
        // POST -- arrives with no `targets` key at all, and a bare
        // delete-then-recreate would silently empty every desk. Empty desks are
        // not loud: requests just stay with whoever raised them.
        //
        // Refusing the wipe is the safe failure. Clearing a desk deliberately
        // is still possible -- deselect everyone in ONE desk and the others
        // survive.
        if (empty($data['targets'])) {
            return redirect()->back()->withErrors(
                'That would have removed every desk assignment, so nothing was saved. '
                . 'To clear one desk, deselect its people and leave the others alone.'
            );
        }

        // Replace wholesale inside a transaction. Diffing rows would be more
        // code for no benefit at this size.
        DB::transaction(function () use ($data) {
            RequestTarget::query()->delete();

            foreach ($data['targets'] ?? [] as $key => $userIds) {
                // "coordinator:14" for a per-rating desk, "vatssa1" otherwise.
                [$tier, $ratingId] = array_pad(explode(':', (string) $key, 2), 2, null);

                if (! RequestTarget::isTier($tier)) {
                    continue;
                }

                foreach (array_unique($userIds) as $userId) {
                    RequestTarget::create([
                        'tier' => $tier,
                        'rating_id' => RequestTarget::isPerRating($tier) && $ratingId ? (int) $ratingId : null,
                        'user_id' => $userId,
                    ]);
                }
            }
        });

        return redirect()->back()->with('success', 'Request routing saved.');
    }

    /**
     * Mentor capacity and the resource links.
     *
     * Both were going to live on a separate mentor portal. They are a number
     * and a list of links, on a page Control Center already has.
     */
    public function mentorship(): View
    {
        $this->authorize('system.settings.manage');

        return view('vatssa.admin.mentorship', [
            'mentors' => User::whereHas('roleAssignments', fn ($q) => $q->where('role', 'mentor'))
                ->orderBy('first_name')->get(),
            'capacity' => MentorCapacity::all(),
            'ceilings' => MentorCeiling::all()->keyBy('user_id'),
            'ratings' => Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->get(),
            'resources' => Resource::forAudience(),
        ]);
    }

    public function updateMentorship(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            // capacity[userId][ratingId] -- per rating, because clearance to
            // mentor S2 is not clearance to mentor C1.
            'capacity' => 'sometimes|array',
            'capacity.*' => 'array',
            'capacity.*.*' => 'nullable|integer|min:0|max:99',
            'total' => 'sometimes|array',
            'total.*' => 'nullable|integer|min:0|max:99',
            'max_rating' => 'sometimes|array',
            'max_rating.*' => 'nullable|exists:ratings,id',
            'resources' => 'sometimes|array',
            'resources.*.label' => 'nullable|string|max:120',
            // url:http,https, not bare `url`. Laravel's unqualified rule accepts
            // any scheme that parses, and `javascript://%0aalert(1)` parses --
            // it has the `//` the pattern wants. Blade escaping does not help:
            // the payload never breaks out of the attribute, it IS the
            // attribute, and mentor-panels renders this straight into an href
            // for every mentor. Injectable only by somebody holding
            // system.settings.manage, which is exactly the account worth
            // protecting from a stolen session.
            'resources.*.url' => 'nullable|url:http,https|max:500',
            'resources.*.icon' => 'nullable|string|max:40',
            'resources.*.description' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['capacity'] ?? [] as $userId => $perRating) {
                foreach ($perRating as $ratingId => $limit) {
                    if ($limit === null || $limit === '') {
                        // Removing the row means "no opinion", which is NOT the
                        // same as a limit of zero -- zero means they take
                        // nobody for that rating. Storing one as the other is
                        // the kind of mistake nobody finds for a term.
                        MentorCapacity::where('user_id', $userId)
                            ->where('rating_id', $ratingId)->delete();

                        continue;
                    }

                    MentorCapacity::updateOrCreate(
                        ['user_id' => (int) $userId, 'rating_id' => (int) $ratingId],
                        ['student_limit' => (int) $limit]
                    );
                }
            }

            // The ceiling: a total across everything, and how far up the ladder
            // they may teach at all. Both the training manager's to set, which
            // is why there is no mentor-facing field for either.
            foreach ($data['total'] ?? [] as $userId => $total) {
                $maxRating = $data['max_rating'][$userId] ?? null;

                if (($total === null || $total === '') && empty($maxRating)) {
                    MentorCeiling::where('user_id', $userId)->delete();

                    continue;
                }

                MentorCeiling::updateOrCreate(['user_id' => (int) $userId], [
                    'total_limit' => ($total === null || $total === '') ? null : (int) $total,
                    'max_rating_id' => $maxRating ?: null,
                ]);
            }

            // Resources are replaced wholesale: a short list, and a row left
            // half-written is worse than one rewritten. Unlike the desks this
            // is safe to empty -- the fields are text inputs, which a browser
            // always submits, so an absent key means the form was not the
            // resources form rather than "the user cleared everything".
            if (array_key_exists('resources', $data)) {
                Resource::where('audience', Resource::AUDIENCE_MENTOR)->delete();
            }

            foreach ($data['resources'] ?? [] as $index => $row) {
                if (empty($row['label']) || empty($row['url'])) {
                    continue;
                }

                Resource::create([
                    'label' => $row['label'],
                    'url' => $row['url'],
                    'icon' => $row['icon'] ?: 'fa-link',
                    'description' => $row['description'] ?? null,
                    'audience' => Resource::AUDIENCE_MENTOR,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Mentorship settings saved.');
    }

    public function courses(): View
    {
        $this->authorize('system.settings.manage');

        return view('vatssa.admin.courses', [
            'courses' => MoodleCourse::orderBy('rating')->get(),
        ]);
    }

    public function updateCourses(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'courses' => 'required|array',
            'courses.*.rating' => 'required|string|max:10',
            'courses.*.course_id' => 'required|integer|min:0',
            'courses.*.exam_quiz_id' => 'required|integer|min:0',
            'courses.*.pass_mark' => 'required|numeric|min:0|max:100',
            'courses.*.active' => 'sometimes|boolean',
        ]);

        foreach ($data['courses'] as $row) {
            MoodleCourse::updateOrCreate(
                ['rating' => strtoupper($row['rating'])],
                [
                    'course_id' => $row['course_id'],
                    'exam_quiz_id' => $row['exam_quiz_id'],
                    'pass_mark' => $row['pass_mark'],
                    'active' => (bool) ($row['active'] ?? false),
                ]
            );
        }

        return redirect()->back()->with('success', 'Moodle course map saved.');
    }
}
