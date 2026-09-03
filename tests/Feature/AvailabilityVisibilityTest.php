<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vatssa\AvailabilityPoll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: a poll's visibility can be changed after it exists.
 *
 * It used to be a decision made once, in the creation form. A poll created
 * invite-only could not be opened up -- you deleted it and built a new one,
 * losing every answer already given. Same failure as not being able to add a
 * participant afterwards, same fix.
 */
class AvailabilityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function poll(User $owner): AvailabilityPoll
    {
        return AvailabilityPoll::create([
            'purpose' => AvailabilityPoll::MENTORING,
            'title' => 'test',
            'starts_on' => now()->addDays(10),
            'ends_on' => now()->addDays(12),
            'slot_minutes' => 30,
            'created_by' => $owner->id,
            'visibility' => AvailabilityPoll::VISIBILITY_INVITED,
        ]);
    }

    #[Test]
    public function the_owner_can_open_a_poll_up_and_close_it_again(): void
    {
        $owner = User::factory()->create();
        $poll = $this->poll($owner);

        $this->actingAs($owner)
            ->post(route('vatssa.availability.visibility', $poll), [
                'visibility' => AvailabilityPoll::VISIBILITY_LINK,
            ])
            ->assertRedirect(route('vatssa.availability.show', $poll));

        $this->assertSame(AvailabilityPoll::VISIBILITY_LINK, $poll->fresh()->visibility);

        // And back down again. Narrowing must not be a one-way door either.
        $this->actingAs($owner)
            ->post(route('vatssa.availability.visibility', $poll), [
                'visibility' => AvailabilityPoll::VISIBILITY_INVITED,
            ]);

        $this->assertSame(AvailabilityPoll::VISIBILITY_INVITED, $poll->fresh()->visibility);
    }

    #[Test]
    public function somebody_elses_poll_is_a_403(): void
    {
        $poll = $this->poll(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('vatssa.availability.visibility', $poll), [
                'visibility' => AvailabilityPoll::VISIBILITY_LINK,
            ])
            ->assertForbidden();

        $this->assertSame(AvailabilityPoll::VISIBILITY_INVITED, $poll->fresh()->visibility);
    }

    #[Test]
    public function an_invented_visibility_is_refused(): void
    {
        $owner = User::factory()->create();
        $poll = $this->poll($owner);

        $this->actingAs($owner)
            ->post(route('vatssa.availability.visibility', $poll), ['visibility' => 'public'])
            ->assertSessionHasErrors('visibility');

        $this->assertSame(AvailabilityPoll::VISIBILITY_INVITED, $poll->fresh()->visibility);
    }
}
