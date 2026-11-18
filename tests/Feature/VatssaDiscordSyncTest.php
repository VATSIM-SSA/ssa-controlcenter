<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: "Sync Discord roles" button and the bot's polling endpoint.
class VatssaDiscordSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->create();
    }

    private function withRole(?string $role, bool $inArea = false): User
    {
        $user = User::factory()->create();
        if ($role) {
            $user->roleAssignments()->create(['role' => $role, 'area_id' => $inArea ? Area::factory()->create()->id : null]);
        }

        return $user->fresh();
    }

    private function requests()
    {
        return DB::table('vatssa_discord_sync_requests')->get();
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

        $this->actingAs($staff)->post(route('vatssa.user.discord-sync', $this->member->id))
            ->assertRedirect(route('user.show', $this->member->id))->assertSessionHas('success');

        $this->assertCount(1, $this->requests());
        $this->assertEquals([$this->member->id, $staff->id], [$this->requests()[0]->user_id, $this->requests()[0]->requested_by]);
    }

    #[Test]
    public function others_are_refused(): void
    {
        foreach ([null, 'buddy', 'event-staff'] as $role) {
            $this->actingAs($this->withRole($role, true))
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
    public function the_bot_reads_requests_after_its_cursor_with_a_read_only_key(): void
    {
        ApiKey::create(['id' => 'bot-key', 'name' => 'role sync', 'read_only' => true, 'created_at' => now()]);
        $ids = collect([1, 2, 3])->map(fn ($i) => DB::table('vatssa_discord_sync_requests')->insertGetId(
            ['user_id' => 1000 + $i, 'requested_by' => 1, 'created_at' => now()]));
        DB::table('vatssa_discord_sync_requests')->insert(['user_id' => 9, 'requested_by' => 1, 'created_at' => now()->subDays(2)]);

        $url = route('api.vatssa.discord-sync.index');
        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();

        $this->getJson($url . '?after=' . $ids[0], ['Authorization' => 'Bearer bot-key'])->assertOk()
            ->assertJsonPath('data', [['id' => $ids[1], 'user_id' => 1002], ['id' => $ids[2], 'user_id' => 1003]]);
        $this->getJson($url, ['Authorization' => 'Bearer bot-key'])->assertJsonCount(3, 'data');
        $this->getJson($url . '?after=x', ['Authorization' => 'Bearer bot-key'])->assertUnprocessable();
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

        $this->getJson(route('api.vatssa.discord-sync.index') . '?after=' . ($id + 50), ['Authorization' => 'Bearer bot-key'])
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

        config(['vatssa.discord_bot_token' => 'bot-secret']);
        $this->postJson($this->resultUrl($id), $body)->assertUnauthorized();
        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer edit-key'])->assertUnauthorized();
        $this->assertNull(DB::table('vatssa_discord_sync_requests')->find($id)->status);

        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer bot-secret'])->assertNoContent();
        $row = DB::table('vatssa_discord_sync_requests')->find($id);
        $this->assertEquals(['done', 'added atc_twr'], [$row->status, $row->detail]);
        $this->assertNotNull($row->finished_at);

        $this->postJson($this->resultUrl($id), $body, ['Authorization' => 'Bearer bot-secret'])->assertNoContent(); // repeat
    }

    #[Test]
    public function the_bot_token_cannot_read_the_feed(): void
    {
        config(['vatssa.discord_bot_token' => 'bot-secret']);
        $this->getJson(route('api.vatssa.discord-sync.index'), ['Authorization' => 'Bearer bot-secret'])->assertUnauthorized();
    }

    #[Test]
    public function results_are_validated(): void
    {
        config(['vatssa.discord_bot_token' => 'bot-secret']);
        $auth = ['Authorization' => 'Bearer bot-secret'];
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

    private function sendPresence(array $members, bool $complete = true, string $token = 'bot-secret')
    {
        config(['vatssa.discord_bot_token' => 'bot-secret']);

        return $this->postJson(route('api.vatssa.discord-presence'), ['complete' => $complete, 'members' => $members],
            ['Authorization' => 'Bearer ' . $token]);
    }

    private function profile()
    {
        return $this->actingAs($this->withRole('admin'))->get(route('user.show', $this->member->id))->assertOk();
    }

    #[Test]
    public function presence_needs_the_bot_token(): void
    {
        ApiKey::create(['id' => 'edit-key', 'name' => 'x', 'read_only' => false, 'created_at' => now()]);
        $m = [['cid' => $this->member->id, 'discord_id' => '123456789012345678', 'name' => 'alice']];

        $this->sendPresence($m, true, 'wrong')->assertUnauthorized();
        $this->sendPresence($m, true, 'edit-key')->assertUnauthorized();
        $this->assertSame(0, DB::table('vatssa_discord_presence')->count());
    }

    #[Test]
    public function presence_is_validated(): void
    {
        $good = ['cid' => 5, 'discord_id' => '123456789012345678', 'name' => 'a'];
        $this->sendPresence([])->assertUnprocessable(); // complete and empty: a fault
        $this->sendPresence([['cid' => 5, 'discord_id' => '1.23e17']])->assertUnprocessable();
        $this->sendPresence([['cid' => 0, 'discord_id' => '123456789012345678']])->assertUnprocessable();
        $this->sendPresence([[...$good, 'name' => str_repeat('x', 101)]])->assertUnprocessable();
        $this->sendPresence([], false)->assertNoContent(); // incomplete and empty: nothing to add
        $this->sendPresence([$good])->assertNoContent();
    }

    #[Test]
    public function a_complete_list_replaces_and_an_incomplete_one_only_adds(): void
    {
        $a = ['cid' => 1, 'discord_id' => '111111111111111111', 'name' => 'a'];
        $b = ['cid' => 2, 'discord_id' => '222222222222222222', 'name' => 'b'];
        $c = ['cid' => 3, 'discord_id' => '333333333333333333', 'name' => 'c'];

        $this->sendPresence([$a, $b])->assertNoContent();
        $this->sendPresence([$c], false)->assertNoContent();
        $this->assertEqualsCanonicalizing([1, 2, 3], DB::table('vatssa_discord_presence')->pluck('user_id')->all());

        $this->sendPresence([$b])->assertNoContent();
        $this->assertSame([2], DB::table('vatssa_discord_presence')->pluck('user_id')->all());
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
}
