<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Livewire\VatssaDiscordMembers;
use App\Models\ApiKey;
use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Training;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: Discord sync v2, phase 1: the queue, the members table and the profile.
class VatssaDiscordSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token-0123456789abcdefghijklmnop';

    private const A = '100000000000000001';

    private const B = '100000000000000002';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create();
        DB::table('vatssa_discord_queue')->delete();
        config(['vatssa.discord_bot_token' => self::TOKEN]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN];
    }

    private function withRole(?string $role, bool $inArea = false): User
    {
        $user = User::factory()->create();
        if ($role) {
            $user->roleAssignments()->create(['role' => $role, 'area_id' => $inArea ? Area::factory()->create()->id : null]);
        }
        DB::table('vatssa_discord_queue')->delete();

        return $user->fresh();
    }

    private function row(?int $cid = null): ?object
    {
        return DB::table('vatssa_discord_queue')->where('user_id', $cid ?? $this->member->id)->first();
    }

    private function generation(): int
    {
        return (int) ($this->row()->generation ?? 0);
    }

    private function claim(int $limit = 20): TestResponse
    {
        return $this->postJson(route('api.vatssa.discord-queue.claim'), ['limit' => $limit], $this->auth());
    }

    private function report(int $cid, int $generation, string $status = 'done', ?string $detail = 'added atc_twr'): TestResponse
    {
        return $this->postJson(route('api.vatssa.discord-queue.result', $cid), compact('generation', 'status', 'detail'), $this->auth());
    }

    private function send(array $members, bool $complete = true, ?float $at = null, array $left = []): TestResponse
    {
        return $this->postJson(route('api.vatssa.discord-members'), ['complete' => $complete, 'at' => $at ?? microtime(true), 'members' => $members, 'left' => $left], $this->auth());
    }

    private function account(string $id, ?int $cid, string $name = 'alice', bool $bot = false): array
    {
        return ['discord_id' => $id, 'cid' => $cid, 'name' => $name, 'bot' => $bot, 'joined_at' => 1700000000];
    }

    private function events(string $id): array
    {
        return DB::table('vatssa_discord_member_history')->where('discord_id', $id)->orderBy('id')->pluck('event')->all();
    }

    private function profile(): TestResponse
    {
        return $this->actingAs($this->withRole('admin'))->get(route('user.show', $this->member->id))->assertOk();
    }

    private function teach(User $mentor, User $student): void
    {
        $training = Training::factory()->create(['user_id' => $student->id, 'area_id' => Area::factory()->create()->id]);
        $mentor->teaches()->attach($training->id, ['expire_at' => now()->addYear()]);
    }

    public static function allowed(): array
    {
        return [['admin', false], ['atc-training-manager', true], ['pipeline-coordinator', true], ['mentor', true]];
    }

    #[Test]
    #[DataProvider('allowed')]
    public function staff_can_request_a_sync(string $role, bool $inArea): void
    {
        $staff = $this->withRole($role, $inArea);
        if ($role === 'mentor') {
            $this->teach($staff, $this->member);
        }

        $this->actingAs($staff)->post(route('vatssa.user.discord-sync', $this->member->id))
            ->assertRedirect(route('user.show', $this->member->id))->assertSessionHas('success');

        $this->assertEquals(['button', $staff->id, 1], [$this->row()->reason, $this->row()->requested_by, $this->row()->fresh]);
    }

    #[Test]
    public function others_and_guests_are_refused(): void
    {
        foreach ([[null, false], ['nav-editor', true], ['event-staff', false], ['feedback-team', false]] as [$role, $inArea]) {
            $this->actingAs($this->withRole($role, $inArea))->post(route('vatssa.user.discord-sync', $this->member->id))->assertForbidden();
        }
        auth()->logout();
        $this->post(route('vatssa.user.discord-sync', $this->member->id))->assertRedirect();
        $this->assertNull($this->row());
    }

    #[Test]
    public function a_mentor_can_sync_only_members_they_teach(): void
    {
        $mentor = $this->withRole('mentor', true);
        $this->actingAs($mentor)->post(route('vatssa.user.discord-sync', User::factory()->create()->id))->assertForbidden();
        $this->teach($mentor, $this->member);
        $this->actingAs($mentor->fresh())->post(route('vatssa.user.discord-sync', $this->member->id))->assertRedirect();
        $this->assertNotNull($this->row());
    }

    #[Test]
    public function the_button_is_rate_limited(): void
    {
        $admin = $this->withRole('admin');
        $members = User::factory()->count(31)->create();
        foreach ($members->take(30) as $m) {
            $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $m->id))->assertRedirect();
        }
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $members->last()->id))->assertTooManyRequests();
    }

    #[Test]
    public function the_api_needs_the_bot_token(): void
    {
        ApiKey::create(['id' => 'edit-key', 'name' => 'x', 'read_only' => false, 'created_at' => now()]);
        Sync::queue([$this->member->id], 'button');
        $urls = [route('api.vatssa.discord-queue.claim'), route('api.vatssa.discord-queue.result', $this->member->id), route('api.vatssa.discord-members')];

        foreach ($urls as $url) {
            $this->postJson($url, [])->assertUnauthorized();
            $this->postJson($url, [], ['Authorization' => 'Bearer edit-key'])->assertUnauthorized();
        }
        config(['vatssa.discord_bot_token' => 'short-token']);
        $this->postJson($urls[0], [], ['Authorization' => 'Bearer short-token'])->assertUnauthorized();
        $this->assertSame('pending', $this->row()->state);
    }

    #[Test]
    public function a_claimed_row_is_leased_and_comes_back_if_the_bot_dies(): void
    {
        Sync::queue([$this->member->id], 'button', null, 0, true);

        $this->claim()->assertJsonPath('data.0.cid', $this->member->id)->assertJsonPath('data.0.fresh', true);
        $this->claim()->assertJsonCount(0, 'data');

        $this->travel(Sync::LEASE_SECONDS + 1)->seconds();
        $this->claim()->assertJsonCount(1, 'data');
        $this->assertSame(2, (int) $this->row()->attempts);
    }

    #[Test]
    public function a_row_the_bot_keeps_dropping_ends_as_failed(): void
    {
        Sync::queue([$this->member->id], 'button');
        for ($i = 0; $i < Sync::MAX_ATTEMPTS; $i++) {
            $this->claim()->assertJsonCount(1, 'data');
            $this->travel(Sync::LEASE_SECONDS + 1)->seconds();
        }
        $this->claim()->assertJsonCount(0, 'data');
        $this->assertEquals(['idle', 'failed'], [$this->row()->state, $this->row()->result]);
    }

    #[Test]
    public function a_change_during_a_sync_sends_the_row_round_again(): void
    {
        Sync::queue([$this->member->id], 'button');
        $generation = $this->claim()->json('data.0.generation');

        Sync::queue([$this->member->id], 'profile');
        $this->assertSame('leased', $this->row()->state);

        $this->report($this->member->id, $generation)->assertNoContent();
        $this->assertSame('pending', $this->row()->state);
        $next = $this->claim()->assertJsonCount(1, 'data')->json('data.0.generation');
        $this->assertGreaterThan($generation, $next);

        $this->report($this->member->id, $next)->assertNoContent();
        $this->assertEquals(['idle', 'done'], [$this->row()->state, $this->row()->result]);
    }

    #[Test]
    public function a_later_automatic_change_does_not_lose_a_fresh_request(): void
    {
        Sync::queue([$this->member->id], 'button', null, 0, true);
        Sync::queue([$this->member->id], 'profile');
        $this->assertSame(1, (int) $this->row()->fresh);

        $generation = $this->claim()->json('data.0.generation');
        $this->report($this->member->id, $generation);
        Sync::queue([$this->member->id], 'profile');
        $this->assertSame(0, (int) $this->row()->fresh);
    }

    #[Test]
    public function automatic_changes_settle_before_the_bot_sees_them(): void
    {
        Sync::queueAuto($this->member->id);
        $this->claim()->assertJsonCount(0, 'data');
        $this->travel(Sync::AUTO_SETTLE_SECONDS + 1)->seconds();
        $this->claim()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_daily_round_goes_behind_a_button_and_skips_rows_already_waiting(): void
    {
        $other = User::factory()->create();
        DB::table('vatssa_discord_queue')->delete();
        Sync::queue([$other->id], 'button', null, 0, true);
        $this->travel(1)->seconds();
        $this->artisan('vatssa:discord-daily')->assertSuccessful();

        $this->assertSame('button', $this->row($other->id)->reason);
        $this->assertSame('daily', $this->row()->reason);
        $this->assertSame($other->id, $this->claim(1)->json('data.0.cid'));
    }

    #[Test]
    public function results_are_validated_and_a_repeat_is_harmless(): void
    {
        Sync::queue([$this->member->id], 'button');
        $generation = $this->claim()->json('data.0.generation');

        $this->report($this->member->id, $generation, 'hacked')->assertUnprocessable();
        $this->report($this->member->id, $generation, 'done', str_repeat('x', 501))->assertUnprocessable();
        $this->report(99999, 1)->assertNotFound();

        $this->report($this->member->id, $generation)->assertNoContent();
        $this->report($this->member->id, $generation, 'failed')->assertNoContent();
        $this->assertSame('done', $this->row()->result);
    }

    #[Test]
    public function a_finished_sync_stamps_the_account_and_its_history(): void
    {
        $this->send([$this->account(self::A, $this->member->id)]);
        Sync::queue([$this->member->id], 'button');
        $this->report($this->member->id, $this->claim()->json('data.0.generation'))->assertNoContent();

        $this->assertNotNull(DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('last_synced_at'));
        $this->assertSame(['joined', 'linked', 'roles_changed'], $this->events(self::A));
    }

    #[Test]
    public function members_are_validated(): void
    {
        $good = $this->account(self::A, 5);
        $this->send([])->assertUnprocessable();
        $this->send([[...$good, 'discord_id' => '1.23e17']])->assertUnprocessable();
        $this->send([[...$good, 'cid' => -1]])->assertUnprocessable();
        $this->send([[...$good, 'name' => str_repeat('x', 101)]])->assertUnprocessable();
        $this->send([], false)->assertNoContent();
        $this->send([$good])->assertNoContent();
    }

    #[Test]
    public function the_table_holds_cids_not_vatsim_and_bots(): void
    {
        $this->send([
            $this->account(self::A, $this->member->id),
            $this->account(self::B, 0, 'plain'),
            $this->account('100000000000000003', 2000000, 'no-cc-account'),
            $this->account('100000000000000004', null, 'no-answer'),
            $this->account('100000000000000005', 0, 'botty', true),
        ])->assertNoContent();

        $row = fn (string $id) => DB::table('vatssa_discord_members')->where('discord_id', $id)->first();
        $this->assertSame($this->member->id, (int) $row(self::A)->user_id);
        $this->assertEquals([null, 0], [$row(self::B)->user_id, $row(self::B)->vatsim_linked]);
        $this->assertEquals([null, 1], [$row('100000000000000003')->user_id, $row('100000000000000003')->vatsim_linked]);
        $this->assertNull($row('100000000000000004')->vatsim_linked);
        $this->assertSame(1, (int) $row('100000000000000005')->is_bot);
    }

    #[Test]
    public function no_answer_from_vatsim_keeps_the_link(): void
    {
        $this->send([$this->account(self::A, $this->member->id)]);
        $this->send([$this->account(self::A, null)]);
        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->value('user_id'));
        $this->assertSame(['joined', 'linked'], $this->events(self::A));
    }

    #[Test]
    public function a_cid_moving_to_another_account_leaves_the_old_one(): void
    {
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, 0, 'bob')]);
        $this->send([$this->account(self::B, $this->member->id, 'bob')], false)->assertNoContent();

        $this->assertNull(DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('user_id'));
        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->where('discord_id', self::B)->value('user_id'));
        $this->assertContains('unlinked', $this->events(self::A));
    }

    #[Test]
    public function two_accounts_can_swap_cids_in_one_list(): void
    {
        $other = User::factory()->create();
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, $other->id, 'bob')]);
        $this->send([$this->account(self::A, $other->id), $this->account(self::B, $this->member->id, 'bob')])->assertNoContent();

        $this->assertSame($other->id, (int) DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('user_id'));
        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->where('discord_id', self::B)->value('user_id'));
    }

    #[Test]
    public function one_cid_sent_for_two_accounts_changes_neither(): void
    {
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, 0, 'bob')]);
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, $this->member->id, 'bob')])->assertNoContent();

        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('user_id'));
        $this->assertNull(DB::table('vatssa_discord_members')->where('discord_id', self::B)->value('user_id'));
    }

    #[Test]
    public function leaving_and_rejoining_is_recorded(): void
    {
        $a = $this->account(self::A, $this->member->id);
        $this->send([$a, $this->account(self::B, 0, 'bob')]);

        $this->send([], false, null, [self::A])->assertNoContent();
        $this->assertSame(0, (int) DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('in_server'));
        $this->send([$this->account(self::B, 0, 'bob')])->assertNoContent(); // a complete list does not record it twice
        $this->send([$a], false)->assertNoContent();

        $this->assertSame(1, (int) DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('in_server'));
        $this->assertSame(['joined', 'linked', 'left', 'joined'], $this->events(self::A));
    }

    #[Test]
    public function a_complete_list_marks_the_missing_as_left_and_a_partial_one_does_not(): void
    {
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, 0, 'bob')]);
        $this->send([$this->account(self::B, 0, 'bob')], false);
        $this->assertSame(2, DB::table('vatssa_discord_members')->where('in_server', true)->count());

        $this->send([$this->account(self::B, 0, 'bob')]);
        $this->assertSame([self::B], DB::table('vatssa_discord_members')->where('in_server', true)->pluck('discord_id')->all());
    }

    #[Test]
    public function an_older_complete_list_is_refused(): void
    {
        $this->send([$this->account(self::A, $this->member->id, 'new')], true, 2000.0)->assertNoContent();
        $this->send([$this->account(self::B, 0, 'old')], true, 1000.0)->assertConflict();
        $this->assertSame(['new'], DB::table('vatssa_discord_members')->pluck('name')->all());
    }

    #[Test]
    public function erased_members_are_forgotten_and_stay_forgotten(): void
    {
        $gone = User::factory()->create();
        $list = [$this->account(self::A, $this->member->id), $this->account(self::B, $gone->id, 'bob')];
        $this->send($list);
        Sync::queue([$this->member->id, $gone->id], 'button');

        $this->member->email = Sync::PSEUDONYMISED_EMAIL;
        $this->member->save();
        $gone->delete();

        foreach ([$this->member->id, $gone->id] as $cid) {
            $this->assertNull($this->row($cid));
            $this->assertSame(0, DB::table('vatssa_discord_members')->where('user_id', $cid)->count());
            $this->assertSame(0, DB::table('vatssa_discord_member_history')->where('user_id', $cid)->count());
        }
        $this->assertSame([], $this->events(self::A));

        $this->send($list)->assertNoContent();
        $this->assertSame(0, DB::table('vatssa_discord_members')->whereNotNull('user_id')->count());
    }

    #[Test]
    public function a_pseudonymised_member_who_returns_gets_their_link_back(): void
    {
        $list = [$this->account(self::A, $this->member->id)];
        $email = $this->member->email;
        $this->member->email = Sync::PSEUDONYMISED_EMAIL;
        $this->member->save();
        $this->send($list);
        $this->assertNull(DB::table('vatssa_discord_members')->value('user_id'));

        $this->member->email = $email;
        $this->member->save();
        $this->send($list);
        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->value('user_id'));
    }

    #[Test]
    public function the_profile_shows_the_account_its_state_and_history(): void
    {
        $this->profile()->assertSee('Unknown: the role sync bot has not reported recently.');

        $this->send([$this->account(self::B, 0, 'someone')]);
        $this->profile()->assertSee('Not on the VATSSA Discord');

        $this->send([$this->account(self::A, $this->member->id, '<i>alice</i>')]);
        $this->profile()->assertSee('On the VATSSA Discord as')->assertSee('&lt;i&gt;alice&lt;/i&gt;', false)
            ->assertDontSee('<i>alice</i>', false)->assertSee('Discord history')->assertSee('Joined the server');

        $this->send([$this->account(self::B, 0, 'someone')]);
        $this->profile()->assertSee('Left the VATSSA Discord');
    }

    #[Test]
    public function the_profile_shows_how_the_last_sync_ended(): void
    {
        $admin = $this->withRole('admin');
        Sync::queue([$this->member->id], 'button');
        $this->actingAs($admin)->get(route('user.show', $this->member->id))->assertSee('waiting for the bot');

        $generation = $this->claim()->json('data.0.generation');
        $this->report($this->member->id, $generation, 'failed', '<b>role is above the bot</b>');
        $this->actingAs($admin)->get(route('user.show', $this->member->id))->assertSee('Last sync: Failed', false)
            ->assertSee('&lt;b&gt;role is above the bot&lt;/b&gt;', false)->assertDontSee('<b>role is above the bot</b>', false);
    }

    #[Test]
    public function a_request_the_bot_never_picked_up_says_so(): void
    {
        $admin = $this->withRole('admin');
        Sync::queue([$this->member->id], 'button');
        $this->travel(10)->minutes();
        $this->actingAs($admin)->get(route('user.show', $this->member->id))
            ->assertSee('Not picked up by the bot')->assertDontSee('waiting for the bot');
    }

    #[Test]
    public function only_staff_see_the_panel_and_only_staff_views_queue(): void
    {
        $this->actingAs($this->withRole(null))->get(route('user.show', $this->member->id))->assertDontSee('VATSSA Discord')->assertDontSee('Sync Discord roles');
        $this->assertNull($this->row());

        $this->profile()->assertSee('Sync Discord roles');
        $this->assertSame('profile', $this->row()->reason);
    }

    #[Test]
    public function opening_a_profile_queues_at_most_once_a_minute(): void
    {
        $admin = $this->withRole('admin');
        $open = fn () => $this->actingAs($admin)->get(route('user.show', $this->member->id));
        $open();
        $first = $this->generation();
        $this->report($this->member->id, $this->claim()->json('data.0.generation'));
        $open();
        $this->assertSame($first, $this->generation());

        $this->travel(2)->minutes();
        DB::table('vatssa_discord_queue')->update(['state' => 'idle']);
        $open();
        $this->assertSame($first + 1, $this->generation());
    }

    #[Test]
    public function logging_in_queues_a_fresh_sync(): void
    {
        event(new Login('web', $this->member, false));
        $this->assertEquals(['login', 1], [$this->row()->reason, $this->row()->fresh]);
    }

    #[Test]
    public function data_changes_queue_the_member(): void
    {
        $e = Endorsement::factory()->create(['user_id' => $this->member->id, 'type' => 'SOLO']);
        $this->assertSame(1, $this->generation());
        $e->revoked = true;
        $e->save();
        $e->delete();
        $this->assertSame(3, $this->generation());

        $a = $this->member->roleAssignments()->create(['role' => 'mentor', 'area_id' => Area::factory()->create()->id]);
        $a->delete();
        $this->assertSame(5, $this->generation());
    }

    #[Test]
    public function training_queues_on_create_and_status_change_only(): void
    {
        $t = Training::factory()->create(['user_id' => $this->member->id, 'area_id' => Area::factory()->create()->id, 'status' => 0]);
        $this->assertSame(1, $this->generation());
        $t->touch();
        $this->assertSame(1, $this->generation());
        $t->status = 2;
        $t->save();
        $this->assertSame(2, $this->generation());
    }

    #[Test]
    public function roster_changes_queue_but_hours_do_not(): void
    {
        $a = AtcActivity::create(['user_id' => $this->member->id, 'area_id' => Area::factory()->create()->id, 'hours' => 0, 'atc_active' => true]);
        $this->assertSame(1, $this->generation());
        $a->hours = 12;
        $a->save();
        $this->assertSame(1, $this->generation());
        $a->atc_active = false;
        $a->save();
        $this->assertSame(2, $this->generation());
    }

    #[Test]
    public function only_rating_or_division_changes_on_a_user_queue(): void
    {
        $this->member->first_name = 'Changed';
        $this->member->save();
        $this->assertNull($this->row());

        $this->member->division = $this->member->division === 'ZZZ' ? 'YYY' : 'ZZZ';
        $this->member->save();
        $this->assertSame(1, $this->generation());
    }

    #[Test]
    public function moving_a_training_to_another_student_queues_both(): void
    {
        $other = User::factory()->create();
        $training = Training::factory()->create(['user_id' => $this->member->id, 'area_id' => Area::factory()->create()->id]);
        DB::table('vatssa_discord_queue')->delete();

        $training->user_id = $other->id;
        $training->save();

        $this->assertEqualsCanonicalizing([$this->member->id, $other->id], DB::table('vatssa_discord_queue')->pluck('user_id')->all());
    }

    #[Test]
    public function a_broken_queue_never_breaks_the_save(): void
    {
        Schema::drop('vatssa_discord_queue');
        $e = Endorsement::factory()->create(['user_id' => $this->member->id, 'type' => 'SOLO']);
        $this->assertDatabaseHas('endorsements', ['id' => $e->id]);
    }

    #[Test]
    public function the_members_page_is_admin_only_and_filters(): void
    {
        $this->send([$this->account(self::A, $this->member->id, 'alice'), $this->account(self::B, 0, 'plainbob')]);

        $this->actingAs($this->withRole('mentor', true))->get(route('vatssa.discord.members'))->assertForbidden();
        $admin = $this->withRole('admin');
        $this->actingAs($admin)->get(route('vatssa.discord.members'))->assertOk()->assertSee('alice')->assertSee('plainbob');
        $page = Livewire::actingAs($admin)->test(VatssaDiscordMembers::class);
        $page->call('setTab', 'not_vatsim')->assertSee('plainbob')->assertDontSee('alice');
        $page->call('setTab', 'server')->set('search', (string) $this->member->id)->assertSee('alice')->assertDontSee('plainbob');
    }

    #[Test]
    public function a_confirmed_claim_takes_the_cid_from_an_account_with_no_answer(): void
    {
        $this->send([$this->account(self::A, $this->member->id), $this->account(self::B, 0, 'bob')]);

        $this->send([$this->account(self::A, null), $this->account(self::B, $this->member->id, 'bob')])->assertNoContent();

        $this->assertNull(DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('user_id'));
        $this->assertSame($this->member->id, (int) DB::table('vatssa_discord_members')->where('discord_id', self::B)->value('user_id'));
    }

    #[Test]
    public function a_list_begun_before_a_leave_does_not_undo_it(): void
    {
        $list = [$this->account(self::A, $this->member->id), $this->account(self::B, 0, 'bob')];
        $this->send($list, true, microtime(true) - 60);
        $this->send([], false, null, [self::A]);

        $this->send($list, true, microtime(true) - 30)->assertNoContent();

        $this->assertSame(0, (int) DB::table('vatssa_discord_members')->where('discord_id', self::A)->value('in_server'));
    }

    #[Test]
    public function a_list_begun_before_a_join_does_not_mark_them_left(): void
    {
        $this->send([$this->account(self::A, $this->member->id)], true, microtime(true) - 60);
        $this->send([$this->account(self::B, 0, 'new')], false);

        $this->send([$this->account(self::A, $this->member->id)], true, microtime(true) - 30)->assertNoContent();

        $this->assertSame(1, (int) DB::table('vatssa_discord_members')->where('discord_id', self::B)->value('in_server'));
    }

    #[Test]
    public function a_button_keeps_its_reason_until_it_is_handled(): void
    {
        Sync::queue([$this->member->id], 'button', null, 0, true);
        Sync::queueAuto($this->member->id);
        Sync::queue([$this->member->id], 'rule');
        $this->assertSame('button', $this->row()->reason);

        $this->travel(Sync::AUTO_SETTLE_SECONDS + 1)->seconds();
        $this->report($this->member->id, $this->claim()->json('data.0.generation'));
        Sync::queue([$this->member->id], 'rule');
        $this->assertSame('rule', $this->row()->reason);
    }

    #[Test]
    public function a_bulk_round_never_demotes_a_waiting_change(): void
    {
        Sync::queueAuto($this->member->id);
        Sync::queue([$this->member->id], 'daily');
        $this->assertSame('change', $this->row()->reason);
    }

    #[Test]
    public function a_rolled_back_change_queues_nothing(): void
    {
        try {
            DB::transaction(function () {
                Sync::queueAuto($this->member->id);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull($this->row());

        DB::transaction(fn () => Sync::queueAuto($this->member->id));
        $this->assertNotNull($this->row());
    }

    #[Test]
    public function an_erased_member_cannot_reenter_the_queue(): void
    {
        $this->member->email = Sync::PSEUDONYMISED_EMAIL;
        $this->member->save();

        Sync::queue([$this->member->id], 'button');
        $this->send([$this->account(self::A, $this->member->id)], false)->assertNoContent();

        $this->assertNull($this->row());
    }

    #[Test]
    public function erasure_removes_the_cid_from_another_accounts_history(): void
    {
        $other = User::factory()->create();
        $this->send([$this->account(self::A, $this->member->id)]);
        $this->send([$this->account(self::A, $other->id)]);
        $this->assertSame(1, DB::table('vatssa_discord_member_history')->where('detail', 'like', "CID {$this->member->id} to %")->count());

        $this->member->email = Sync::PSEUDONYMISED_EMAIL;
        $this->member->save();

        $this->assertSame(0, DB::table('vatssa_discord_member_history')->where('detail', 'like', "%{$this->member->id}%")->count());
    }

    #[Test]
    public function bot_routes_have_their_own_higher_limit(): void
    {
        $middleware = Route::getRoutes()->getByName('api.vatssa.discord-queue.claim')->gatherMiddleware();
        $this->assertContains('throttle:3000,1,vatssa-discord-bot', $middleware);

        $groups = Route::getRoutes()->getByName('api.vatssa.groups.index')->gatherMiddleware();
        $this->assertContains('throttle:600,1,vatssa-api', $groups);
    }
}
