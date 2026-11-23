<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Livewire\VatssaDiscordMembers;
use App\Livewire\VatssaDiscordRuleEditor;
use App\Livewire\VatssaDiscordRuleList;
use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupMember;
use App\Services\PermissionMatrix;
use App\Services\VatssaDiscordRules;
use App\Services\VatssaDiscordRuleStore;
use App\Services\VatssaDiscordState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: CC decides Discord roles through rules; the bot applies them.
class VatssaDiscordRulesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token-0123456789abcdefghijklmnop';

    private const D = '100000000000000001';

    private const ROLE = '200000000000000001';

    private const ROLE2 = '200000000000000002';

    private const ROLES = ['not_vatsim' => '1552239343182024775', 'visitor' => '986216721046175785', 'member' => '986216672685867038',
        'trainee' => '1037453067303526421', 'mentor' => '986212946487148574', 'examiner' => '986213129891504158', 'solo' => '1552240522285023232',
        'fss' => '986213832294805504', 'approved' => '1037376361137979442', 'gnd' => '986213502253428816', 'twr' => '986213753718722571',
        'app' => '986213568892506153', 'ctr' => '986213786690158634', 'adm' => '1496139792897933392', 'sus_ina' => '1572712830019440793'];

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.mode' => 'division', 'app.owner_code' => 'SSA', 'vatssa.discord_bot_token' => self::TOKEN]);
        $this->area = Area::factory()->create();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);

        return $admin->fresh();
    }

    private function person(int $rating = 3, string $division = 'SSA', bool $roster = false): User
    {
        $user = User::factory()->create(['rating' => $rating, 'division' => $division]);
        if ($roster) {
            AtcActivity::create(['user_id' => $user->id, 'area_id' => $this->area->id, 'hours' => 50, 'atc_active' => true]);
        }

        return $user;
    }

    private function endorse(User $user, string $type, ?string $rating = null): void
    {
        $endorsement = Endorsement::factory()->create(['user_id' => $user->id, 'type' => $type, 'revoked' => false, 'expired' => false, 'valid_to' => null]);
        if ($rating) {
            $endorsement->ratings()->attach((Rating::where('name', $rating)->first() ?? Rating::forceCreate(['name' => $rating, 'description' => $rating]))->id);
        }
    }

    private function rule(array $conditions, array $roles = [self::ROLE], bool $enabled = true, string $name = 'Test'): int
    {
        $id = DB::table('vatssa_discord_rules')->insertGetId(['name' => $name, 'enabled' => $enabled, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($conditions as $c) {
            DB::table('vatssa_discord_rule_conditions')->insert(['rule_id' => $id, 'type' => $c[0], 'params' => json_encode($c[1] ?? []), 'negated' => $c[2] ?? false]);
        }
        foreach ($roles as $role) {
            DB::table('vatssa_discord_rule_roles')->insert(['rule_id' => $id, 'discord_role_id' => $role]);
        }

        return $id;
    }

    private function rolesFor(int $cid): ?array
    {
        $answer = app(VatssaDiscordRules::class)->evaluate([['discord_id' => self::D, 'cid' => $cid]]);

        return $answer['roles'][self::D] ?? null;
    }

    private function seeded(int $cid): array
    {
        $names = array_flip(self::ROLES);

        return collect($this->rolesFor($cid))->map(fn ($id) => $names[$id])->sort()->values()->all();
    }

    private function roleOnServer(string $id, string $name = 'Role', bool $assignable = true): void
    {
        DB::table('vatssa_discord_guild_roles')->updateOrInsert(['discord_role_id' => $id], ['name' => $name, 'assignable' => $assignable]);
    }

    private function body(array $over = []): array
    {
        return [...['name' => 'New rule', 'conditions' => [['type' => 'division', 'division' => 'home']], 'roles' => [self::ROLE]], ...$over];
    }

    #[Test]
    public function the_starting_rules_match_the_old_bot_exactly(): void
    {
        $this->artisan('vatssa:discord-seed-rules')->assertSuccessful();

        $this->assertSame(['not_vatsim'], $this->seeded(0));

        $this->assertSame(['member'], $this->seeded($this->person(1)->id)); // OBS, no roster
        $this->assertSame(['member'], $this->seeded($this->person(3)->id)); // rated, off the roster
        $this->assertSame(['approved', 'gnd', 'member'], $this->seeded($this->person(2, 'SSA', true)->id));
        $this->assertSame(['approved', 'member', 'twr'], $this->seeded($this->person(3, 'SSA', true)->id));
        $this->assertSame(['app', 'approved', 'member'], $this->seeded($this->person(4, 'SSA', true)->id));
        $this->assertSame(['approved', 'ctr', 'member'], $this->seeded($this->person(5, 'SSA', true)->id));
        $this->assertSame(['approved', 'ctr', 'member'], $this->seeded($this->person(10, 'SSA', true)->id));
        $this->assertSame(['adm', 'approved', 'ctr', 'member'], $this->seeded($this->person(12, 'SSA', true)->id));
        $this->assertSame(['approved', 'member', 'sus_ina'], $this->seeded($this->person(0, 'SSA', true)->id)); // no ladder role
        $this->assertSame(['member', 'sus_ina'], $this->seeded($this->person(-1)->id));

        $this->assertSame(['visitor'], $this->seeded($this->person(5, 'EUD', true)->id)); // the roster alone means nothing

        $visitor = $this->person(5, 'EUD');
        $this->endorse($visitor, 'VISITING', 'S3');
        $this->assertSame(['app', 'approved', 'visitor'], $this->seeded($visitor->id)); // C1 with S3 visiting is APP

        $higher = $this->person(7, 'EUD');
        $this->endorse($higher, 'VISITING', 'S2');
        $this->endorse($higher, 'VISITING', 'C1');
        $this->assertSame(['approved', 'ctr', 'visitor'], $this->seeded($higher->id));

        $full = $this->person(4, 'SSA', true);
        $this->endorse($full, 'EXAMINER', 'S2');
        $this->endorse($full, 'SOLO', 'S3');
        $this->endorse($full, 'FACILITY', 'AFRx FSS');
        $full->roleAssignments()->create(['role' => 'mentor', 'area_id' => $this->area->id]);
        $training = Training::factory()->create(['user_id' => $full->id, 'area_id' => $this->area->id, 'status' => 0, 'type' => 1]);
        $withoutTrainee = ['app', 'approved', 'examiner', 'fss', 'member', 'mentor', 'solo'];
        $this->assertSame($withoutTrainee, $this->seeded($full->fresh()->id)); // the waiting list is not training

        $training->update(['status' => 1, 'type' => 5]);
        $this->assertSame($withoutTrainee, $this->seeded($full->fresh()->id)); // nor is familiarisation

        $training->update(['type' => 1]);
        $this->assertSame([...$withoutTrainee, 'trainee'], $this->seeded($full->fresh()->id));
    }

    #[Test]
    public function seeding_refuses_when_rules_exist(): void
    {
        $this->rule([['not_vatsim']]);
        $this->artisan('vatssa:discord-seed-rules')->assertFailed();
        $this->assertSame(1, DB::table('vatssa_discord_rules')->count());
    }

    public static function conditions(): array
    {
        return [
            'rating in' => [['rating', ['mode' => 'in', 'values' => [3]]], true],
            'rating not in' => [['rating', ['mode' => 'in', 'values' => [4]]], false],
            'rating min' => [['rating', ['mode' => 'min', 'value' => 3]], true],
            'rating max' => [['rating', ['mode' => 'max', 'value' => 2]], false],
            'home' => [['division', ['value' => 'home']], true],
            'visitor' => [['division', ['value' => 'visitor']], false],
            'unknown type' => [['nonsense', []], false],
        ];
    }

    #[Test]
    #[DataProvider('conditions')]
    public function each_condition_is_evaluated(array $condition, bool $expected): void
    {
        $this->rule([$condition]);
        $this->assertSame($expected ? [self::ROLE] : [], $this->rolesFor($this->person(3)->id));
    }

    #[Test]
    public function all_conditions_must_hold_and_any_rule_gives_the_role(): void
    {
        $user = $this->person(3, 'SSA', true);
        $this->rule([['division', ['value' => 'home']], ['rating', ['mode' => 'in', 'values' => [4]]]], [self::ROLE], true, 'A');
        $this->assertSame([], $this->rolesFor($user->id));

        $this->rule([['roster', ['area' => 'any']]], [self::ROLE, self::ROLE2], true, 'B');
        $this->assertEqualsCanonicalizing([self::ROLE, self::ROLE2], $this->rolesFor($user->id));
    }

    #[Test]
    public function negation_off_and_empty_rules_behave(): void
    {
        $user = $this->person(3);
        $this->rule([['roster', ['area' => 'any'], true]], [self::ROLE], true, 'Not rostered');
        $this->rule([['division', ['value' => 'home']]], [self::ROLE2], false, 'Off');
        $this->rule([], ['200000000000000003'], true, 'No conditions');

        $this->assertSame([self::ROLE], $this->rolesFor($user->id));
    }

    #[Test]
    public function an_account_with_no_vatsim_link_never_matches_a_negated_condition(): void
    {
        $this->rule([['roster', ['area' => 'any'], true]], [self::ROLE], true, 'Negated');
        $this->rule([['not_vatsim', [], true]], [self::ROLE2], true, 'Negated not vatsim');
        $this->assertSame([], $this->rolesFor(0));
    }

    #[Test]
    public function groups_areas_and_cc_roles_count(): void
    {
        $user = $this->person(3);
        $group = VatssaGroup::create(['name' => 'Streamers']);
        VatssaGroupMember::create(['group_id' => $group->id, 'user_id' => $user->id]);
        $other = Area::factory()->create();
        $user->roleAssignments()->create(['role' => 'mentor', 'area_id' => $this->area->id]);

        $this->rule([['group', ['group' => $group->id]]], [self::ROLE], true, 'G');
        $this->rule([['cc_role', ['role' => 'mentor', 'area' => $other->id]]], [self::ROLE2], true, 'Wrong area');
        $this->assertSame([self::ROLE], $this->rolesFor($user->id));

        DB::table('vatssa_groups')->where('id', $group->id)->update(['archived_at' => now()]);
        $this->assertSame([], $this->rolesFor($user->id));
    }

    #[Test]
    public function a_group_member_with_no_cc_account_gets_the_role(): void
    {
        $group = VatssaGroup::create(['name' => 'Hit Squad']);
        VatssaGroupMember::create(['group_id' => $group->id, 'user_id' => 2000000]);
        DB::table('vatssa_vatsim_members')->insert(['cid' => 2000000, 'rating' => 3, 'division' => 'EUD', 'source' => 'lookup', 'needs_lookup' => false, 'fetched_at' => now()]);
        $this->rule([['group', ['group' => $group->id]]]);

        $this->assertSame([self::ROLE], $this->rolesFor(2000000));
    }

    #[Test]
    public function an_expired_or_revoked_endorsement_does_not_count(): void
    {
        $user = $this->person(3);
        Endorsement::factory()->create(['user_id' => $user->id, 'type' => 'SOLO', 'revoked' => true, 'expired' => false]);
        Endorsement::factory()->create(['user_id' => $user->id, 'type' => 'SOLO', 'revoked' => false, 'expired' => false, 'valid_to' => now()->subDay()]);
        $this->rule([['endorsement', ['type' => 'SOLO']]]);

        $this->assertSame([], $this->rolesFor($user->id));
    }

    #[Test]
    public function no_vatsim_answer_is_unknown_and_changes_nothing(): void
    {
        $this->rule([['rating', ['mode' => 'min', 'value' => -1]]]);
        $answer = app(VatssaDiscordRules::class)->evaluate([['discord_id' => self::D, 'cid' => 2000001]]);

        $this->assertSame([self::D], $answer['unknown']);
        $this->assertArrayNotHasKey(self::D, $answer['roles']);
        $this->assertSame(1, (int) DB::table('vatssa_vatsim_members')->where('cid', 2000001)->value('needs_lookup'));
    }

    #[Test]
    public function an_erased_member_gets_no_managed_roles(): void
    {
        $user = $this->person(3);
        $user->email = Sync::PSEUDONYMISED_EMAIL;
        $user->save();
        $this->rule([['division', ['value' => 'home']]]);

        $this->assertSame([], $this->rolesFor($user->id));
    }

    #[Test]
    public function the_bot_api_needs_its_token_and_answers_with_strings(): void
    {
        $user = $this->person(3);
        $this->rule([['division', ['value' => 'home']]]);
        $body = ['accounts' => [['discord_id' => self::D, 'cid' => $user->id]]];

        $this->postJson(route('api.vatssa.discord-roles'), $body)->assertUnauthorized();
        $this->postJson(route('api.vatssa.discord-roles'), $body, ['Authorization' => 'Bearer ' . self::TOKEN])->assertOk()
            ->assertJsonPath('managed', [self::ROLE])->assertJsonPath('roles.' . self::D, [self::ROLE]);
    }

    #[Test]
    public function the_bot_reports_server_roles_and_missing_ones_go(): void
    {
        $auth = ['Authorization' => 'Bearer ' . self::TOKEN];
        $role = fn ($id, $name) => ['id' => $id, 'name' => $name, 'colour' => 5, 'position' => 2, 'assignable' => true];
        $url = route('api.vatssa.discord-guild-roles');

        $this->postJson($url, ['roles' => [$role(self::ROLE, 'A'), $role(self::ROLE2, 'B')]])->assertUnauthorized();
        $this->postJson($url, ['roles' => []], $auth)->assertUnprocessable();
        $this->postJson($url, ['roles' => [$role(self::ROLE, 'A'), $role(self::ROLE2, 'B')]], $auth)->assertNoContent();
        $this->postJson($url, ['roles' => [$role(self::ROLE, 'A2')]], $auth)->assertNoContent();

        $this->assertSame([self::ROLE => 'A2'], DB::table('vatssa_discord_guild_roles')->pluck('name', 'discord_role_id')->mapWithKeys(fn ($n, $k) => [(string) $k => $n])->all());
    }

    #[Test]
    public function the_rule_page_is_guarded(): void
    {
        $mentor = User::factory()->create();
        $mentor->roleAssignments()->create(['role' => 'mentor', 'area_id' => $this->area->id]);
        $this->actingAs($mentor->fresh())->get(route('vatssa.discord.rules.index'))->assertForbidden();
        $this->actingAs($mentor)->get(route('vatssa.discord.rules.create'))->assertForbidden();
        Livewire::actingAs($mentor)->test(VatssaDiscordRuleList::class)->assertForbidden();
        Livewire::actingAs($mentor)->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])->assertForbidden();

        $this->actingAs($this->admin())->get(route('vatssa.discord.rules.index'))->assertOk();
    }

    #[Test]
    public function staff_build_a_rule_and_it_is_audited_and_queued(): void
    {
        $this->roleOnServer(self::ROLE, 'Streamer');
        $member = $this->person(3);
        DB::table('vatssa_discord_members')->insert(['discord_id' => self::D, 'user_id' => $member->id, 'vatsim_linked' => true, 'last_seen_at' => now()]);
        DB::table('vatssa_discord_queue')->delete();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('vatssa.discord.rules.create'))->assertOk()->assertSee('Streamer');
        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])
            ->set('name', 'New rule')->set('roles', [self::ROLE])->call('save')->assertHasNoErrors()->assertRedirect(route('vatssa.discord.rules.index'));

        $this->assertSame([self::ROLE], $this->rolesFor($member->id));
        $this->assertSame('created', DB::table('vatssa_discord_rule_audit')->value('action'));
        $this->assertSame('rule', DB::table('vatssa_discord_queue')->where('user_id', $member->id)->value('reason'));
        $this->actingAs($admin)->get(route('vatssa.discord.rules.index'))->assertSee('Home member');
    }

    #[Test]
    public function bad_rules_are_refused(): void
    {
        $this->roleOnServer(self::ROLE);
        $this->roleOnServer(self::ROLE2, 'Above the bot', false);
        $admin = $this->admin();
        $editor = fn () => Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])->set('name', 'Bad');

        $editor()->set('roles', [])->call('save')->assertHasErrors('roles');
        $editor()->set('roles', [self::ROLE2])->call('save')->assertHasErrors('roles');
        $editor()->set('roles', ['200000000000000009'])->call('save')->assertHasErrors('roles');
        $editor()->set('roles', [self::ROLE])->set('conditions', [])->call('save')->assertHasErrors('conditions');
        foreach ([['type' => 'hacked'], ['division' => 'x'], ['type' => 'rating', 'rating_mode' => 'in', 'ratings' => ['99']],
            ['type' => 'group', 'group' => '999'], ['type' => 'cc_role', 'role' => 'nope']] as $over) {
            $row = app(VatssaDiscordRuleStore::class)->blankRow();
            $editor()->set('roles', [self::ROLE])->set('conditions', [[...$row, ...$over]])->call('save')->assertHasErrors();
        }
        $this->assertSame(0, DB::table('vatssa_discord_rules')->count());
    }

    #[Test]
    public function deleting_keeps_the_role_by_default_and_can_remove_it(): void
    {
        $this->roleOnServer(self::ROLE);
        $admin = $this->admin();

        $keep = $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'Keep');
        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $keep])->set('confirmingDelete', true)->call('delete')->assertRedirect();
        $this->assertSame([], app(VatssaDiscordRules::class)->managed()); // no longer touched

        $remove = $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'Remove');
        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $remove])->set('confirmingDelete', true)->set('dropped', 'remove')->call('delete')->assertRedirect();
        $this->assertSame([self::ROLE], app(VatssaDiscordRules::class)->managed()); // managed, wanted by nobody
        $this->assertSame([], $this->rolesFor($this->person(3)->id));
    }

    #[Test]
    public function a_change_can_be_restored(): void
    {
        $this->roleOnServer(self::ROLE);
        $this->roleOnServer(self::ROLE2);
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])->set('name', 'New rule')->set('roles', [self::ROLE])->call('save');
        $id = (int) DB::table('vatssa_discord_rules')->value('id');

        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id])->set('name', 'Changed')->set('roles', [self::ROLE2])->call('save')->assertHasNoErrors();
        $list = Livewire::actingAs($admin)->test(VatssaDiscordRuleList::class);
        $list->call('restore', (int) DB::table('vatssa_discord_rule_audit')->max('id'));
        $this->assertSame('New rule', DB::table('vatssa_discord_rules')->where('id', $id)->value('name'));
        $this->assertSame(self::ROLE, (string) DB::table('vatssa_discord_rule_roles')->where('rule_id', $id)->value('discord_role_id'));

        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id])->set('confirmingDelete', true)->call('delete');
        Livewire::actingAs($admin)->test(VatssaDiscordRuleList::class)->call('restore', (int) DB::table('vatssa_discord_rule_audit')->max('id'));
        $this->assertSame('New rule', DB::table('vatssa_discord_rules')->where('id', $id)->value('name'));
    }

    #[Test]
    public function the_edit_page_loads_an_existing_rule_and_keeps_its_conditions(): void
    {
        $id = $this->rule([['rating', ['mode' => 'min', 'value' => 5], true]]);
        $this->roleOnServer(self::ROLE);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('vatssa.discord.rules.edit', $id))->assertOk()->assertSee('Save rule');

        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id])->call('save')->assertHasNoErrors();
        $saved = DB::table('vatssa_discord_rule_conditions')->where('rule_id', $id)->first();
        $this->assertSame(['mode' => 'min', 'value' => 5], json_decode($saved->params, true));
        $this->assertSame(1, (int) $saved->negated);
    }

    #[Test]
    public function a_joiner_with_a_cid_is_queued_and_one_with_none_is_flagged(): void
    {
        $auth = ['Authorization' => 'Bearer ' . self::TOKEN];
        $member = $this->person(3);
        DB::table('vatssa_discord_queue')->delete();
        $send = fn ($id, $cid, $complete = false) => $this->postJson(route('api.vatssa.discord-members'), ['complete' => $complete, 'at' => microtime(true),
            'members' => [['discord_id' => $id, 'cid' => $cid, 'name' => 'x', 'bot' => false]]], $auth)->assertNoContent();

        $send(self::D, $member->id);
        $this->assertSame('join', DB::table('vatssa_discord_queue')->where('user_id', $member->id)->value('reason'));

        $send('100000000000000002', 0);
        $claim = $this->postJson(route('api.vatssa.discord-queue.claim'), [], $auth)->assertOk();
        $claim->assertJsonPath('data.0.cid', $member->id)->assertJsonPath('accounts', ['100000000000000002']);
        $this->postJson(route('api.vatssa.discord-queue.claim'), [], $auth)->assertJsonPath('accounts', []);

        DB::table('vatssa_discord_queue')->delete();
        $send(self::D, $member->id); // nothing changed: no new queue row
        $this->assertSame(0, DB::table('vatssa_discord_queue')->count());
    }

    #[Test]
    public function the_page_shows_the_bot_and_approval_reaches_it_once(): void
    {
        $auth = ['Authorization' => 'Bearer ' . self::TOKEN];
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(VatssaDiscordRuleList::class)->assertSee('Bot offline');

        $status = ['mode' => 'live', 'paused_removals' => true, 'paused_adds' => false, 'frozen' => 1];
        $this->postJson(route('api.vatssa.discord-queue.claim'), ['status' => $status], $auth)->assertJsonPath('approve', false);
        $list = Livewire::actingAs($admin)->test(VatssaDiscordRuleList::class)->assertSee('Paused by a safety limit')->assertSee('Approve and resume');

        $list->call('approve');
        $this->postJson(route('api.vatssa.discord-queue.claim'), ['status' => $status], $auth)->assertJsonPath('approve', true);
        $this->postJson(route('api.vatssa.discord-queue.claim'), ['status' => $status], $auth)->assertJsonPath('approve', false);

        $this->travel(5)->minutes();
        Livewire::actingAs($admin)->test(VatssaDiscordRuleList::class)->assertSee('Bot offline');
    }

    #[Test]
    public function a_viewer_without_manage_cannot_change_anything(): void
    {
        $id = $this->rule([['division', ['value' => 'home']]]);
        $viewer = User::factory()->create();
        $viewer->roleAssignments()->create(['role' => 'mentor', 'area_id' => $this->area->id]);
        config(['roles.matrix.mentor' => [...config('roles.matrix.mentor'), 'vatssa.discord.rules.view']]);
        app()->forgetInstance(PermissionMatrix::class);

        $list = Livewire::actingAs($viewer->fresh())->test(VatssaDiscordRuleList::class)->assertOk()->assertDontSee('New rule');
        $list->call('toggle', $id)->assertForbidden();
        Livewire::actingAs($viewer->fresh())->test(VatssaDiscordRuleList::class)->call('approve')->assertForbidden();
        $this->assertSame(1, (int) DB::table('vatssa_discord_rules')->where('id', $id)->value('enabled'));
    }

    #[Test]
    public function the_rule_list_groups_by_discord_role_and_searches(): void
    {
        $this->roleOnServer(self::ROLE, 'Approved Controller');
        $this->roleOnServer(self::ROLE2, 'Streamer');
        $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'Approved home');
        $this->rule([['division', ['value' => 'visitor']]], [self::ROLE], true, 'Approved visitor');
        $this->rule([['division', ['value' => 'home']]], [self::ROLE2], true, 'Stream');

        $list = Livewire::actingAs($this->admin())->test(VatssaDiscordRuleList::class)->assertSee('Approved home')->assertSee('Approved visitor');
        $this->assertSame(2, substr_count($list->html(), 'class="vd-role"'));
        $list->set('search', 'streamer')->assertSee('Stream')->assertDontSee('Approved home');
    }

    #[Test]
    public function the_profile_shows_roles_and_what_is_waiting(): void
    {
        $this->roleOnServer(self::ROLE, 'Should have');
        $this->roleOnServer(self::ROLE2, 'Should lose');
        $member = $this->person(3);
        $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'A');
        $this->rule([['division', ['value' => 'visitor']]], [self::ROLE2], true, 'B');
        DB::table('vatssa_discord_members')->insert(['discord_id' => self::D, 'user_id' => $member->id, 'vatsim_linked' => true,
            'in_server' => true, 'role_ids' => json_encode([self::ROLE2]), 'last_seen_at' => now()]);

        $this->actingAs($this->admin())->get(route('user.show', $member->id))->assertOk()
            ->assertSee('Waiting to add: Should have')->assertSee('Waiting to remove: Should lose');
    }

    #[Test]
    public function the_members_page_filters_searches_and_keeps_long_ids_exact(): void
    {
        DB::table('vatssa_discord_members')->insert([
            ['discord_id' => self::D, 'name' => '<b>x</b>', 'vatsim_linked' => false, 'is_bot' => false, 'in_server' => true, 'last_seen_at' => now()],
            ['discord_id' => '100000000000000002', 'name' => 'robot', 'vatsim_linked' => false, 'is_bot' => true, 'in_server' => true, 'last_seen_at' => now()],
        ]);
        DB::table('vatssa_discord_member_history')->insert(['discord_id' => self::D, 'event' => 'joined', 'created_at' => now()]);

        $page = Livewire::actingAs($this->admin())->test(VatssaDiscordMembers::class)->assertSee(self::D)->assertDontSeeHtml('<b>x</b>');
        $page->call('openHistory', self::D)->assertSee('Joined the server')->call('closeHistory')->assertDontSee('Joined the server');
        $page->call('setTab', 'bots')->assertSee('robot')->assertDontSee(self::D);
        $page->call('setTab', 'server')->set('search', 'robot')->assertDontSee(self::D);
        $page->call('openHistory', 'not-an-id')->assertNotFound();
    }

    #[Test]
    public function a_negated_condition_on_something_gone_gives_nothing(): void
    {
        $user = $this->person(3);
        $group = VatssaGroup::create(['name' => 'Going']);
        $this->rule([['group', ['group' => $group->id], true]], [self::ROLE], true, 'Not in group');
        $this->rule([['nonsense', [], true]], [self::ROLE2], true, 'Not unknown');
        $this->assertSame([self::ROLE], $this->rolesFor($user->id));

        DB::table('vatssa_groups')->where('id', $group->id)->update(['archived_at' => now()]);
        $this->assertSame([], $this->rolesFor($user->id));

        DB::table('vatssa_groups')->where('id', $group->id)->delete();
        $this->assertSame([], $this->rolesFor($user->id));
    }

    #[Test]
    public function a_retired_role_stops_being_managed_once_nobody_has_it(): void
    {
        $this->roleOnServer(self::ROLE);
        DB::table('vatssa_discord_guild_roles')->where('discord_role_id', self::ROLE)->update(['retired' => true]);
        $auth = ['Authorization' => 'Bearer ' . self::TOKEN];
        $send = fn (array $roles) => $this->postJson(route('api.vatssa.discord-members'), ['complete' => true, 'at' => microtime(true),
            'members' => [['discord_id' => self::D, 'cid' => 0, 'name' => 'x', 'roles' => $roles]]], $auth)->assertNoContent();

        $send([self::ROLE]);
        $this->assertSame([self::ROLE], app(VatssaDiscordRules::class)->managed());

        $send([]);
        $this->assertSame([], app(VatssaDiscordRules::class)->managed());
    }

    #[Test]
    public function changing_type_clears_a_global_area(): void
    {
        Livewire::actingAs($this->admin())->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])
            ->set('conditions.0.type', 'cc_role')->set('conditions.0.area', 'global')
            ->set('conditions.0.type', 'roster')->assertSet('conditions.0.area', 'any');
    }

    #[Test]
    public function the_editor_shows_an_archived_or_deleted_group_as_it_is(): void
    {
        VatssaGroup::create(['name' => 'Active']);
        $old = VatssaGroup::create(['name' => 'Old']);
        DB::table('vatssa_groups')->where('id', $old->id)->update(['archived_at' => now()]);
        $id = $this->rule([['group', ['group' => $old->id]]]);
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id])->assertSee('Old (archived)');

        DB::table('vatssa_groups')->where('id', $old->id)->delete();
        Livewire::actingAs($admin)->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id])->assertSee('(deleted group)');
    }

    #[Test]
    public function saving_a_rule_deleted_elsewhere_is_a_404(): void
    {
        $this->roleOnServer(self::ROLE);
        $id = $this->rule([['division', ['value' => 'home']]]);
        $editor = Livewire::actingAs($this->admin())->test(VatssaDiscordRuleEditor::class, ['ruleId' => $id]);

        DB::table('vatssa_discord_rules')->where('id', $id)->delete();
        $editor->call('save')->assertNotFound();
    }

    #[Test]
    public function an_old_approval_is_not_acted_on(): void
    {
        VatssaDiscordState::put(Sync::BOT_APPROVE, ['by' => 1, 'until' => now()->subMinute()->timestamp]);

        $this->postJson(route('api.vatssa.discord-queue.claim'), [], ['Authorization' => 'Bearer ' . self::TOKEN])->assertJsonPath('approve', false);
    }

    #[Test]
    public function searching_roles_keeps_picks_and_reads_no_members(): void
    {
        $this->roleOnServer(self::ROLE, 'Streamer');
        $this->roleOnServer(self::ROLE2, 'Examiner');
        $editor = Livewire::actingAs($this->admin())->test(VatssaDiscordRuleEditor::class, ['ruleId' => null])
            ->set('name', 'Search')->set('roles', [self::ROLE]);

        DB::enableQueryLog();
        $editor->set('roleSearch', 'exam')->assertSee('Examiner')->assertDontSee('Streamer');
        $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'vatssa_discord_members'))->all());

        $editor->set('roleSearch', '')->assertSet('roles', [self::ROLE])->assertSee('Streamer');
    }

    #[Test]
    public function discord_links_sit_in_the_users_and_administration_menus_for_admins_only(): void
    {
        $html = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();
        $page = new \DOMDocument;
        @$page->loadHTML($html);
        $links = new \DOMXPath($page);

        $this->assertSame(1, $links->query('//*[@id="collapseMem"]//a[contains(., "Discord members")]')->length);
        $this->assertSame(1, $links->query('//*[@id="collapseUtilities"]//a[contains(., "Discord rules")]')->length);
        $this->assertSame(1, $links->query('//a[contains(., "Discord members")]')->length);
        $this->assertSame(1, $links->query('//a[contains(., "Discord rules")]')->length);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()
            ->assertDontSee('Discord members')->assertDontSee('Discord rules');
    }
}
