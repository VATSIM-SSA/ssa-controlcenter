<?php

namespace Database\Factories;

use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Models\Feedback;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        return [
            'submitter_user_id' => User::factory(),
            'reference_user_id' => User::factory(),
            'reference_position_id' => Position::factory(),
            'feedback' => $this->faker->paragraph(),
            'status' => FeedbackStatus::OPEN,
        ];
    }

    public function uncorrelated(): static
    {
        return $this->state(['reference_position_id' => null]);
    }

    /** Read, and deliberately not passed on. */
    public function closed(?User $actor = null): static
    {
        return $this->actioned(FeedbackStatus::CLOSED, $actor);
    }

    /** Read, and shown to the controller it is about. */
    public function forwarded(?User $actor = null): static
    {
        return $this->actioned(FeedbackStatus::FORWARDED, $actor);
    }

    /**
     * The three action fields move together, so the states set them together.
     * A status with no actor is feedback that says it was dealt with by nobody,
     * and a fixture that can produce one will eventually be mistaken for a bug.
     */
    private function actioned(FeedbackStatus $status, ?User $actor): static
    {
        return $this->state([
            'status' => $status,
            'sentiment' => FeedbackSentiment::POSITIVE,
            'actioned_by_id' => $actor?->id ?? User::factory(),
            'actioned_at' => now(),
        ]);
    }
}
