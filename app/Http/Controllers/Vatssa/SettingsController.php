<?php

namespace App\Http\Controllers\Vatssa;

use App\Http\Controllers\Controller;
use App\Models\Vatssa\MessageTemplate;
use App\Models\Vatssa\MoodleCourse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
