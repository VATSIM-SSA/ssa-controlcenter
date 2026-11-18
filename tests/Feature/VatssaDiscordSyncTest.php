<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController;
use App\Models\ApiKey;
use App\Models\Area;
use App\Models\AtcActivity;
use App\Models\Endorsement;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: "Sync Discord roles" button and the bot's polling endpoint.
class VatssaDiscordSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token-0123456789abcdefghijklmnop';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create();
        config(['vatssa.discord_bot_token' => self::TOKEN]);
    }

    private function withRole(?string $role, bool $inArea = false): User
    {
        $user = User::factory()->create();
        if ($role) {
            $user->roleAssignments()->create(['role' => $role, 'area_id' => $inArea ? Area::factory()->create()->id : null]);
        }

        return $user->fresh();
    }

    private function requests(): Collection
    {
        return DB::table('vatssa_discord_sync_requests')->where('source', 'button')->get();
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
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

        $this->assertCount(1, $this->requests());
        $this->assertEquals([$this->member->id, $staff->id], [$this->requests()[0]->user_id, $this->requests()[0]->requested_by]);
    }

    #[Test]
    public function others_are_refused(): void
    {
        foreach ([[null, false], ['nav-editor', true], ['event-staff', false], ['feedback-team', false]] as [$role, $inArea]) {
            $this->actingAs($this->withRole($role, $inArea))
                ->post(route('vatssa.user.discord-sync', $this->member->id))->assertForbidden();
        }
        $this->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->assertCount(0, $this->requests());
    }

    #[Test]
    public function guests_are_sent_to_login(): void
    {
        auth()->logout();
        $this->post(route('vatssa.user.discord-sync', $this->member->id))->assertRedirect();
        $this->assertCount(0, $this->requests());
    }

    #[Test]
    public function a_double_click_adds_one_row(): void
    {
        $admin = $this->withRole('admin');
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->assertCount(1, $this->requests());

        $this->travel(2)->minutes();
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function the_button_shows_only_to_those_allowed(): void
    {
        $this->actingAs($this->withRole('admin'))->get(route('user.show', $this->member->id))
            ->assertOk()->assertSee('Sync Discord roles');
        $this->actingAs($this->withRole(null))->get(route('user.show', $this->member->id))
            ->assertDontSee('Sync Discord roles');
    }

    #[Test]
    public function the_bot_reads_requests_after_its_cursor_with_its_token_only(): void
    {
        ApiKey::create(['id' => 'bot-key', 'name' => 'role sync', 'read_only' => true, 'created_at' => now()]);
        $start = (int) DB::table('vatssa_discord_sync_requests')->max('id');
        $ids = collect([1, 2, 3])->map(fn ($i) => DB::table('vatssa_discord_sync_requests')->insertGetId(
            ['user_id' => 1000 + $i, 'requested_by' => 1, 'created_at' => now()]));
        DB::table('vatssa_discord_sync_requests')->insert(['user_id' => 9, 'requested_by' => 1, 'created_at' => now()->subDays(2)]);

        $url = route('api.vatssa.discord-sync.index');
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer bot-key'])->assertUnauthorized();

        $this->getJson($url . '?after=' . $ids[0], ['Authorization' => 'Bearer ' . self::TOKEN])->assertOk()
            ->assertJsonPath('data', [['id' => $ids[1], 'user_id' => 1002, 'source' => 'button'], ['id' => $ids[2], 'user_id' => 1003, 'source' => 'button']]);
        $this->getJson($url . '?after=' . $start, ['Authorization' => 'Bearer ' . self::TOKEN])->assertJsonCount(3, 'data');
        $this->getJson($url . '?after=x', ['Authorization' => 'Bearer ' . self::TOKEN])->assertUnprocessable();
    }

    #[Test]
    public function pressing_the_button_prunes_requests_older_than_30_days(): void
    {
        $old = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 1, 'requested_by' => 1, 'created_at' => now()->subDays(31)]);
        $kept = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 1, 'requested_by' => 1, 'created_at' => now()->subDays(29)]);

        $this->actingAs($this->withRole('admin'))->post(route('vatssa.user.discord-sync', $this->member->id));

        $ids = $this->requests()->pluck('id')->all();
        $this->assertNotContains($old, $ids);
        $this->assertContains($kept, $ids);
    }

    #[Test]
    public function the_feed_reports_the_highest_id_even_past_the_cursor(): void
    {
        ApiKey::create(['id' => 'bot-key', 'name' => 'role sync', 'read_only' => true, 'created_at' => now()]);
        $id = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 5, 'requested_by' => 1, 'created_at' => now()]);

        $this->getJson(route('api.vatssa.discord-sync.index') . '?after=' . ($id + 50), ['Authorization' => 'Bearer ' . self::TOKEN])
            ->assertOk()->assertExactJson(['data' => [], 'max_id' => $id]);
    }

    private function resultUrl(int $id): string
    {
        return route('api.vatssa.discord-sync.result', $id);
    }

    #[Test]
    public function results_need_the_bot_token_and_nothing_else(): void
    {
        $id = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 5, 'requested_by' => 1, 'created_at' => now()]);
        ApiKey::create(['id' => 'edit-key', 'name' => 'x', 'read_only' => false, 'created_at' => now()]);
        $body = ['status' => 'done', 'detail' => 'added atc_twr'];

        config(['vatssa.discord_bot_token' => null]); // not configured: closed to everyone
        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer '])->assertUnauthorized();

        config(['vatssa.discord_bot_token' => self::TOKEN]);
        $this->postJson($this->resultUrl($id), $body)->assertUnauthorized();
        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer edit-key'])->assertUnauthorized();
        $this->assertNull(DB::table('vatssa_discord_sync_requests')->find($id)->status);

        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer ' . self::TOKEN])->assertNoContent();
        $row = DB::table('vatssa_discord_sync_requests')->find($id);
        $this->assertEquals(['done', 'added atc_twr'], [$row->status, $row->detail]);
        $this->assertNotNull($row->finished_at);

        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer ' . self::TOKEN])->assertNoContent();
    }

    #[Test]
    public function results_are_validated(): void
    {
        config(['vatssa.discord_bot_token' => self::TOKEN]);
        $auth = ['Authorization' => 'Bearer ' . self::TOKEN];
        $id = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 5, 'requested_by' => 1, 'created_at' => now()]);

        $this->postJson($this->resultUrl($id), ['status' => 'hacked'], $auth)->assertUnprocessable();
        $this->postJson($this->resultUrl($id), ['status' => 'done', 'detail' => str_repeat('x', 501)], $auth)->assertUnprocessable();
        $this->postJson($this->resultUrl($id + 1000), ['status' => 'done'], $auth)->assertNotFound();
        $this->post('/api/vatssa/discord-sync-requests/abc/result', ['status' => 'done'], $auth)->assertNotFound();
    }

    #[Test]
    public function the_profile_shows_how_the_last_request_ended(): void
    {
        $admin = $this->withRole('admin');
        $page = fn () => $this->actingAs($admin)->get(route('user.show', $this->member->id))->assertOk();

        $page()->assertDontSee('Last sync')->assertDontSee('waiting for the bot');

        $id = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => $this->member->id, 'requested_by' => $admin->id, 'created_at' => now()]);
        $page()->assertSee('waiting for the bot');

        DB::table('vatssa_discord_sync_requests')->where('id', $id)
            ->update(['status' => 'failed', 'detail' => '<b>role is above the bot</b>', 'finished_at' => now()]);
        $page()->assertSee('Last sync: Failed', false)->assertSee('&lt;b&gt;role is above the bot&lt;/b&gt;', false)
            ->assertDontSee('<b>role is above the bot</b>', false);
    }

    /**
     * @param  array<int, array{cid: int, discord_id: string, name?: string|null}>  $members
     */
    private function sendPresence(array $members, bool $complete = true, string $token = self::TOKEN, ?float $at = null): TestResponse
    {
        return $this->postJson(route('api.vatssa.discord-presence'), ['complete' => $complete, 'at' => $at ?? microtime(true), 'members' => $members],
            ['Authorization' => 'Bearer ' . $token]);
    }

    private function profile(): TestResponse
    {
        return $this->actingAs($this->withRole('admin'))->get(route('user.show', $this->member->id))->assertOk();
    }

    #[Test]
    public function presence_needs_the_bot_token(): void
    {
        ApiKey::create(['id' => 'edit-key', 'name' => 'x', 'read_only' => false, 'created_at' => now()]);
        $members = [['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => 'alice']];

        $this->sendPresence($members, true, 'wrong')->assertUnauthorized();
        $this->sendPresence($members, true, 'edit-key')->assertUnauthorized();
        $this->assertSame(0, DB::table('vatssa_discord_presence')->count());
    }

    #[Test]
    public function presence_is_validated(): void
    {
        $good = ['cid' => 5, 'discord_id' => '123456789012345678', 'name' => 'a'];
        $this->sendPresence([])->assertUnprocessable();
        $this->sendPresence([['cid' => 5, 'discord_id' => '1.23e17']])->assertUnprocessable();
        $this->sendPresence([['cid' => 0, 'discord_id' => '123456789012345678']])->assertUnprocessable();
        $this->sendPresence([[...$good, 'name' => str_repeat('x', 101)]])->assertUnprocessable();
        $this->sendPresence([], false)->assertNoContent();
        $this->sendPresence([$good])->assertNoContent();
    }

    #[Test]
    public function a_complete_list_replaces_and_an_incomplete_one_only_adds(): void
    {
        [$one, $two, $three] = User::factory()->count(3)->create()->pluck('id')->all();
        $a = ['cid' => $one, 'discord_id' => '111111111111111111', 'name' => 'a'];
        $b = ['cid' => $two, 'discord_id' => '222222222222222222', 'name' => 'b'];
        $c = ['cid' => $three, 'discord_id' => '333333333333333333', 'name' => 'c'];

        $this->sendPresence([$a, $b])->assertNoContent();
        $this->sendPresence([$c], false)->assertNoContent();
        $this->assertEqualsCanonicalizing([$one, $two, $three], DB::table('vatssa_discord_presence')->pluck('user_id')->all());

        $this->sendPresence([$b])->assertNoContent();
        $this->assertSame([$two], DB::table('vatssa_discord_presence')->pluck('user_id')->all());
        $this->assertSame('222222222222222222', DB::table('vatssa_discord_presence')->value('discord_id'));
    }

    #[Test]
    public function the_profile_says_whether_the_member_is_on_discord(): void
    {
        $this->profile()->assertSee('Unknown: the role sync bot has not reported recently.');

        $this->sendPresence([['cid' => 99, 'discord_id' => '999999999999999999', 'name' => 'someone']]);
        $this->profile()->assertSee('Not on the VATSSA Discord')->assertSee('Checked');

        $this->sendPresence([['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => '<i>alice</i>']]);
        $this->profile()->assertSee('On the VATSSA Discord as')
            ->assertSee('@&lt;i&gt;alice&lt;/i&gt;', false)->assertDontSee('<i>alice</i>', false)
            ->assertDontSee('several accounts');

        $this->sendPresence([
            ['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => 'alice'],
            ['cid' => $this->member->id, 'discord_id' => '123456789012345679', 'name' => 'alice2'],
        ]);
        $this->profile()->assertSee('several accounts');
    }

    #[Test]
    public function an_incomplete_list_does_not_count_as_checked(): void
    {
        $this->sendPresence([['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => 'alice']], false);
        $this->profile()->assertSee('Unknown: the role sync bot has not reported recently.');
    }

    #[Test]
    public function a_stale_check_shows_as_unknown(): void
    {
        $this->sendPresence([['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => 'alice']]);
        $this->travel(3)->hours();
        $this->profile()->assertSee('Unknown: the role sync bot has not reported recently.')->assertDontSee('On the VATSSA Discord as');
    }

    #[Test]
    public function only_staff_see_the_discord_line(): void
    {
        $this->actingAs($this->withRole(null))->get(route('user.show', $this->member->id))->assertDontSee('VATSSA Discord');
    }

    private function autoRows(?int $userId = null): int
    {
        return DB::table('vatssa_discord_sync_requests')->where('source', 'auto')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))->count();
    }

    #[Test]
    public function endorsement_changes_queue_the_member(): void
    {
        $e = Endorsement::factory()->create(['user_id' => $this->member->id, 'type' => 'SOLO']);
        $this->assertSame(1, $this->autoRows($this->member->id));

        $e->revoked = true;
        $e->save();
        $this->assertSame(2, $this->autoRows($this->member->id));

        $e->delete();
        $this->assertSame(3, $this->autoRows($this->member->id));
        $this->assertNull(DB::table('vatssa_discord_sync_requests')->where('source', 'auto')->value('requested_by'));
    }

    #[Test]
    public function training_queues_on_create_and_status_change_only(): void
    {
        $t = Training::factory()->create(['user_id' => $this->member->id, 'area_id' => Area::factory()->create()->id, 'status' => 0]);
        $this->assertSame(1, $this->autoRows($this->member->id));

        $t->touch();
        $this->assertSame(1, $this->autoRows($this->member->id));

        $t->status = 2;
        $t->save();
        $this->assertSame(2, $this->autoRows($this->member->id));
    }

    #[Test]
    public function role_grants_and_revokes_queue_the_member(): void
    {
        $a = $this->member->roleAssignments()->create(['role' => 'mentor', 'area_id' => Area::factory()->create()->id]);
        $this->assertSame(1, $this->autoRows($this->member->id));
        $a->delete();
        $this->assertSame(2, $this->autoRows($this->member->id));
    }

    #[Test]
    public function roster_changes_queue_but_hours_do_not(): void
    {
        $area = Area::factory()->create();
        $a = AtcActivity::create(['user_id' => $this->member->id, 'area_id' => $area->id, 'hours' => 0, 'atc_active' => true]);
        $this->assertSame(1, $this->autoRows($this->member->id));

        $a->hours = 12;
        $a->save();
        $this->assertSame(1, $this->autoRows($this->member->id));

        $a->atc_active = false;
        $a->save();
        $this->assertSame(2, $this->autoRows($this->member->id));
    }

    #[Test]
    public function only_rating_or_division_changes_on_a_user_queue(): void
    {
        $this->member->last_activity = now();
        $this->member->first_name = 'Changed';
        $this->member->save();
        $this->assertSame(0, $this->autoRows($this->member->id));

        $this->member->rating = ($this->member->rating?->value ?? $this->member->rating) == 4 ? 5 : 4;
        $this->member->save();
        $this->assertSame(1, $this->autoRows($this->member->id));

        $this->member->division = $this->member->division === 'ZZZ' ? 'YYY' : 'ZZZ';
        $this->member->save();
        $this->assertSame(2, $this->autoRows($this->member->id));
    }

    #[Test]
    public function a_broken_queue_never_breaks_the_save(): void
    {
        Schema::drop('vatssa_discord_sync_requests');

        $e = Endorsement::factory()->create(['user_id' => $this->member->id, 'type' => 'SOLO']);

        $this->assertTrue($e->exists);
        $this->assertDatabaseHas('endorsements', ['id' => $e->id]);
    }

    #[Test]
    public function the_feed_and_profile_say_when_a_sync_was_automatic(): void
    {
        ApiKey::create(['id' => 'bot-key', 'name' => 'role sync', 'read_only' => true, 'created_at' => now()]);
        $start = (int) DB::table('vatssa_discord_sync_requests')->max('id');
        Endorsement::factory()->create(['user_id' => $this->member->id, 'type' => 'SOLO']);
        $this->travel(VatssaDiscordSyncController::AUTO_SETTLE_SECONDS + 1)->seconds();

        $this->getJson(route('api.vatssa.discord-sync.index') . '?after=' . $start, ['Authorization' => 'Bearer ' . self::TOKEN])
            ->assertJsonPath('data.0.source', 'auto')->assertJsonPath('data.0.user_id', $this->member->id);

        $admin = $this->withRole('admin');
        $this->actingAs($admin)->get(route('user.show', $this->member->id))->assertSee('Change detected');

        DB::table('vatssa_discord_sync_requests')->where('user_id', $this->member->id)
            ->update(['status' => 'done', 'detail' => 'added solo', 'finished_at' => now()]);
        $this->actingAs($admin)->get(route('user.show', $this->member->id))->assertSee('Last sync (automatic): Done', false);
    }

    #[Test]
    public function a_short_bot_token_closes_the_routes(): void
    {
        config(['vatssa.discord_bot_token' => 'short-token']);
        $id = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 5, 'requested_by' => 1, 'created_at' => now()]);
        $this->postJson($this->resultUrl($id), ['status' => 'done'], ['Authorization' => 'Bearer short-token'])->assertUnauthorized();
    }

    #[Test]
    public function the_bot_can_report_a_full_batch_without_hitting_a_rate_limit(): void
    {
        config(['vatssa.discord_bot_token' => self::TOKEN]);
        ApiKey::create(['id' => 'bot-key', 'name' => 'role sync', 'read_only' => true, 'created_at' => now()]);
        $ids = collect(range(1, 100))->map(fn ($i) => DB::table('vatssa_discord_sync_requests')
            ->insertGetId(['user_id' => $i, 'requested_by' => 1, 'created_at' => now()]));

        $this->getJson(route('api.vatssa.discord-sync.index'), ['Authorization' => 'Bearer ' . self::TOKEN])->assertOk();
        foreach ($ids as $id) {
            $this->postJson($this->resultUrl($id), ['status' => 'done'], ['Authorization' => 'Bearer ' . self::TOKEN])->assertNoContent();
        }
        // ...and upstream's own /api/users counter is untouched by all of that.
        $this->getJson('/api/users', ['Authorization' => 'Bearer bot-key'])->assertOk();
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
    public function pruning_never_empties_the_table(): void
    {
        $a = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 1, 'requested_by' => 1, 'created_at' => now()->subDays(40)]);
        $b = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 1, 'requested_by' => 1, 'created_at' => now()->subDays(40)]);

        VatssaDiscordSyncController::queueAuto(7);

        $ids = DB::table('vatssa_discord_sync_requests')->pluck('id')->all();
        $this->assertNotContains($a, $ids);
        $this->assertContains($b, $ids); // was the newest when the prune ran
        $this->assertSame(max($ids), (int) DB::table('vatssa_discord_sync_requests')->max('id'));
        $this->assertGreaterThan($b, max($ids));
    }

    #[Test]
    public function a_request_the_bot_never_picked_up_says_so(): void
    {
        DB::table('vatssa_discord_sync_requests')->insert(['user_id' => $this->member->id, 'requested_by' => 1, 'source' => 'auto', 'created_at' => now()->subMinutes(10)]);
        $this->actingAs($this->withRole('admin'))->get(route('user.show', $this->member->id))
            ->assertSee('Not picked up by the bot')->assertDontSee('waiting for the bot');
    }

    private function teach(User $mentor, User $student): void
    {
        $training = Training::factory()->create(['user_id' => $student->id, 'area_id' => Area::factory()->create()->id]);
        $mentor->teaches()->attach($training->id, ['expire_at' => now()->addYear()]);
    }

    #[Test]
    public function a_mentor_can_sync_only_members_they_teach(): void
    {
        $mentor = $this->withRole('mentor', true);
        $stranger = User::factory()->create();

        $this->actingAs($mentor)->post(route('vatssa.user.discord-sync', $stranger->id))->assertForbidden();
        $this->teach($mentor, $this->member);
        $this->actingAs($mentor->fresh())->post(route('vatssa.user.discord-sync', $this->member->id))->assertRedirect();
        $this->assertSame([$this->member->id], $this->requests()->pluck('user_id')->all());
    }

    private function feed(int $after): TestResponse
    {
        return $this->getJson(route('api.vatssa.discord-sync.index') . '?after=' . $after, ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    #[Test]
    public function automatic_requests_settle_before_the_bot_sees_them(): void
    {
        $start = (int) DB::table('vatssa_discord_sync_requests')->max('id');
        VatssaDiscordSyncController::queueAuto($this->member->id);
        $button = DB::table('vatssa_discord_sync_requests')->insertGetId(['user_id' => 7, 'requested_by' => 1, 'source' => 'button', 'created_at' => now()]);

        $this->feed($start)->assertJsonCount(0, 'data');

        $this->travel(VatssaDiscordSyncController::AUTO_SETTLE_SECONDS + 1)->seconds();
        $this->feed($start)->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $button);
    }

    #[Test]
    public function an_automatic_or_finished_request_never_swallows_a_button_press(): void
    {
        $admin = $this->withRole('admin');
        VatssaDiscordSyncController::queueAuto($this->member->id);
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->assertCount(1, $this->requests());

        DB::table('vatssa_discord_sync_requests')->where('source', 'button')->update(['status' => 'done', 'finished_at' => now()]);
        $this->actingAs($admin)->post(route('vatssa.user.discord-sync', $this->member->id));
        $this->assertCount(2, $this->requests());
    }

    #[Test]
    public function moving_a_training_to_another_student_queues_both(): void
    {
        $other = User::factory()->create();
        $training = Training::factory()->create(['user_id' => $this->member->id, 'area_id' => Area::factory()->create()->id]);
        DB::table('vatssa_discord_sync_requests')->delete();

        $training->user_id = $other->id;
        $training->save();

        $this->assertEqualsCanonicalizing([$this->member->id, $other->id],
            DB::table('vatssa_discord_sync_requests')->where('source', 'auto')->pluck('user_id')->all());
    }

    #[Test]
    public function pseudonymised_and_deleted_members_are_forgotten_and_stay_forgotten(): void
    {
        $gone = User::factory()->create();
        foreach ([$this->member, $gone] as $user) {
            DB::table('vatssa_discord_sync_requests')->insert(['user_id' => $user->id, 'requested_by' => 1, 'created_at' => now()]);
        }
        $this->sendPresence([
            ['cid' => $this->member->id, 'discord_id' => '100000000000000001', 'name' => 'a'],
            ['cid' => $gone->id, 'discord_id' => '100000000000000002', 'name' => 'b'],
        ])->assertNoContent();

        $this->member->email = VatssaDiscordSyncController::PSEUDONYMISED_EMAIL;
        $this->member->save();
        $gone->delete();

        foreach ([$this->member->id, $gone->id] as $cid) {
            $this->assertSame(0, DB::table('vatssa_discord_sync_requests')->where('user_id', $cid)->count());
            $this->assertSame(0, DB::table('vatssa_discord_presence')->where('user_id', $cid)->count());
        }

        $this->sendPresence([
            ['cid' => $this->member->id, 'discord_id' => '100000000000000001', 'name' => 'a'],
            ['cid' => $gone->id, 'discord_id' => '100000000000000002', 'name' => 'b'],
        ])->assertNoContent();
        $this->assertSame(0, DB::table('vatssa_discord_presence')->count());
    }

    #[Test]
    public function an_older_complete_list_cannot_replace_a_newer_one(): void
    {
        $new = [['cid' => $this->member->id, 'discord_id' => '100000000000000001', 'name' => 'new']];
        $old = [['cid' => $this->member->id, 'discord_id' => '100000000000000009', 'name' => 'old']];

        $this->sendPresence($new, true, self::TOKEN, 2000.0)->assertNoContent();
        $this->sendPresence($old, true, self::TOKEN, 1000.0)->assertConflict();
        $this->assertSame(['new'], DB::table('vatssa_discord_presence')->pluck('name')->all());
        $this->sendPresence($old, false, self::TOKEN, 1000.0)->assertNoContent();
    }
}
