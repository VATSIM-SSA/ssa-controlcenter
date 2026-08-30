<?php

namespace App\Http\Controllers\Vatssa;

use App\Helpers\TrainingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Training;
use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\MessageLog;
use App\Models\Vatssa\MessageTemplate;
use App\Models\Vatssa\PlatformRequirement;
use App\Models\Vatssa\MoodleCourse;
use App\Models\Vatssa\TheoryAttempt;
use App\Models\Vatssa\UserPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VATSSA: what the training pipeline bot writes back.
 *
 * The bot computes; Control Center stores and shows. Nothing in here decides
 * anything -- there is no pipeline logic on this side of the wire, because
 * logic in two places drifts and the bot already has the tests.
 *
 * Reached container-to-container over the Docker network. Caddy 403s
 * `/api/vatssa/bridge/*` at the edge and `vatssa-bridge` checks the token, so
 * there are two locks and neither is the only one.
 *
 * EVERY WRITE IS IDEMPOTENT. The bot re-reads the same Moodle attempts and the
 * same Brevo events on every poll, deliberately, so that downtime costs
 * freshness rather than data. Writes that were not idempotent would turn that
 * into duplicates.
 */
class BridgeController extends Controller
{
    /**
     * Where somebody is, on Discord and Moodle.
     */
    public function platforms(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'discord_user_id' => 'nullable|integer',
            'on_discord' => 'required|boolean',
            'moodle_user_id' => 'nullable|integer',
            'on_moodle' => 'required|boolean',
            // Enrolment is not the same fact as having an account. null means
            // registered but in no course, which is the stall worth seeing.
            'moodle_enrolment' => 'sometimes|nullable|in:active,suspended',
            'moodle_course' => 'sometimes|nullable|string|max:20',
            'vatsim_member' => 'sometimes|boolean',
        ]);

        // checked_at is set here rather than accepted from the bot, so the
        // panel's "as of" can never be older or newer than the write itself.
        $data['checked_at'] = now();

        UserPlatform::updateOrCreate(['user_id' => $user->id], $data);

        return response()->json(['status' => 'ok']);
    }

    /**
     * One attempt at one rating's theory exam.
     *
     * Keyed on the Moodle attempt id, so re-polling the same attempt updates
     * the row rather than adding another.
     */
    public function theoryAttempt(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|string|max:10',
            'moodle_course_id' => 'required|integer',
            'moodle_quiz_id' => 'required|integer',
            'moodle_attempt_id' => 'nullable|integer',
            'grade' => 'nullable|numeric',
            'passed' => 'required|boolean',
            'taken_at' => 'required|date',
        ]);

        $data['rating'] = strtoupper($data['rating']);

        $attempt = TheoryAttempt::updateOrCreate([
            'user_id' => $user->id,
            'moodle_quiz_id' => $data['moodle_quiz_id'],
            'moodle_attempt_id' => $data['moodle_attempt_id'] ?? null,
        ], $data + ['user_id' => $user->id]);

        return response()->json(['status' => 'ok', 'id' => $attempt->id]);
    }

    /**
     * One email the student received, from either system.
     */
    public function logMessage(Request $request, Training $training): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'kind' => 'sometimes|string|max:40',
            'source' => 'sometimes|in:bot,control-center',
            'message_id' => 'nullable|string|max:255',
            'sent_at' => 'required|date',
        ]);

        // The Brevo poll overlaps its window to survive downtime, so the same
        // email arrives more than once by design. Without a message id there is
        // nothing to deduplicate on, so the subject and timestamp stand in.
        $key = [
            'user_id' => $training->user_id,
            'message_id' => $data['message_id'] ?: substr(sha1($data['subject'] . $data['sent_at']), 0, 40),
        ];

        $entry = MessageLog::updateOrCreate($key, $data + [
            'user_id' => $training->user_id,
            'training_id' => $training->id,
            'kind' => $data['kind'] ?? 'other',
            'source' => $data['source'] ?? 'control-center',
        ]);

        return response()->json(['status' => 'ok', 'id' => $entry->id]);
    }

    /**
     * Move a training to awaiting-mentor, or back to active.
     *
     * The bot owns this transition -- it is the one Control Center cannot see,
     * because a theory pass lives in Moodle. Staff cannot set it by hand; see
     * `App\Rules\AssignableTrainingStatus`.
     */
    public function setStatus(Request $request, Training $training): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|integer',
            'reason' => 'nullable|string|max:255',
        ]);

        $wanted = TrainingStatus::tryFrom($data['status']);
        if ($wanted === null) {
            return response()->json(['message' => 'Unknown status'], 422);
        }

        $old = $training->status;
        if ($old === $wanted) {
            return response()->json(['status' => 'unchanged']);
        }

        $training->fill($training->resolveStatusChanges($wanted));
        $training->save();

        // The audit trail records a null actor, which is how a human reading it
        // later can tell the pipeline moved somebody rather than a person.
        TrainingActivityController::create(
            $training->id, 'STATUS', $wanted->value, $old->value, null, $data['reason'] ?? null
        );

        // And on the division-wide log, so "what has the bot been doing" has an
        // answer that is not a container log. mirror: false -- the STATUS row
        // above already says this on the training's own timeline, and a COMMENT
        // repeating it makes the timeline worse.
        ActionLog::did(
            'training.status_set_by_bot',
            ($training->user?->name ?? ('CID ' . $training->user_id))
                . " moved from {$old->label()} to {$wanted->label()}."
                . (($data['reason'] ?? null) ? ' ' . $data['reason'] : ''),
            $training->id,
            $training->user_id,
            ['from' => $old->value, 'to' => $wanted->value],
            ActionLog::ACTOR_BOT,
            mirror: false,
        );

        return response()->json(['status' => 'ok', 'from' => $old->value, 'to' => $wanted->value]);
    }

    /**
     * Something the bot did, or noticed and could not do.
     *
     * The bot enrols people in Moodle, kicks suspended members from Discord and
     * chases theory attempts. Until now all of that lived in the bot's own
     * container log, which means nobody in the division could answer "what has
     * it been doing" without an SSH session.
     *
     * `level: warning` is the important half: a rating whose Moodle course id
     * is still a placeholder, a Discord member whose CID cannot be resolved.
     * The bot cannot fix those and a person must.
     *
     * Not mirrored onto the training timeline by default. The bot posts a lot,
     * most of it is not about one training, and a timeline is a student's
     * record rather than an operations feed -- pass mirror to override.
     */
    public function actionLog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|max:60',
            'summary' => 'required|string|max:255',
            'level' => ['sometimes', 'in:' . ActionLog::INFO . ',' . ActionLog::WARNING],
            'training_id' => 'nullable|exists:trainings,id',
            'user_id' => 'nullable|exists:users,id',
            'context' => 'nullable|array',
            'mirror' => 'sometimes|boolean',
        ]);

        $level = $data['level'] ?? ActionLog::INFO;
        $mirror = (bool) ($data['mirror'] ?? false);

        $level === ActionLog::WARNING
            ? ActionLog::noticed(
                $data['action'], $data['summary'], $data['training_id'] ?? null,
                $data['user_id'] ?? null, $data['context'] ?? [], ActionLog::ACTOR_BOT, $mirror
            )
            : ActionLog::did(
                $data['action'], $data['summary'], $data['training_id'] ?? null,
                $data['user_id'] ?? null, $data['context'] ?? [], ActionLog::ACTOR_BOT, $mirror
            );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Who is excused from the platform requirements.
     *
     * The bot chases students who leave Discord and eventually closes their
     * training. It must not do that to somebody an ATC training manager has
     * excused -- a country that blocks Discord, an account stuck in support --
     * and the exemption is granted HERE, with a reason and a name against it,
     * so this is a read.
     *
     * Read rather than pushed, on every cycle. An exemption granted five
     * minutes before the removal sweep has to be honoured by that sweep, and a
     * push would race it.
     */
    public function exemptions(): JsonResponse
    {
        return response()->json([
            'discord' => PlatformRequirement::where('discord', true)->pluck('user_id'),
            'moodle' => PlatformRequirement::where('moodle', true)->pluck('user_id'),
        ]);
    }

    /**
     * The bot's message templates, edited in Control Center.
     */
    public function templates(): JsonResponse
    {
        return response()->json(
            MessageTemplate::all()->mapWithKeys(fn (MessageTemplate $template) => [
                $template->key => [
                    'subject' => $template->subject,
                    'body' => $template->body,
                    'channel' => $template->channel,
                ],
            ])
        );
    }

    /**
     * The rating-to-Moodle-course map.
     *
     * Rows with placeholder ids are left out by `MoodleCourse::map()`, so an
     * unconfigured rating reads as needing no theory -- visible and fixable --
     * rather than as a student with no attempts.
     */
    public function courses(): JsonResponse
    {
        return response()->json(MoodleCourse::map());
    }
}
