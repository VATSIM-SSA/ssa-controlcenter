<?php

namespace App\Console\Commands;

use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Http\Controllers\TrainingActivityController;
use App\Models\Task;
use App\Models\Training;
use App\Models\TrainingActivity;
use App\Models\User;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\RequestTarget;
use App\Models\Vatssa\TrainingMentorSnapshot;
use App\Notifications\Vatssa\MentorLostNotification;
use App\Notifications\Vatssa\StudentRemovedFromMentorNotification;
use App\Tasks\Types\MentorNeeded;
use Illuminate\Console\Command;

/**
 * VATSSA: watch mentor assignments, and act when one disappears.
 *
 * ## The gap this closes
 *
 * Upstream detaches a mentor in at least three places -- `UpdateMemberDetails`
 * when they leave the division, `UserDelete` on a data-deletion request, and
 * the training form when somebody removes them by hand. Every one of those
 * detaches the mentor and LEAVES THE TRAINING'S STATUS ALONE, tells the
 * student nothing, and tells the mentor nothing.
 *
 * So the student stayed in "Active training" with nobody teaching them, out of
 * the queue, and found out by noticing that nothing had happened for a month.
 * The mentor kept a slot reserved for somebody who was not coming back, and
 * that slot is one the next student in the queue could not have.
 *
 * ## Two passes, because they catch different things
 *
 * REMOVALS come from diffing `training_mentor` against the snapshot table. This
 * catches every path, including ones nobody has found, and it is the only way
 * to see a SWAP: move a student from mentor A to mentor B and the training is
 * never mentorless, so A is never told.
 *
 * ORPHANS are trainings with no mentor at all. A removal usually causes one,
 * but not always -- a mentor can be detached before this ever ran, and that
 * student is just as stuck.
 *
 * ## What it does about an orphan
 *
 * STATE: back to "awaiting a mentor", which is the truthful description and is
 * what puts them in a queue a coordinator reads.
 *
 * ACTION ITEM: a `MentorNeeded` request on that rating's coordinator desk. The
 * state change alone would be silent, and a queue nobody is asked to work is a
 * queue nobody works.
 *
 * PEOPLE: the student is told they are back on the waiting list and that it is
 * not their fault; the mentor is told they have a slot back.
 *
 * Everything lands on the training's own timeline as well as the action log.
 *
 * ## Guards
 *
 * An empty snapshot means NO PRIOR KNOWLEDGE, so the first run seeds silently
 * rather than emailing every mentor that they have lost every student. Paused
 * trainings are left alone -- paused is a decision somebody made on purpose.
 * One open request per training, so a coordinator already working on it is not
 * nagged daily.
 */
class VatssaMentorWatch extends Command
{
    protected $signature = 'vatssa:mentor-watch {--dry-run}';

    protected $description = 'Notice lost mentors: tell both people, requeue the student, raise a request';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $removals = $this->reportRemovals($dry);
        $orphans = $this->handleOrphans($dry);

        if (! $dry) {
            // Last, and only on a clean run. Capturing before the passes would
            // erase the very difference they are looking for.
            TrainingMentorSnapshot::capture();
        }

        $this->info("Mentors removed: {$removals}. Mentorless trainings handled: {$orphans}.");

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------
    // Pass one: who lost a student since the last look
    // -----------------------------------------------------------------

