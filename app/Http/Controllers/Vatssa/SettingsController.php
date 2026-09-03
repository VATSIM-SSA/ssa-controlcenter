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
     * Which desks ONE member sits at.
     *
     * This is the Request routing page, per person, and it is where desk
     * assignment belongs: "what access does this person have" is a question
     * about a person, and the answer used to be split between their profile
     * (roles) and a grid on a different page (desks).
     *
     * SAFER THAN THE GRID IT REPLACES. That page rebuilt every desk in the
     * division on every save, which is why it needed a guard against a browser
     * omitting an empty multi-select and silently emptying all of them. This
     * touches one member's rows and nothing else, so an empty submission means
     * exactly what it says: this person sits at no desk.
     */
    public function updateDesks(Request $request, User $user): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'desks' => 'sometimes|array|max:50',
            // "coordinator:14" for a per-rating desk, "membership" otherwise --
            // the same key shape the grid used, so nothing else had to change.
            'desks.*' => 'string|max:40',
        ]);

        DB::transaction(function () use ($data, $user) {
            RequestTarget::where('user_id', $user->id)->delete();

            foreach (array_unique($data['desks'] ?? []) as $key) {
                [$tier, $ratingId] = array_pad(explode(':', $key, 2), 2, null);

                if (! RequestTarget::isTier($tier)) {
                    continue;
                }

                RequestTarget::create([
                    'tier' => $tier,
                    // A coordinator row with no rating is the CATCH-ALL, which
                    // is what makes a one-coordinator division work without
                    // four identical rows. Blank is not "no desk" here.
                    'rating_id' => RequestTarget::isPerRating($tier) && $ratingId !== null && $ratingId !== ''
                        ? (int) $ratingId
                        : null,
                    'user_id' => $user->id,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Desks saved for ' . $user->name . '.');
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
