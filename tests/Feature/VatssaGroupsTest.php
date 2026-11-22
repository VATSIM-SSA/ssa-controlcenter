<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupEvent;
use App\Models\VatssaGroupManager;
use App\Models\VatssaGroupMember;
use Illuminate\Database\UniqueConstraintViolationException;
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
        return (int) DB::table('vatssa_discord_queue')->where('user_id', $cid)->value('generation');
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
            ->assertSee('Manage groups permission')->assertSee('No managers appointed to this group yet')
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

    #[Test]
    public function staff_who_already_manage_every_group_cannot_be_added_as_managers(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $this->admin->id, 'level' => 'members'])
            ->assertSessionHasErrors(['user_id' => $this->admin->name . ' already manages every group through the Manage groups permission.']);
        $this->assertSame(0, $this->group->managers()->count());
    }

    /**
     * The confirm() texts as a browser runs them: attribute values with entities decoded.
     *
     * @return array<int, string>
     */
    private function confirmScripts(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $scripts = [];
        foreach ((new \DOMXPath($dom))->query('//@onsubmit | //@onclick') as $attribute) {
            if (str_contains($attribute->value, 'confirm(')) {
                $scripts[] = $attribute->value;
            }
        }

        return $scripts;
    }

    #[Test]
    public function names_with_quotes_cannot_break_out_of_the_confirm_pop_ups(): void
    {
        $evil = "x');alert(document.cookie);//";
        $this->group->update(['name' => $evil]);
        $manager = User::factory()->create(['id' => 1500002, 'first_name' => "O'Neil", 'last_name' => "x');alert(1);//"]);
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $manager->id]);
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1000001]);

        $scripts = $this->confirmScripts($this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->getContent());

        $this->assertNotEmpty($scripts);
        foreach ($scripts as $script) {
            $this->assertStringNotContainsString("');alert", $script, $script);
            $this->assertMatchesRegularExpression('/^return confirm\\(.*\\)$/s', $script);
        }
    }

    #[Test]
    public function a_settings_manager_cannot_demote_another_settings_manager(): void
    {
        $actor = $this->manager(true);
        $other = $this->manager(true);

        $this->actingAs($actor)->post(route('vatssa.groups.managers.add', $this->group), ['user_id' => $other->id, 'level' => 'members'])->assertForbidden();
        $this->assertTrue($this->group->fresh()->settingsEditableBy($other));
    }

    #[Test]
    public function group_names_are_unique_even_against_archived_groups(): void
    {
        VatssaGroup::create(['name' => 'Streamers', 'archived_at' => now()]);

        $this->actingAs($this->admin)->post(route('vatssa.groups.store'), ['name' => 'Streamers'])
            ->assertSessionHasErrors(['name' => 'A group with that name already exists (it may be archived).']);
        $this->actingAs($this->admin)->patch(route('vatssa.groups.update', $this->group), ['name' => 'Streamers'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->patch(route('vatssa.groups.update', $this->group), ['name' => 'SSA HitSquad', 'description' => 'same name is fine'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function erasure_removes_the_cid_from_history_and_every_by_field(): void
    {
        $staff = User::factory()->create(['id' => 1500003]);
        $group = VatssaGroup::create(['name' => 'Erase me', 'created_by' => $staff->id]);
        $group->forceFill(['archived_by' => $staff->id])->save();
        VatssaGroupMember::create(['group_id' => $group->id, 'user_id' => 1000001, 'added_by' => $staff->id, 'removed_by' => $staff->id]);
        VatssaGroupManager::create(['group_id' => $group->id, 'user_id' => 1000002, 'added_by' => $staff->id]);
        $group->record('member_added', $staff->id, null, $staff->id);

        $staff->delete();

        $this->assertSame(0, VatssaGroupEvent::where('user_id', $staff->id)->orWhere('by', $staff->id)->count());
        $this->assertSame(0, VatssaGroupMember::where('added_by', $staff->id)->orWhere('removed_by', $staff->id)->count());
        $this->assertSame(0, VatssaGroupManager::where('added_by', $staff->id)->count());
        $this->assertNull($group->fresh()->created_by);
        $this->assertNull($group->fresh()->archived_by);
        $this->assertSame(2, VatssaGroupMember::count() + VatssaGroupManager::count(), 'other people stay in the group');
    }

    #[Test]
    public function membership_changes_queue_a_sync_from_any_code_path(): void
    {
        $member = VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1000001]);
        $this->assertSame(1, $this->queued(1000001));

        $member->forceFill(['removed_at' => now()])->save();
        $this->assertSame(2, $this->queued(1000001));

        $member->touch();
        $this->assertSame(2, $this->queued(1000001), 'a save that changes nothing about membership queues nothing');
    }

    #[Test]
    public function the_history_says_when_it_is_cut_off(): void
    {
        foreach (range(1, 200) as $i) {
            $this->group->record('member_added', 1000000 + $i);
        }
        $this->actingAs($this->admin)->get(route('vatssa.groups.show', $this->group))->assertSee('Showing the latest 200 changes.');
    }

    #[Test]
    public function pseudonymising_a_user_clears_them_from_groups_too(): void
    {
        $user = User::factory()->create(['id' => 1500004]);
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => $user->id]);
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $user->id]);
        $this->group->record('member_added', $user->id, null, $user->id);

        $user->email = VatssaDiscordSyncController::PSEUDONYMISED_EMAIL;
        $user->save();

        $this->assertSame(0, VatssaGroupMember::where('user_id', $user->id)->count());
        $this->assertSame(0, VatssaGroupManager::where('user_id', $user->id)->count());
        $this->assertSame(0, VatssaGroupEvent::where('user_id', $user->id)->orWhere('by', $user->id)->count());
    }

    #[Test]
    public function a_name_with_a_comma_must_be_typed_in_full_to_delete(): void
    {
        $group = VatssaGroup::create(['name' => 'Events, 2026']);
        $group->forceFill(['archived_at' => now()])->save();

        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $group), ['confirm_name' => 'Events'])->assertSessionHasErrors('confirm_name');
        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $group), ['confirm_name' => ' 2026'])->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('vatssa_groups', ['id' => $group->id]);

        $this->actingAs($this->admin)->delete(route('vatssa.groups.destroy', $group), ['confirm_name' => 'Events, 2026'])->assertRedirect();
        $this->assertDatabaseMissing('vatssa_groups', ['id' => $group->id]);
    }

    #[Test]
    public function a_rename_without_a_description_does_not_record_one(): void
    {
        $this->group->update(['description' => 'Event support']);
        $this->actingAs($this->admin)->patch(route('vatssa.groups.update', $this->group), ['name' => 'Renamed only']);

        $this->assertSame('Event support', $this->group->fresh()->description);
        $this->assertSame(0, $this->group->events()->where('event', 'described')->count());
        $this->assertSame(1, $this->group->events()->where('event', 'renamed')->count());
    }

    #[Test]
    public function removal_reports_cids_it_skipped(): void
    {
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001']);

        $this->actingAs($this->admin)->post(route('vatssa.groups.members.remove', $this->group), ['cids' => '1000001, 1000002, 101']);
        $message = session('success');
        $this->assertStringContainsString('1 removed.', $message);
        $this->assertStringContainsString('Not in the group: 1000002.', $message);
        $this->assertStringContainsString('Not valid CIDs, skipped: 101.', $message);
    }

    #[Test]
    public function the_database_refuses_a_second_current_membership_or_management(): void
    {
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1000001]);
        try {
            VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1000001]);
            $this->fail('a second current membership was stored');
        } catch (UniqueConstraintViolationException) {
        }

        VatssaGroupMember::where('user_id', 1000001)->update(['removed_at' => now()]);
        VatssaGroupMember::create(['group_id' => $this->group->id, 'user_id' => 1000001]); // re-adding after removal is fine
        $this->assertSame(2, VatssaGroupMember::where('user_id', 1000001)->count());

        $manager = User::factory()->create(['id' => 1500005]);
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $manager->id]);
        $this->expectException(UniqueConstraintViolationException::class);
        VatssaGroupManager::create(['group_id' => $this->group->id, 'user_id' => $manager->id]);
    }

    #[Test]
    public function the_database_refuses_a_duplicate_group_name(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);
        VatssaGroup::create(['name' => 'SSA HitSquad']);
    }

    #[Test]
    public function a_change_re_checks_the_stored_group_not_the_page_it_came_from(): void
    {
        $manager = $this->manager();
        $stale = VatssaGroup::find($this->group->id);
        VatssaGroup::whereKey($this->group->id)->update(['archived_at' => now()]);

        // The route model is loaded fresh per request, so prove the lock helper reads the
        // stored state: archive directly, then try as staff (allowed only when not archived).
        $this->assertFalse($stale->isArchived());
        $this->actingAs($this->admin)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001'])->assertForbidden();
        $this->actingAs($manager)->post(route('vatssa.groups.members.add', $this->group), ['cids' => '1000001'])->assertForbidden();
        $this->assertSame([], $this->memberCids());
    }
}