    private function reportRemovals(bool $dry): int
    {
        if (! TrainingMentorSnapshot::isPrimed()) {
            $this->line('No previous snapshot; seeding rather than reporting.');

            return 0;
        }

        $previous = TrainingMentorSnapshot::previous();
        $told = 0;

        foreach ($previous as $trainingId => $wasMentoring) {
            $training = Training::with('user', 'mentors', 'ratings')->find($trainingId);

            // The training is gone. Nothing to tell anybody about: a deleted
            // training has no mentor to have lost.
            if ($training === null) {
                continue;
            }

            $stillMentoring = $training->mentors->pluck('id')->all();
            $gone = array_diff($wasMentoring, $stillMentoring);

            foreach ($gone as $mentorId) {
                $mentor = User::find($mentorId);
                if ($mentor === null) {
                    continue;       // deleted account; there is nobody to write to
                }

                $student = $training->user?->name ?? ('CID ' . $training->user_id);

                if ($dry) {
                    $this->line("[dry-run] tell {$mentor->name} they lost {$student}");
                    $told++;

                    continue;
                }

                // A swap is not a loss for the student, so the wording changes.
                // Being told "we are finding you somebody else" when you already
                // have somebody else is the kind of message that makes people
                // stop reading the ones that matter.
                $reason = $stillMentoring
                    ? 'They have been reassigned to another mentor.'
                    : null;

                $mentor->notify(new StudentRemovedFromMentorNotification(
                    $training, $student, $reason
                ));

                // Two rows, and they say different things.
                //
                // The MENTOR row is the FACT that the mentor is gone. Upstream
                // writes it when a person removes a mentor through the form,
                // and writes nothing at all when UpdateMemberDetails or
                // UserDelete detaches one -- so the timeline of somebody whose
                // mentor left the division showed no mentor change ever
                // happening. Backfilled only when it is missing, so the manual
                // path is not duplicated.
                $this->backfillMentorActivity($training->id, $mentorId);

                // The COMMENT is that they were TOLD. Never a duplicate: it is
                // a separate event, and "the mentor was removed" and "the
                // mentor knows" are the two facts a coordinator needs.
                $this->note(
                    $training->id,
                    "{$mentor->name} was told they are no longer mentoring {$student}."
                );

                ActionLog::did(
                    'mentor.removed',
                    "{$mentor->name} is no longer mentoring {$student}, and has been told.",
                    $training->id,
                    $training->user_id,
                    ['mentor_id' => $mentorId, 'reassigned' => (bool) $stillMentoring],
                    mirror: false,      // note() above already wrote the timeline
                );

                $told++;
            }
        }

        return $told;
    }

    // -----------------------------------------------------------------
    // Pass two: trainings with nobody teaching them
    // -----------------------------------------------------------------

    private function handleOrphans(bool $dry): int
    {
        $orphaned = Training::whereIn('status', [
            TrainingStatus::ACTIVE_TRAINING,
            TrainingStatus::AWAITING_EXAM,
        ])
            ->whereNull('paused_at')
            ->whereDoesntHave('mentors')
            ->with('user', 'ratings')
            ->get();

        $handled = 0;

        foreach ($orphaned as $training) {
            $name = $training->user?->name ?? ('CID ' . $training->user_id);
            $was = $training->status;

            if ($dry) {
                $this->line("[dry-run] {$name} - {$was->label()} with no mentor");

                continue;
            }

            // Already on somebody's desk. Say nothing: raising it again every
            // morning is how a request queue turns into noise, and a noisy
            // queue is one nobody reads -- which is the original problem.
            if ($this->alreadyRaised($training)) {
                $this->line("Already raised for {$name}");

                continue;
            }

            // AWAITING_EXAM keeps its status and only gets the request. Somebody
            // waiting on a CPT has finished the mentored part; dropping them
            // back into the queue would undo real progress to fix a bookkeeping
            // problem, and the examiner is not their mentor.
            if ($was === TrainingStatus::ACTIVE_TRAINING) {
                $training->fill($training->resolveStatusChanges(TrainingStatus::AWAITING_MENTOR));
                $training->save();

                $this->note(
                    $training->id,
                    'No mentor was assigned, so the training was returned to the mentor queue.',
                    'STATUS',
                    TrainingStatus::AWAITING_MENTOR->value,
                    $was->value,
                );

                // The student, in plain words, and told it is not their fault.
                // A status that silently moves backwards reads as a punishment,
                // and a student who thinks they have been dropped stops asking.
                $training->user?->notify(new MentorLostNotification($training));

                ActionLog::did(
                    'training.returned_to_queue',
                    "{$name} was in active training with no mentor, so they were returned to the mentor queue and told.",
                    $training->id,
                    $training->user_id,
                    ['from' => $was->value, 'to' => TrainingStatus::AWAITING_MENTOR->value],
                    mirror: false,
                );
            }

            $this->raise($training, $name, $was);
            $handled++;
        }

        return $handled;
    }

