<?php

namespace App\Rules;

use App\Helpers\TrainingStatus;
use App\Models\Training;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * VATSSA: staff may not hand-set the stages the pipeline owns.
 *
 * In queue, pre-training and awaiting-mentor are decided by the system --
 * registration, a theory pass, a mentor being assigned. A human setting one by
 * hand puts Control Center and the bot into disagreement about where somebody
 * is, and the bot will move them straight back, which looks like a bug.
 *
 * The one manual move that IS wanted: a student in active training whose mentor
 * has gone goes back to awaiting-mentor and rejoins the queue.
 *
 * The dropdown in `training/show.blade.php` hides what this rejects. Both are
 * needed: the dropdown is a courtesy, this is the rule. A hidden `<option>` is
 * two seconds of DevTools away.
 *
 * @see TrainingStatus::isAssignableFrom()
 */
class AssignableTrainingStatus implements ValidationRule
{
    public function __construct(private ?Training $training = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $training = $this->training ?? request()->route('training');

        // Nothing to compare against -- creation, or a route without the model
        // bound. Rule::enum() has already checked the value is a real status.
        if (! $training instanceof Training) {
            return;
        }

        $wanted = TrainingStatus::tryFrom((int) $value);
        if ($wanted === null) {
            return;     // Rule::enum() owns that failure; do not double-report.
        }

        if (! $wanted->isAssignableFrom($training->status)) {
            $fail(sprintf(
                '"%s" is set by the training pipeline, not by hand. %s',
                $wanted->label(),
                $wanted === TrainingStatus::AWAITING_MENTOR
                    ? 'A training can only be returned to it from active training.'
                    : 'It is reached automatically when the student meets the requirement.'
            ));
        }
    }
}
