<?php

namespace App\Console\Commands;

use App\Helpers\TaskStatus;
use App\Helpers\TrainingStatus;
use App\Models\Task;
use App\Models\Training;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\RequestTarget;
use App\Tasks\Types\MentorNeeded;
use Illuminate\Console\Command;

/**
 * VATSSA: find students whose mentor has gone, and do something about it.
 *
 * ## The gap this closes
 *
 * Upstream detaches a mentor in several places -- `UpdateMemberDetails` when
 * they leave the division, `UserDelete` on a data-deletion request, and the
 * training form when somebody removes them by hand. Every one of those detaches
 * the mentor and LEAVES THE TRAINING'S STATUS ALONE.
 *
 * So the student stays in "Active training" with nobody teaching them, they do
 * not rejoin the mentor queue, no request is ever raised, and the only signal
 * is a training that quietly stops moving. The student usually notices first,
 * and by then it has been weeks.
 *
 * Nothing in the system looked for this. Now something does, every morning.
 *
 * ## Two halves, and both are needed
 *
 * STATE: the training goes back to "awaiting a mentor", which is the truthful
 * description of a student with no mentor, and is what puts them back in the
 * queue a coordinator actually reads.
 *
 * ACTION ITEM: a `MentorNeeded` request on that rating's coordinator desk. The
 * state change alone would be silent -- a queue nobody is asked to work is a
 * queue nobody works, and this whole class exists because something true was
 * recorded and never surfaced.
 *
 * Both are written to the action log and to the training's own timeline.
 *
 * ## Why it acts rather than only warning
 *
 * Leaving the student in "Active training" so a human can decide keeps them
 * invisible for exactly as long as nobody reads the warning, which is the
 * failure this exists to prevent. Every move is reversible in one click.
 *
 * ## Guards
 *
 * Only trainings with NO mentor at all, and not paused -- a paused training is
 * meant to be still, and moving one would undo a decision somebody made on
 * purpose. And one open request per training: a coordinator already working on
 * it is not nagged daily, which is how a queue becomes noise and stops being
 * read.
 */
class VatssaOrphanedTrainings extends Command
{
    protected $signature = 'vatssa:orphaned-trainings {--dry-run}';

    protected $description = 'Return mentorless students to the queue and raise a request';

    public function handle(): int
    {
        $orphaned = Training::whereIn('status', [
            TrainingStatus::ACTIVE_TRAINING,
            TrainingStatus::AWAITING_EXAM,
        ])
            ->whereNull('paused_at')
            ->whereDoesntHave('mentors')
            ->with('user', 'ratings')
            ->get();

        if ($orphaned->isEmpty()) {
            $this->info('No mentorless trainings.');

            return Command::SUCCESS;
        }

        $raised = 0;

        foreach ($orphaned as $training) {
            $name = $training->user?->name ?? ('CID ' . $training->user_id);
            $was = $training->status;

            if ($this->option('dry-run')) {
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

                ActionLog::did(
                    'training.returned_to_queue',
                    "{$name} was in active training with no mentor, so they were returned to the mentor queue.",
                    $training->id,
                    $training->user_id,
                    ['from' => $was->value, 'to' => TrainingStatus::AWAITING_MENTOR->value]
                );
            }

            $this->raise($training, $name, $was);
            $raised++;
        }

        $this->info("Mentorless trainings found: {$orphaned->count()}, requests raised: {$raised}");

        return Command::SUCCESS;
    }

    /**
     * Is there an open mentor request for this training already?
     */
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
            ['task_id' => $task->id, 'rating_id' => $ratingId]
        );

        $this->line("Raised a mentor request for {$name}");
    }
}
