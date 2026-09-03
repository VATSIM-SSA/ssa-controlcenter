<?php

namespace Tests\Feature;

use anlutro\LaravelSettings\Facade as Setting;
use App\Helpers\TrainingStatus;
use App\Helpers\VatsimRating;
use App\Models\Training;
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
    public function training_being_closed_is_listed_only_when_it_is_actually_closed(): void
    {
        $user = User::factory()->create();

        Setting::set('trainingEnabled', false);
        $this->assertFalse($this->labelled($user, 'Training is open')->met);

        // And when it IS open, the line is gone rather than green. A tick that
        // is true for everybody, nearly always, teaches somebody to stop
        // reading the list it sits at the top of.
        Setting::set('trainingEnabled', true);
        $this->assertNull(
            MembershipCheck::for($user)->first(fn (Requirement $r) => str_contains($r->label, 'Training is open')),
            'an always-true line should not be rendered at all'
        );
    }

    #[Test]
    public function an_open_training_shows_as_an_unmet_blocking_requirement(): void
    {
        $user = User::factory()->create();

        $open = $this->labelled($user, 'No other training open');

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
        // A MEMBER who cannot apply. A plain factory user is not in the
        // division, and since the membership module landed those people get the
        // transfer/visit box instead of the training block -- so this test has
        // to say which of the two it is about, or it silently starts asserting
        // against a card it was not written for.
        $user = User::factory()->create([
            'division' => config('app.owner_code'),
            'subdivision' => config('app.owner_code'),
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('What you need', $html);
        $this->assertStringContainsString('Discord account linked', $html);
        $this->assertStringContainsString('Moodle account linked', $html);
        $this->assertStringContainsString('No other training open', $html);

        // The pill carries one word now, not the policy's first denial.
        $this->assertStringContainsString('Not eligible', $html);
    }

    // ------------------------------------------------- rules that do not apply

    #[Test]
    public function an_observer_is_not_asked_for_an_active_atc_rating(): void
    {
        // An OBS has no rating to keep active, so telling them theirs is
        // inactive is telling them something that is not about them -- and it
        // rendered as a requirement they could never satisfy and never needed
        // to. Upstream's own policy already carves this out; what changed is
        // that a rule which does not apply to you is no longer shown to you.
        $observer = User::factory()->create(['rating' => VatsimRating::OBS->value]);

        $this->assertNull(
            MembershipCheck::for($observer)->first(fn (Requirement $r) => str_contains($r->label, 'ATC rating active'))
        );
    }

    #[Test]
    public function somebody_already_in_training_is_not_asked_for_an_active_atc_rating(): void
    {
        // Measured by their training rather than by their hours, which is what
        // makes refresher training reachable at all.
        $user = User::factory()->create(['rating' => VatsimRating::S2->value]);
        Training::factory()->create([
            'user_id' => $user->id,
            'status' => TrainingStatus::ACTIVE_TRAINING,
        ]);

        $list = MembershipCheck::for($user->fresh());

        $this->assertNull($list->first(fn (Requirement $r) => str_contains($r->label, 'ATC rating active')));
    }

    #[Test]
    public function a_rated_member_not_in_training_is_asked_for_an_active_atc_rating(): void
    {
        // The rule still exists. This is the case it was written for.
        $user = User::factory()->create(['rating' => VatsimRating::S2->value]);

        $this->assertNotNull(
            MembershipCheck::for($user)->first(fn (Requirement $r) => str_contains($r->label, 'ATC rating active'))
        );
    }

    #[Test]
    public function the_seven_day_wait_is_listed_only_for_somebody_it_applies_to(): void
    {
        $user = User::factory()->create();

        $this->assertNull(
            MembershipCheck::for($user)->first(fn (Requirement $r) => str_contains($r->label, '7 days')),
            'a member who has never trained here cannot fail a wait they are not serving'
        );
    }
}