    private function alreadyRaised(Training $training): bool
    {
        return Task::where('type', MentorNeeded::class)
            ->where('subject_training_id', $training->id)
            ->where('status', TaskStatus::PENDING)
            ->exists();
    }

    /**
     * Put it on the coordinator desk for this student's rating.
     */
    private function raise(Training $training, string $name, TrainingStatus $was): void
    {
        $ratingId = $training->ratings->first()?->id;
        $target = RequestTarget::nextAt(RequestTarget::COORDINATOR, $ratingId);

        // `tasks.assignee_user_id` is NOT NULL, so an empty desk means there is
        // no row to write. Warn instead of inventing a recipient: a request
        // assigned to an arbitrary role-holder looks handled and is not.
        if ($target === null) {
            ActionLog::noticed(
                'training.mentor_lost_no_desk',
                "{$name} has no mentor, and nobody sits on that rating's coordinator desk to be told.",
                $training->id,
                $training->user_id,
                ['rating_id' => $ratingId]
            );

            $this->warn("No coordinator desk for {$name}");

            return;
        }

        $task = Task::create([
            'type' => MentorNeeded::class,
            'status' => TaskStatus::PENDING,
            'message' => $was === TrainingStatus::AWAITING_EXAM
                ? 'Awaiting a CPT with no mentor attached. Left in that state - check whether it is intended.'
                : 'Their mentor was removed, so they have been returned to the mentor queue.',
            'subject_user_id' => $training->user_id,
            'subject_training_id' => $training->id,
            'subject_training_rating_id' => $ratingId,
            'assignee_user_id' => $target->id,
            // No creator. Nullable upstream, and null is the honest answer:
            // nobody asked for this, the system noticed it.
            'creator_user_id' => null,
            'vatssa_tier' => RequestTarget::COORDINATOR,
            'vatssa_rating_id' => $ratingId,
        ]);

        $task->type()->create($task);

        ActionLog::did(
            'training.mentor_request_raised',
            "A mentor request for {$name} was raised on the coordinator desk.",
            $training->id,
            $training->user_id,
            ['task_id' => $task->id, 'rating_id' => $ratingId],
            mirror: false,
        );

        $this->note($training->id, 'A mentor request was raised on the coordinator desk.');

        $this->line("Raised a mentor request for {$name}");
    }

    /**
     * Write the missing "X removed as mentor" row, if nobody wrote it.
     *
     * `UpdateMemberDetails` and `UserDelete` detach a mentor and leave the
     * timeline completely silent, so a student whose mentor left the division
     * had a training that appeared never to have had a mentor change. The
     * training form does write one, which is why this checks first.
     */
    private function backfillMentorActivity(int $trainingId, int $mentorId): void
    {
        $exists = TrainingActivity::where('training_id', $trainingId)
            ->where('type', 'MENTOR')
            ->where('old_data', $mentorId)
            // A window, not "ever". The same mentor can be attached and
            // detached twice, and the row from the first time is not a record
            // of the second.
            ->where('created_at', '>=', now()->subDays(2))
            ->exists();

        if (! $exists) {
            $this->note($trainingId, null, 'MENTOR', null, $mentorId);
        }
    }

    /**
     * Onto the training's own timeline.
     *
     * A null actor is how somebody reading it later can tell the system did
     * this rather than a person. That distinction matters more than it looks:
     * "the coordinator moved you back" and "nobody was available" are different
     * facts about the same row.
     */
    private function note(
        int $trainingId,
        ?string $text,
        string $type = 'COMMENT',
        ?int $new = null,
        ?int $old = null,
    ): void {
        TrainingActivityController::create($trainingId, $type, $new, $old, null, $text);
    }
}
