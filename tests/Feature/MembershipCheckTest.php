<?php

namespace Tests\Feature;

use anlutro\LaravelSettings\Facade as Setting;
use App\Models\User;
use App\Services\Vatssa\MembershipCheck;
use App\Services\Vatssa\Requirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: the requirement list, asked rather than only enforced.
 *
 * Every rule in MembershipCheck already existed and every one of them was
 * written to REFUSE -- TrainingPolicy::apply() returns the first denial it
 * reaches, as one sentence, in a pill. A member learned one reason at a time in
 * whatever order the policy happened to check.
 *
 * These tests pin the two things that matter about the collection: that it says
 * the same thing the policy says, and that "we have not checked yet" stays
 * distinguishable from "we checked and you are not there".
 */
class MembershipCheckTest extends TestCase
{
    use RefreshDatabase;

    private function labelled(User $user, string $label): Requirement
    {
        return MembershipCheck::for($user)->firstOrFail(fn (Requirement $r) => str_contains($r->label, $label));
    }

    #[Test]
    public function every_requirement_carries_a_label_and_a_verdict(): void
    {
        $list = MembershipCheck::for(User::factory()->create());

        $this->assertNotEmpty($list);
        $this->assertContainsOnlyInstancesOf(Requirement::class, $list);
        $this->assertTrue($list->every(fn (Requirement $r) => $r->label !== ''));
    }

    #[Test]
    public function a_platform_we_have_never_checked_reads_as_unknown_not_as_absent(): void
    {
        // Telling somebody who joined Discord an hour ago that they are not on
        // Discord is how a correct rule gets a reputation for being broken.
        $discord = $this->labelled(User::factory()->create(), 'Discord');

        $this->assertFalse($discord->met);
        $this->assertTrue($discord->unknown);
        $this->assertStringContainsString('not checked', $discord->detail);
        $this->assertSame('fas fa-clock text-muted', $discord->icon());
    }

    #[Test]
    public function training_being_closed_shows_as_its_own_unmet_requirement(): void
    {
        $user = User::factory()->create();

        Setting::set('trainingEnabled', false);
        $this->assertFalse($this->labelled($user, 'Training is open')->met);

        Setting::set('trainingEnabled', true);
        $this->assertTrue($this->labelled($user, 'Training is open')->met);
    }

    #[Test]
    public function an_open_training_shows_as_an_unmet_blocking_requirement(): void
    {
        $user = User::factory()->create();

        $open = $this->labelled($user, 'No training already open');

        $this->assertTrue($open->met, 'a fresh member has nothing open');
        $this->assertTrue($open->blocking);
    }

    #[Test]
    public function blockers_are_a_subset_of_the_list_and_all_unmet(): void
    {
        $user = User::factory()->create();

        $blockers = MembershipCheck::blockersFor($user);

        $this->assertTrue($blockers->every(fn (Requirement $r) => $r->blocking && ! $r->met));
        $this->assertLessThanOrEqual(MembershipCheck::for($user)->count(), $blockers->count());
    }

    #[Test]
    public function the_dashboard_renders_the_list_for_somebody_who_cannot_apply(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('What you need', $html);
        $this->assertStringContainsString('Discord linked', $html);
        $this->assertStringContainsString('Moodle account', $html);
    }
}
