<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use App\Models\VatssaGroup;
use App\Models\VatssaGroupMember;
use App\Services\VatssaDiscordRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        Training::factory()->create(['user_id' => $full->id, 'area_id' => $this->area->id, 'status' => 0]);
        $this->assertSame(['app', 'approved', 'examiner', 'fss', 'member', 'mentor', 'solo', 'trainee'], $this->seeded($full->fresh()->id));
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
    public function an_erased_member_is_unknown(): void
    {
        $user = $this->person(3);
        $user->email = Sync::PSEUDONYMISED_EMAIL;
        $user->save();
        $this->rule([['division', ['value' => 'home']]]);

        $this->assertNull($this->rolesFor($user->id));
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
        $this->actingAs($mentor)->post(route('vatssa.discord.rules.store'), $this->body())->assertForbidden();

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
        $this->actingAs($admin)->post(route('vatssa.discord.rules.store'), $this->body())->assertRedirect(route('vatssa.discord.rules.index'));

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
        $bad = [
            ['conditions' => []], ['roles' => []], ['roles' => [self::ROLE2]], ['roles' => ['200000000000000009']],
            ['conditions' => [['type' => 'hacked']]], ['conditions' => [['type' => 'division', 'division' => 'x']]],
            ['conditions' => [['type' => 'rating', 'rating_mode' => 'in', 'ratings' => [99]]]],
            ['conditions' => [['type' => 'group', 'group' => 999]]],
            ['conditions' => [['type' => 'cc_role', 'role' => 'nope']]],
        ];
        foreach ($bad as $over) {
            $this->actingAs($admin)->post(route('vatssa.discord.rules.store'), $this->body($over))->assertSessionHasErrors();
        }
        $this->assertSame(0, DB::table('vatssa_discord_rules')->count());
    }

    #[Test]
    public function the_preview_counts_what_a_save_would_do(): void
    {
        $this->roleOnServer(self::ROLE, 'Streamer');
        $member = $this->person(3);
        DB::table('vatssa_discord_members')->insert(['discord_id' => self::D, 'user_id' => $member->id, 'vatsim_linked' => true, 'role_ids' => '[]', 'last_seen_at' => now()]);

        $this->actingAs($this->admin())->postJson(route('vatssa.discord.rules.preview'), $this->body())->assertOk()
            ->assertJsonPath('adds.0.role', 'Streamer')->assertJsonPath('adds.0.members', 1)->assertJsonPath('counted', 1);
        $this->assertSame(0, DB::table('vatssa_discord_rules')->count());
    }

    #[Test]
    public function deleting_keeps_the_role_by_default_and_can_remove_it(): void
    {
        $this->roleOnServer(self::ROLE);
        $admin = $this->admin();

        $keep = $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'Keep');
        $this->actingAs($admin)->delete(route('vatssa.discord.rules.destroy', $keep))->assertRedirect();
        $this->assertSame([], app(VatssaDiscordRules::class)->managed()); // no longer touched

        $remove = $this->rule([['division', ['value' => 'home']]], [self::ROLE], true, 'Remove');
        $this->actingAs($admin)->delete(route('vatssa.discord.rules.destroy', $remove), ['dropped' => 'remove'])->assertRedirect();
        $this->assertSame([self::ROLE], app(VatssaDiscordRules::class)->managed()); // managed, wanted by nobody
        $this->assertSame([], $this->rolesFor($this->person(3)->id));
    }

    #[Test]
    public function a_change_can_be_restored(): void
    {
        $this->roleOnServer(self::ROLE);
        $this->roleOnServer(self::ROLE2);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('vatssa.discord.rules.store'), $this->body());
        $id = DB::table('vatssa_discord_rules')->value('id');

        $this->actingAs($admin)->patch(route('vatssa.discord.rules.update', $id), $this->body(['name' => 'Changed', 'roles' => [self::ROLE2]]))->assertRedirect();
        $this->actingAs($admin)->post(route('vatssa.discord.rules.restore', DB::table('vatssa_discord_rule_audit')->max('id')))->assertRedirect();
        $this->assertSame('New rule', DB::table('vatssa_discord_rules')->where('id', $id)->value('name'));
        $this->assertSame(self::ROLE, (string) DB::table('vatssa_discord_rule_roles')->where('rule_id', $id)->value('discord_role_id'));

        $this->actingAs($admin)->delete(route('vatssa.discord.rules.destroy', $id));
        $this->actingAs($admin)->post(route('vatssa.discord.rules.restore', DB::table('vatssa_discord_rule_audit')->max('id')))->assertRedirect();
        $this->assertSame('New rule', DB::table('vatssa_discord_rules')->where('id', $id)->value('name'));
    }

    #[Test]
    public function the_edit_page_loads_an_existing_rule(): void
    {
        $id = $this->rule([['rating', ['mode' => 'in', 'values' => [3]]]]);
        $this->actingAs($this->admin())->get(route('vatssa.discord.rules.edit', $id))->assertOk()->assertSee('Save rule');
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
}
