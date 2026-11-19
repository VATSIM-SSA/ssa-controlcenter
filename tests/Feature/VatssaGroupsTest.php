<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupManager;
use App\Models\VatssaGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: groups (named lists of people by VATSIM ID, with managers).
class VatssaGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private VatssaGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);
        $this->admin = $this->admin->fresh();
        $this->group = VatssaGroup::create(['name' => 'SSA HitSquad', 'created_by' => $this->admin->id]);
    }

    private function manager(bool $settings = false): User
    {
        $user = User::factory()->create();
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $user->id, 'can_manage_settings' => $settings]);

        return $user;
    }

    /**
     * @return array<int, int>
     */
    private function memberCids(): array
    {
        return $this->group->currentMembers()->orderBy('user_id')->pluck('user_id')->map(fn ($cid) => (int) $cid)->all();
    }

    private function queued(int $cid): int
    {
        return DB::table('vatssa_discord_sync_requests')->where('user_id', $cid)->where('source', 'auto')->count();
    }

    #[Test]
    public function staff_create_groups_and_others_cannot(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.store'), ['name' => 'Streamers'])->assertRedirect();
        $this->assertDatabaseHas('vatssa_groups', ['name' => 'Streamers']);

        $this->actingAs(User::factory()->create())->post(route('vatssa.groups.store'), ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($this->manager(true))->post(route('vatssa.groups.store'), ['name' => 'Nope'])->assertForbidden();
        $this->assertDatabaseMissing('vatssa_groups', ['name' => 'Nope']);
    }

    #[Test]
    public function bulk_add_parses_any_list_skips_duplicates_and_accepts_people_without_an_account(): void
    {
        $known = User::factory()->create(['id' => 1500001]);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => "{$known->id}, 1234567\n1234567;2765432"])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '3 added') && str_contains($message, '1234567'));

        $this->assertSame(array_values(collect([$known->id, 1234567, 2765432])->sort()->all()), $this->memberCids());

        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1234567 2777777'])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 added, 1 already'));
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => 'none here'])->assertSessionHasErrors('cids');
    }

    #[Test]
    public function bulk_remove_by_ticks_or_by_pasted_list_keeps_history(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001 1000002 1000003 1000004']);

        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['members' => [1000001, 1000002]]);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['cids' => '1000003, 9999999']);

        $this->assertSame([1000004], $this->memberCids());
        $this->assertSame(4, VatssaGroupMember::where('group_id', $this->group->id)->count());
        $this->assertSame(3, $this->group->events()->where('event', 'member_removed')->count());
    }

    #[Test]
    public function every_membership_change_queues_a_discord_sync(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001']);
        $this->assertSame(1, $this->queued(1000001));
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['members' => [1000001]]);
        $this->assertSame(2, $this->queued(1000001));
    }

    #[Test]
    public function a_member_manager_edits_members_only(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->get(route('vatssa.groups.show', $this->group))->assertOk()
            ->assertDontSee('id="groupSettings"', false)->assertDontSee(route('vatssa.groups.managers.add', $this->group));
        $this->actingAs($manager)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001'])->assertRedirect();
        $this->assertSame([1000001], $this->memberCids());

        $this->actingAs($manager)->patch(route('vatssa.groups.update', $this->group), ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($manager)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => User::factory()->create()->id, 'level' => 'members'])->assertForbidden();
        $this->actingAs($manager)->post(route('vatssa.groups.archive', $this->group))->assertForbidden();
    }

    #[Test]
    public function a_settings_manager_renames_and_appoints_member_managers_but_not_settings_managers(): void
    {
        $manager = $this->manager(true);
        $helper = User::factory()->create();

        $this->actingAs($manager)->get(route('vatssa.groups.show', $this->group))->assertSee('id="groupSettings"', false);
        $this->actingAs($manager)->patch(route('vatssa.groups.update', $this->group), ['name' => 'HitSquad 2026', 'description' => 'Event support'])->assertRedirect();
        $this->assertSame('HitSquad 2026', $this->group->fresh()->name);
        $this->assertSame(1, $this->group->events()->where('event', 'renamed')->count());

        $this->actingAs($manager)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $helper->id, 'level' => 'members'])->assertRedirect();
        $this->assertTrue($this->group->fresh()->membersEditableBy($helper));
        $this->actingAs($manager)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $helper->id, 'level' => 'settings'])->assertForbidden();

        $other = VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => User::factory()->create()->id, 'can_manage_settings' => true]);
        $this->actingAs($manager)->post(route('vatssa.groups.managers.remove', [$this->group, $other]))->assertForbidden();
        $this->actingAs($manager)->post(route('vatssa.groups.archive', $this->group))->assertForbidden();
    }

    #[Test]
    public function outsiders_and_managers_of_other_groups_are_refused(): void
    {
        $outsider = User::factory()->create();
        $elsewhere = User::factory()->create();
        $other = VatssaGroup::create(['name' => 'Other']);
        VatssaGroupManager::create(['group_id' => $other->id, 'user_id' => $elsewhere->id]);

        foreach ([$outsider, $elsewhere] as $user) {
            $this->actingAs($user)->get(route('vatssa.groups.show', $this->group))->assertForbidden();
            $this->actingAs($user)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001'])->assertForbidden();
        }
        $this->actingAs($outsider)->get(route('vatssa.groups.index'))->assertForbidden();
        $this->actingAs($elsewhere)->get(route('vatssa.groups.index'))->assertOk()->assertSee('Other')->assertDontSee('SSA HitSquad');
        $this->assertSame([], $this->memberCids());
    }

    #[Test]
    public function the_sidebar_shows_groups_to_staff_and_managers_only(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('vatssa.groups.index'));
        $this->actingAs($this->manager())->get(route('dashboard'))->assertSee(route('vatssa.groups.index'));
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertDontSee(route('vatssa.groups.index'));
    }

    #[Test]
    public function archive_hides_the_group_from_managers_and_restore_brings_it_back(): void
    {
        $manager = $this->manager(true);
        $this->actingAs($this->admin)->post(route('vatssa.groups.archive', $this->group))->assertRedirect(route('vatssa.groups.index'));

        $this->actingAs($manager)->get(route('vatssa.groups.show', $this->group))->assertForbidden();
        $this->actingAs($manager)->get(route('vatssa.groups.index'))->assertForbidden();

        $this->actingAs($this->admin)->post(route('vatssa.groups.archive', $this->group), ['restore' => 1]);
        $this->actingAs($manager)->get(route('vatssa.groups.show', $this->group))->assertOk();
    }

    #[Test]
    public function delete_needs_archive_first_and_the_name_typed(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001']);

        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $this->group), ['confirm_name' => 'SSA HitSquad'])->assertStatus(409);
        $this->actingAs($this->admin)->post(route('vatssa.groups.archive', $this->group));
        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $this->group), ['confirm_name' => 'wrong'])->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('vatssa_groups', ['id' => $this->group->id]);

        $before = $this->queued(1000001);
        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $this->group), ['confirm_name' => 'SSA HitSquad'])->assertRedirect();
        $this->assertDatabaseMissing('vatssa_groups', ['id' => $this->group->id]);
        $this->assertDatabaseMissing('vatssa_group_members', ['group_id' => $this->group->id]);
        $this->assertGreaterThan($before, $this->queued(1000001));
    }

    #[Test]
    public function the_api_lists_active_groups_and_a_members_groups_for_any_api_key(): void
    {
        ApiKey::create(['id' => 'ro-key', 'name' => 't', 'read_only' => true, 'created_at' => now()]);
        $archived = VatssaGroup::create(['name' => 'Old', 'archived_at' => now()]);
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1234567]);
        VatssaGroupMember::create(['group_id' => $archived->id, 'user_id' => 1234567]);
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 7654321, 'removed_at' => now()]);
        $archived->forceFill(['archived_at' => now()])->save();

        $this->getJson(route('api.vatssa.groups.index'))->assertUnauthorized();
        $this->getJson(route('api.vatssa.groups.index'), ['Authorization' => 'Bearer ro-key'])->assertOk()
            ->assertExactJson(['data' => [['id' => $this->group->id, 'name' => 'SSA HitSquad', 'description' => null, 'members' => [1234567]]]]);
        $this->getJson(route('api.vatssa.members.show', 1234567), ['Authorization' => 'Bearer ro-key'])->assertOk()
            ->assertExactJson(['data' => ['cid' => 1234567, 'groups' => [['id' => $this->group->id, 'name' => 'SSA HitSquad']]]]);
        $this->getJson(route('api.vatssa.members.show', 7654321), ['Authorization' => 'Bearer ro-key'])->assertJsonPath('data.groups', []);
    }

    #[Test]
    public function a_deleted_member_leaves_every_group(): void
    {
        $user = User::factory()->create();
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => $user->id]);
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $user->id]);

        $user->delete();

        $this->assertSame(0, VatssaGroupMember::where('user_id', $user->id)->count());
        $this->assertSame(0, VatssaGroupManager::where('user_id', $user->id)->count());
    }

    #[Test]
    public function the_group_page_shows_names_history_and_escapes_input(): void
    {
        $this->actingAs($this->admin)->patch(route('vatssa.groups.update', $this->group), ['name' => '<b>Bold</b>']);
        $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->assertOk()
            ->assertSee('&lt;b&gt;Bold&lt;/b&gt;', false)->assertDontSee('<b>Bold</b>', false)->assertSee('Renamed');
    }

    #[Test]
    public function an_archived_group_is_read_only_even_for_staff(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001']);
        $this->actingAs($this->admin)->post(route('vatssa.groups.archive', $this->group));

        $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->assertOk()
            ->assertSee('Read-only')->assertSee('title="Restore the group to change it"', false)->assertDontSee('name="members[]"', false);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000002'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['members' => [1000001]])->assertForbidden();
        $this->actingAs($this->admin)->patch(route('vatssa.groups.update', $this->group), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $this->admin->id, 'level' => 'members'])->assertForbidden();
        $this->assertSame([1000001], $this->memberCids());

        $this->actingAs($this->admin)->post(route('vatssa.groups.archive', $this->group), ['restore' => 1]);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000002'])->assertRedirect();
        $this->assertSame([1000001, 1000002], $this->memberCids());
    }

    #[Test]
    public function staff_change_a_managers_level_and_it_is_recorded(): void
    {
        $manager = $this->manager();
        $this->actingAs($this->admin)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $manager->id, 'level' => 'settings']);

        $this->assertTrue($this->group->fresh()->settingsEditableBy($manager));
        $this->assertSame(1, $this->group->currentManagers()->where('user_id', $manager->id)->count());
        $this->assertSame(1, $this->group->events()->where('event', 'manager_changed')->count());
        $this->actingAs($this->admin)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $manager->id, 'level' => 'owner'])->assertSessionHasErrors('level');
    }

    #[Test]
    public function the_page_names_who_else_can_manage_and_lists_only_current_members(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001, 1000002']);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['members' => [1000002]]);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000002']);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['members' => [1000002]]);

        $page = $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->assertOk()
            ->assertSee('Manage groups permission')->assertSee('No group managers yet')
            ->assertSee($this->admin->name)->assertDontSee(route('vatssa.groups.managers.remove', [$this->group, 0]), false);
        $this->assertSame(1, substr_count($page->getContent(), 'value="1000001"'));
        $this->assertSame(0, substr_count($page->getContent(), 'value="1000002"'));
    }

    #[Test]
    public function a_manager_who_never_signed_in_gets_a_clear_error(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => 9999999, 'level' => 'members'])
            ->assertSessionHasErrors(['user_id' => 'That CID has never signed in to Control Center, so they cannot manage a group yet.']);
    }

    #[Test]
    public function staff_who_are_also_group_managers_are_listed_once(): void
    {
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $this->admin->id]);
        $page = $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->getContent();

        $this->assertSame(0, substr_count($page, 'Manage groups permission (Administrator)'));
        $this->assertSame(1, substr_count($page, '<span class="text-muted">' . $this->admin->id . '</span>'));
    }

    #[Test]
    public function only_valid_vatsim_cids_are_accepted(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '101'])
            ->assertSessionHasErrors(['cids' => 'Not valid VATSIM CIDs: 101. A CID is a number from 800000 to 3000000 (or a VATSIM sandbox account, 10000001 to 10000011).']);
        $response = $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '101, 799999, 0800000, 3000001, 10000012, 800000, 2768227, 3000000, 10000001, 10000011']);
        $message = session('success');
        $this->assertStringContainsString('5 added', $message);
        $this->assertStringContainsString('Not valid CIDs, skipped: 101, 799999, 0800000, 3000001, 10000012.', $message);
        $this->assertSame([800000, 2768227, 3000000, 10000001, 10000011], $this->memberCids());
    }

    #[Test]
    public function members_are_listed_by_name_and_people_without_an_account_last(): void
    {
        $zed = User::factory()->create(['id' => 1000003, 'first_name' => 'Zed', 'last_name' => 'Last']);
        $amy = User::factory()->create(['id' => 1000009, 'first_name' => 'Amy', 'last_name' => 'First']);
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => "2000000, {$zed->id}, 900000, {$amy->id}"]);

        $page = $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->getContent();
        preg_match_all('/name="members\[\]" value="(\d+)"/', $page, $order);
        $this->assertSame(['1000009', '1000003', '900000', '2000000'], $order[1]);
    }
}
