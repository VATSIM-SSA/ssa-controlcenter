<?php

namespace Tests\Feature;

use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Models\User;
use App\Services\VatssaVatsimFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: CC owns VATSIM rating and division for the Discord role sync.
class VatssaVatsimFactsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token-0123456789abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['app.mode' => 'division', 'app.owner_code' => 'SSA', 'vatsim.core_api_token' => 'core-token', 'vatssa.discord_bot_token' => self::TOKEN]);
    }

    private function org(array $items, ?int $count = null): void
    {
        Http::fake(['api.vatsim.net/v2/orgs/*' => Http::response(['count' => $count ?? count($items), 'items' => $items])]);
    }

    private function item(int $cid, int $rating = 3): array
    {
        return ['id' => $cid, 'rating' => $rating, 'division_id' => 'SSA'];
    }

    private function fact(int $cid): ?object
    {
        return DB::table('vatssa_vatsim_members')->where('cid', $cid)->first();
    }

    private function user(int $cid, string $division = 'SSA', int $rating = 3, ?string $token = null): User
    {
        $user = User::factory()->create(['id' => $cid, 'division' => $division, 'rating' => $rating]);
        DB::table('users')->where('id', $cid)->update(['refresh_token' => $token]);

        return $user;
    }

    #[Test]
    public function the_whole_list_is_stored_across_pages_with_the_core_token(): void
    {
        Http::fake(['api.vatsim.net/v2/orgs/division/SSA*' => Http::sequence()
            ->push(['count' => 3, 'items' => [$this->item(1000001), $this->item(1000002)]])
            ->push(['count' => 3, 'items' => [$this->item(1000003, 5)]])]);

        $this->assertSame(3, app(VatssaVatsimFacts::class)->pull());

        $this->assertSame(5, (int) $this->fact(1000003)->rating);
        $this->assertSame('org', $this->fact(1000003)->source);
        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'core-token') && str_contains($request->url(), 'offset=2'));
    }

    #[Test]
    public function a_bad_list_changes_nothing(): void
    {
        $this->org([$this->item(1000001)]);
        app(VatssaVatsimFacts::class)->pull();
        $bad = [
            fn () => Http::fake(['api.vatsim.net/*' => Http::response([], 500)]),
            fn () => Http::fake(['api.vatsim.net/*' => Http::response(['count' => 0, 'items' => []])]),
            fn () => Http::fake(['api.vatsim.net/*' => Http::response(['count' => 5, 'items' => []])]),
            fn () => Http::fake(['api.vatsim.net/*' => Http::response(['count' => 1, 'items' => [['id' => 1000002, 'rating' => 3, 'division_id' => 'EUD']]])]),
        ];
        foreach ($bad as $fake) {
            Http::swap(new Factory);
            $fake();
            $this->artisan('vatssa:vatsim-pull')->assertFailed();
        }
        $this->assertNull($this->fact(1000002)); // the refused row never landed
        $this->assertSame(0, (int) $this->fact(1000001)->needs_lookup);
    }

    #[Test]
    public function a_list_that_shrinks_too_far_is_refused(): void
    {
        $this->org(array_map(fn ($i) => $this->item(1000000 + $i), range(1, 10)));
        app(VatssaVatsimFacts::class)->pull();

        Http::swap(new Factory);
        $this->org(array_map(fn ($i) => $this->item(1000000 + $i), range(1, 7)));
        $this->artisan('vatssa:vatsim-pull')->assertFailed();
        $this->assertSame(0, DB::table('vatssa_vatsim_members')->where('needs_lookup', true)->count());
    }

    #[Test]
    public function without_a_token_the_pull_fails_loudly(): void
    {
        config(['vatsim.core_api_token' => null]);
        Http::fake();
        $this->artisan('vatssa:vatsim-pull')->assertFailed();
        Http::assertNothingSent();
    }

    #[Test]
    public function someone_claiming_ssa_off_the_list_is_checked_and_keeps_their_fact_until_then(): void
    {
        $this->user(1000002);
        $others = array_map(fn ($i) => $this->item(1000010 + $i), range(1, 5));
        $this->org([...$others, $this->item(1000002)]);
        app(VatssaVatsimFacts::class)->pull();

        $this->travel(1)->days();
        Http::swap(new Factory);
        $this->org($others);
        app(VatssaVatsimFacts::class)->pull();
        $this->assertSame([1, 'SSA'], [(int) $this->fact(1000002)->needs_lookup, $this->fact(1000002)->division]);

        Http::swap(new Factory);
        Http::fake(['api.vatsim.net/v2/members/1000002' => Http::response(['id' => 1000002, 'rating' => 4, 'division_id' => 'EUD'])]);
        $this->assertSame(1, app(VatssaVatsimFacts::class)->runLookups());

        $this->assertSame(['EUD', 4, 0], [$this->fact(1000002)->division, (int) $this->fact(1000002)->rating, (int) $this->fact(1000002)->needs_lookup]);
        $this->assertSame('pending', DB::table('vatssa_discord_queue')->where('user_id', 1000002)->value('state'));
        $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division')); // never touches users
    }

    #[Test]
    public function a_cc_account_saying_ssa_with_no_fact_yet_is_checked(): void
    {
        $this->user(1000005);
        $this->org([$this->item(1000001)]);
        app(VatssaVatsimFacts::class)->pull();

        $this->assertSame(1, (int) $this->fact(1000005)->needs_lookup);
        $this->assertNull($this->fact(1000005)->rating);
    }

    #[Test]
    public function a_visitor_with_a_live_login_needs_no_vatsim_call(): void
    {
        $this->user(1000006, 'EUD', 4, 'live-token');
        $this->user(1000007, 'EUD', 4, null);
        $this->org([$this->item(1000001)]);
        app(VatssaVatsimFacts::class)->pull();

        $this->assertSame(['cc', 0, 'EUD'], [$this->fact(1000006)->source, (int) $this->fact(1000006)->needs_lookup, $this->fact(1000006)->division]);
        $this->assertNull($this->fact(1000007));
    }

    #[Test]
    public function no_answer_never_changes_a_fact(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'needs_lookup' => true]);
        foreach ([Http::response([], 500), Http::response(['id' => 999, 'rating' => 1, 'division_id' => 'X']), fn () => throw new ConnectionException('down')] as $answer) {
            Http::swap(new Factory);
            Http::fake(['api.vatsim.net/*' => $answer]);
            app(VatssaVatsimFacts::class)->runLookups();
            $this->assertSame([3, 'SSA', 1], [(int) $this->fact(1000001)->rating, $this->fact(1000001)->division, (int) $this->fact(1000001)->needs_lookup]);
        }
    }

    #[Test]
    public function a_rate_limit_stops_the_run(): void
    {
        DB::table('vatssa_vatsim_members')->insert([['cid' => 1000001, 'needs_lookup' => true], ['cid' => 1000002, 'needs_lookup' => true]]);
        Http::fake(['api.vatsim.net/*' => Http::response([], 429)]);

        $this->assertSame(0, app(VatssaVatsimFacts::class)->runLookups());
        Http::assertSentCount(1);
    }

    #[Test]
    public function the_bot_gets_facts_and_unknown_cids_go_in_line(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'source' => 'org', 'needs_lookup' => false]);

        $this->postJson(route('api.vatssa.vatsim-facts'), ['cids' => [1000001, 2000000]])->assertUnauthorized();
        $this->postJson(route('api.vatssa.vatsim-facts'), ['cids' => [1000001, 2000000]], ['Authorization' => 'Bearer ' . self::TOKEN])
            ->assertOk()->assertJsonPath('data.1000001.rating', 3)->assertJsonMissingPath('data.2000000');

        $this->assertSame(1, (int) $this->fact(2000000)->needs_lookup);
    }

    #[Test]
    public function a_day_old_individual_answer_is_checked_again_and_still_served(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 2000000, 'rating' => 4, 'division' => 'EUD', 'source' => 'lookup', 'needs_lookup' => false, 'fetched_at' => now()->subDays(2)]);

        $this->assertSame(4, app(VatssaVatsimFacts::class)->forBot([2000000])['data'][2000000]['rating']);
        $this->assertSame(1, (int) $this->fact(2000000)->needs_lookup);
    }

    #[Test]
    public function erased_members_are_gone_and_stay_gone(): void
    {
        $user = $this->user(1000001);
        $this->org([$this->item(1000001), $this->item(1000002)]);
        app(VatssaVatsimFacts::class)->pull();

        $user->email = Sync::PSEUDONYMISED_EMAIL;
        $user->save();
        $this->assertNull($this->fact(1000001));

        Http::swap(new Factory);
        $this->org([$this->item(1000001), $this->item(1000002)]);
        app(VatssaVatsimFacts::class)->pull();
        $this->assertSame([], app(VatssaVatsimFacts::class)->forBot([1000001])['data']);
        $this->assertNull($this->fact(1000001));
    }

    #[Test]
    public function a_rating_change_on_the_user_updates_the_fact(): void
    {
        $user = $this->user(1000001, 'SSA', 3);
        $user->rating = 4;
        $user->save();

        $this->assertSame([4, 'cc'], [(int) $this->fact(1000001)->rating, $this->fact(1000001)->source]);
    }

    #[Test]
    public function the_button_asks_for_a_fresh_check(): void
    {
        $admin = User::factory()->create();
        $admin->roleAssignments()->create(['role' => 'admin', 'area_id' => null]);
        $member = $this->user(1000001);
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'source' => 'org', 'needs_lookup' => false]);

        $this->actingAs($admin->fresh())->post(route('vatssa.user.discord-sync', $member->id))->assertRedirect();
        $this->assertSame(1, (int) $this->fact(1000001)->needs_lookup);
    }
}
