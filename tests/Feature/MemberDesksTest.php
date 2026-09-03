<?php

namespace Tests\Feature;

use App\Models\Rating;
use App\Models\User;
use App\Models\Vatssa\RequestTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: desks are set on the member whose desks they are.
 *
 * The Request routing grid is gone. It grew with every rating times every desk,
 * and it REBUILT EVERY DESK IN THE DIVISION on every save -- which is why it
 * needed a guard against a browser omitting an empty multi-select and emptying
 * all of them at once. Per member, that class of accident does not exist: an
 * empty submission means this person sits at no desk, and says nothing about
 * anybody else's.
 */
class MemberDesksTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);

        return $admin;
    }

    #[Test]
    public function an_admin_sets_the_desks_one_member_sits_at(): void
    {
        $member = User::factory()->create();
        $rating = Rating::whereNotNull('vatsim_rating')->orderBy('vatsim_rating')->first();

        $this->actingAs($this->admin())
            ->post(route('vatssa.admin.desks.update', $member), [
                'desks' => [RequestTarget::MEMBERSHIP, RequestTarget::COORDINATOR . ':' . $rating->id],
            ])
            ->assertRedirect();

        $held = RequestTarget::where('user_id', $member->id)->get();

        $this->assertCount(2, $held);
        $this->assertTrue($held->contains(fn ($row) => $row->tier === RequestTarget::MEMBERSHIP && $row->rating_id === null));
        $this->assertTrue($held->contains(fn ($row) => $row->tier === RequestTarget::COORDINATOR && $row->rating_id === $rating->id));
    }

    #[Test]
    public function a_coordinator_row_with_no_rating_is_the_catch_all(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('vatssa.admin.desks.update', $member), [
                'desks' => [RequestTarget::COORDINATOR . ':'],
            ]);

        $row = RequestTarget::where('user_id', $member->id)->sole();

        $this->assertSame(RequestTarget::COORDINATOR, $row->tier);
        $this->assertNull($row->rating_id, 'a blank rating on the coordinator desk is the catch-all, not "no desk"');
    }

    #[Test]
    public function saving_one_member_leaves_everybody_elses_desks_alone(): void
    {
        $keeper = User::factory()->create();
        RequestTarget::create(['tier' => RequestTarget::MEMBERSHIP, 'rating_id' => null, 'user_id' => $keeper->id]);

        $other = User::factory()->create();
        RequestTarget::create(['tier' => RequestTarget::MEMBERSHIP, 'rating_id' => null, 'user_id' => $other->id]);

        // The empty submission the old grid had to refuse outright.
        $this->actingAs($this->admin())
            ->post(route('vatssa.admin.desks.update', $other), []);

        $this->assertSame(0, RequestTarget::where('user_id', $other->id)->count());
        $this->assertSame(1, RequestTarget::where('user_id', $keeper->id)->count(),
            'clearing one member must never touch another');
    }

    #[Test]
    public function an_invented_desk_key_is_ignored_rather_than_stored(): void
    {
        $member = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('vatssa.admin.desks.update', $member), ['desks' => ['not-a-desk', 'everything']]);

        $this->assertSame(0, RequestTarget::where('user_id', $member->id)->count());
    }

    #[Test]
    public function a_member_cannot_put_themselves_on_a_desk(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->post(route('vatssa.admin.desks.update', $member), ['desks' => [RequestTarget::LEADERSHIP]])
            ->assertForbidden();

        $this->assertSame(0, RequestTarget::where('user_id', $member->id)->count());
    }

    #[Test]
    public function the_routing_grid_is_gone(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('vatssa.admin.routing'));
        $this->assertNull(Route::getRoutes()->getByName('vatssa.admin.routing.update'));
    }
}
