<?php

namespace App\Support\Vatssa;

use App\Helpers\TrainingStatus;
use App\Http\Controllers\TaskController;
use App\Models\Training;
use App\Tasks\Types\CheckoutRequest;
use App\Tasks\Types\Custom;
use App\Tasks\Types\LeaveOfAbsence;
use App\Tasks\Types\MentorCapacityRequest;
use App\Tasks\Types\MentorNeeded;
use App\Tasks\Types\RatingUpgrade;
use App\Tasks\Types\ReturnFromLeave;
use App\Tasks\Types\SoloEndorsement;
use App\Tasks\Types\Types;

/**
 * VATSSA: which requests make sense on THIS training, right now.
 *
 * ## The problem
 *
 * `TaskController::getTypes()` scans a directory and returns all eight, so the
 * dropdown on a training in the queue offered "Return From Leave" to a student
 * who is not on leave and "Mentor Capacity" to a student who is not a mentor.
 * Six of the eight were wrong on most trainings.
 *
 * A menu of mostly-inapplicable options is not merely untidy. It teaches people
 * that the list is decoration, and the request that gets picked is the one
 * whose name reads closest -- which is how a CPT request arrives as a Custom
 * and sits on the wrong desk for a week.
 *
 * ## An added file, filtering at the call site
 *
 * The alternative was a method on `App\Tasks\Types\Types`, which would mean
 * modifying that abstract class and all eight subclasses -- nine upstream files
 * conflicting at every release, to express something only VATSSA's pipeline
 * knows. The mapping lives here instead, and upstream's task types are
 * untouched.
 *
 * The cost is honest: a task type added upstream is unknown to this map. It
 * falls through to "always available", which is the old behaviour, so a new
 * type appears rather than silently vanishing. Wrong in the tidy direction.
 *
 * ## This is a courtesy, not a control
 *
 * Anyone can still POST any type. Nothing here is a security boundary -- the
 * request lands on a desk and a human declines it. Do not add a check here and
 * think a permission has been enforced.
 */
class RequestAvailability
{
    /**
     * The request types worth offering on $training.
     *
     * @return array<int, Types>
     */
    public static function for(Training $training): array
    {
        return array_values(array_filter(
            TaskController::getTypes(),
            fn ($type) => self::applies($type::class, $training),
        ));
    }

    /**
     * @param  class-string  $type
     */
    public static function applies(string $type, Training $training): bool
    {
        $status = $training->status;
        $paused = $training->paused_at !== null;
        $hasMentor = $training->mentors->isNotEmpty();

        return match ($type) {
            // About the MENTOR, not the student. Both belong in the mentor
            // portal, and neither has ever made sense on somebody's training --
            // that is the clearest case of the list being scanned rather than
            // read.
            MentorCapacityRequest::class, MentorNeeded::class => false,

            // Only while the training is actually running. Asking for leave
            // from a queue you have not left yet is asking for nothing, and on
            // a closed training it is asking for less.
            LeaveOfAbsence::class => $status->isInProgress() && ! $paused,

            // The mirror of it, and the reason `$paused` is checked on both:
            // exactly one of the pair should ever be offered.
            ReturnFromLeave::class => $paused,

            // Needs somebody to have taught them. A solo before a mentor is
            // not a request, it is a mistake with a form attached.
            SoloEndorsement::class => $hasMentor && in_array(
                $status, [TrainingStatus::ACTIVE_TRAINING, TrainingStatus::AWAITING_EXAM], true
            ),

            // Ready to sit, or waiting to. Not in queue, not in theory.
            CheckoutRequest::class => in_array(
                $status, [TrainingStatus::ACTIVE_TRAINING, TrainingStatus::AWAITING_EXAM], true
            ),

            // After the exam, not before it. This is the "process my rating"
            // request, and there is nothing to process until somebody has sat
            // something.
            RatingUpgrade::class => $status === TrainingStatus::AWAITING_EXAM,

            // Always. It exists precisely for what this map cannot predict, and
            // removing it on any stage would leave somebody with no way to ask.
            Custom::class => true,

            // Unknown to this map -- almost certainly new from upstream. Show
            // it. A type that vanishes because nobody updated a match arm is a
            // worse failure than one that appears where it need not.
            default => true,
        };
    }
}
