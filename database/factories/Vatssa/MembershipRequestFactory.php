<?php

namespace Database\Factories\Vatssa;

use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\User;
use App\Models\Vatssa\MembershipRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * VATSSA: membership requests for tests.
 *
 * ## The states are states, not attributes
 *
 * `MembershipRequest::open()` picks the starting state from the TYPE, because
 * getting it from the caller is how a transfer ends up starting at `open` and
 * skipping its disciplinary check. The factory keeps that honest: `definition()`
 * produces a request in its type's initial state, and the named states move it
 * afterwards the way the application would.
 *
 * @extends Factory<MembershipRequest>
 */
class MembershipRequestFactory extends Factory
{
    protected $model = MembershipRequest::class;

    public function definition(): array
    {
        $type = MembershipRequestType::TRANSFER;

        return [
            'type' => $type,
            'state' => $type->initialState(),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
        ];
    }

    public function type(MembershipRequestType $type): static
    {
        // The state moves with the type. A rating upgrade sitting in
        // `pending-disciplinary` is a row the application cannot produce, and a
        // fixture that can produce one will eventually be mistaken for a bug.
        return $this->state(fn () => [
            'type' => $type,
            'state' => $type->initialState(),
        ]);
    }

    public function inState(MembershipRequestState $state): static
    {
        return $this->state(fn () => ['state' => $state]);
    }

    /**
     * A request whose Terminal check has been done.
     *
     * The three disciplinary fields move together, so the factory sets them
     * together -- a request that says it was checked by nobody is worse than
     * one that says it was never checked.
     */
    public function checked(bool $clean = true): static
    {
        return $this->state(fn () => [
            'disciplinary_clean' => $clean,
            'disciplinary_context' => $clean ? null : 'Suspended for 30 days in March 2026.',
            'disciplinary_checked_at' => now(),
            'disciplinary_checked_by' => User::factory(),
        ]);
    }
}
