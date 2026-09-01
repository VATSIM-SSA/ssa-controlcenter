<?php

namespace App\Console\Commands;

use App\Helpers\ExamStage;
use App\Models\Vatssa\ActionLog;
use App\Models\Vatssa\Exam;
use Illuminate\Console\Command;

/**
 * VATSSA: the seven-day rule, actually enforced.
 *
 * ## Why a sweep and not just validation
 *
 * The rule is checked when an examiner confirms: slots inside the window are
 * never offered. That stops the rule being broken deliberately and does nothing
 * about the way it is actually broken, which is TIME PASSING. An exam confirmed
 * three weeks out with the banner still to do is legal on the day it is
 * confirmed and illegal a fortnight later, and nobody notices because nothing
 * changed -- which is the point. Nothing changing IS the failure.
 *
 * ## Three things it looks for
 *
 * A confirmed exam that is now inside the notice period with paperwork
 * outstanding. An exam nobody has taken with no legal slot left. And a stage
 * that has not moved in a fortnight, which is a handoff somebody has forgotten.
 *
 * ## It reports and does not act
 *
 * Deliberately, and unlike vatssa:mentor-watch. Cancelling somebody's exam on a
 * timer is the kind of automation that is right nine times and unforgivable the
 * tenth -- the student has told their family, the examiner has booked the
 * evening. Every one of these needs a person to make a call, so the job's whole
 * purpose is making sure a person knows.
 */
class VatssaExamWatch extends Command
{
    protected $signature = 'vatssa:exam-watch';

    protected $description = 'Notice exams that will miss the seven-day deadline, or have stalled';

    /** A stage that has not moved in this long is a forgotten handoff. */
    private const STALL_DAYS = 14;

    public function handle(): int
    {
        $exams = Exam::with('training.user', 'examiner')->open()->get();
        $flagged = 0;

        foreach ($exams as $exam) {
            $who = $exam->training?->user?->name ?? ('CID ' . $exam->training?->user_id);

            // 1. Confirmed, close, and not published.
            if ($exam->noticeBreached()) {
                ActionLog::noticed(
                    'exam.notice_breached',
                    "{$who}'s exam is on " . $exam->scheduled_for->format('j M')
                        . ' and still needs: ' . implode(', ', $exam->checklistOutstanding()) . '.',
                    $exam->training_id,
                    $exam->training?->user_id,
                    ['exam_id' => $exam->id, 'outstanding' => $exam->checklistOutstanding()],
                );
                $flagged++;
            }

            // 2. Offered to examiners, and every cleared slot has aged out.
            if ($exam->stage === ExamStage::AWAITING_EXAMINER && $exam->offerableSlots() === []) {
                ActionLog::noticed(
                    'exam.no_slot_left',
                    "{$who}'s exam has no time left that any examiner could legally take. "
                        . 'They need to give fresh availability.',
                    $exam->training_id,
                    $exam->training?->user_id,
                    ['exam_id' => $exam->id],
                );
                $flagged++;
            }

            // 3. Nothing has happened for a fortnight.
            //
            // updated_at, not created_at: an exam that moved yesterday is fine
            // however old it is, and one raised yesterday that has already
            // stalled is not yet a problem.
            if ($exam->updated_at?->lessThan(now()->subDays(self::STALL_DAYS))) {
                ActionLog::noticed(
                    'exam.stalled',
                    "{$who}'s exam has not moved in " . self::STALL_DAYS
                        . ' days. Waiting on ' . ($exam->stage->waitingOn() ?? 'nobody') . '.',
                    $exam->training_id,
                    $exam->training?->user_id,
                    ['exam_id' => $exam->id, 'stage' => $exam->stage->value],
                );
                $flagged++;
            }
        }

        $this->info("Exams in flight: {$exams->count()}, flagged: {$flagged}.");

        return Command::SUCCESS;
    }
}
