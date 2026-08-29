<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\User;
use App\Models\Vatssa\MentorCapacity;
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

        // Replace wholesale inside a transaction. Diffing rows would be more
        // code for no benefit -- the table is a handful of rows and a partial
        // write here means requests routing to the wrong desk until somebody
        // notices.
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
            'ratings' => Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->get(),
            'resources' => Resource::forAudience(),
            'default' => config('vatssa.default_mentor_capacity'),
        ]);
    }

    public function updateMentorship(Request $request): RedirectResponse
    {
        $this->authorize('system.settings.manage');

        $data = $request->validate([
            'capacity' => 'sometimes|array',
            'capacity.*' => 'nullable|integer|min:0|max:99',
            'resources' => 'sometimes|array',
            'resources.*.label' => 'nullable|string|max:120',
            'resources.*.url' => 'nullable|url|max:500',
            'resources.*.icon' => 'nullable|string|max:40',
            'resources.*.description' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['capacity'] ?? [] as $userId => $limit) {
                if ($limit === null || $limit === '') {
                    // Removing the row means "no opinion", which falls back to
                    // the division default. Storing 0 would mean "takes nobody",
                    // and those are very different instructions.
                    MentorCapacity::where('user_id', $userId)->whereNull('rating_id')->delete();

                    continue;
                }

                MentorCapacity::updateOrCreate(
                    ['user_id' => (int) $userId, 'rating_id' => null],
                    ['student_limit' => (int) $limit]
                );
            }

            // Resources are replaced wholesale: it is a short list, and a row
            // left half-written is worse than one rewritten.
            Resource::where('audience', Resource::AUDIENCE_MENTOR)->delete();

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
