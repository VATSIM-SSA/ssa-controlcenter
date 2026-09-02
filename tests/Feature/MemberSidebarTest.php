<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VATSSA: what an ordinary member sees in the menu, and what they must not.
 *
 * Two bugs prompted this. Moodle and the Availability Tool sat inside a canany
 * of four STAFF permissions, so the member the two pages exist for could not
 * see either. And the admin block's outer condition was an OR across three
 * permissions wrapping BOTH sections, so anybody clearing any one of them was
 * shown the Training Admin heading with nothing under it.
 *
 * A heading with no items is not cosmetic. It tells somebody an area of the
 * application exists and is being withheld, which is the opposite of what a
 * permission system is for.
 */
class MemberSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        return User::factory()->create();
    }

    #[Test]
    public function a_member_with_no_roles_sees_the_training_heading_and_the_availability_tool(): void
    {
        $html = $this->actingAs($this->member())->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('Availability Tool', $html);
        $this->assertStringContainsString('Training', $html);
    }

    #[Test]
    public function a_member_with_no_roles_sees_neither_admin_section(): void
    {
        $html = $this->actingAs($this->member())->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('Training Admin', $html);
        $this->assertStringNotContainsString('System Admin', $html);
        $this->assertStringNotContainsString('Positions', $html);
    }

    #[Test]
    public function a_nav_editor_sees_positions_but_not_an_empty_training_admin_heading(): void
    {
        // The nav editor holds `fir.positions.view` and nothing else that
        // appears down here. Under the old single condition that one permission
        // opened BOTH headings, and Training Admin rendered with no items at
        // all -- which is the bug this test exists for.
        $navEditor = $this->member();
        $navEditor->roleAssignments()->create(['role' => 'nav-editor', 'area_id' => null]);

        $html = $this->actingAs($navEditor)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('System Admin', $html);
        $this->assertStringContainsString('Positions', $html);
        $this->assertStringNotContainsString('Training Admin', $html);
        $this->assertStringNotContainsString('Training setup', $html);
        $this->assertStringNotContainsString('Notification templates', $html);
    }

    #[Test]
    public function a_membership_manager_sees_no_system_admin_heading(): void
    {
        $manager = $this->member();
        $manager->roleAssignments()->create(['role' => 'membership-manager', 'area_id' => null]);

        $html = $this->actingAs($manager)->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('System Admin', $html);
        $this->assertStringNotContainsString('Positions', $html);
    }

    #[Test]
    public function a_member_with_no_roles_cannot_open_the_positions_page(): void
    {
        $this->assertFalse($this->member()->can('viewAny', Position::class));

        $this->actingAs($this->member())->get(route('positions.index'))->assertForbidden();
    }

    #[Test]
    public function the_positions_resource_registers_no_route_without_a_method_behind_it(): void
    {
        // positions.create and positions.edit were registered by
        // `except(['show'])` and the controller implements neither, so a
        // logged-in member reached them and got a BadMethodCallException --
        // a route with no authorize() call in it, because there was nothing
        // to authorise in.
        $this->assertNull(Route::getRoutes()->getByName('positions.create'));
        $this->assertNull(Route::getRoutes()->getByName('positions.edit'));

        // And the path a member could hit now falls through to index, which
        // does authorise.
        $this->actingAs($this->member())->get('/admin/positions/create')->assertForbidden();
    }
}
