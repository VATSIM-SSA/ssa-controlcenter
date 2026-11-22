<?php

namespace Tests\Feature;

use App\Helpers\TrainingStatus;
use App\Http\Controllers\VatssaDiscordSyncController as Sync;
use App\Models\Area;
use App\Models\Training;
use App\Models\User;
use App\Services\VatssaVatsimFacts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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
        config(['app.mode' => 'division', 'app.owner_code' => 'SSA', 'vatssa.vatsim_write_users' => true, 'vatsim.core_api_token' => 'core-token', 'vatssa.discord_bot_token' => self::TOKEN]);
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
        $this->assertSame(['EUD', 'S3'], [DB::table('users')->where('id', 1000002)->value('division'), DB::table('users')->where('id', 1000002)->value('rating_short')]);
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
    public function a_rate_limit_holds_every_lookup_until_vatsim_says_so(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'needs_lookup' => true]);
        Http::fake(['api.vatsim.net/*' => Http::response([], 429, ['Retry-After' => '155'])]);

        app(VatssaVatsimFacts::class)->runLookups();
        $this->travel(150)->seconds();
        app(VatssaVatsimFacts::class)->runLookups();
        Http::assertSentCount(1);

        $this->travel(10)->seconds();
        app(VatssaVatsimFacts::class)->runLookups();
        Http::assertSentCount(2);
    }

    #[Test]
    public function the_scheduler_checks_five_members_a_run(): void
    {
        DB::table('vatssa_vatsim_members')->insert(array_map(fn ($cid) => ['cid' => $cid, 'needs_lookup' => true], range(1000001, 1000006)));
        Http::fake(['api.vatsim.net/*' => Http::response(['detail' => 'Not Found'], 404)]);

        $this->assertSame(5, app(VatssaVatsimFacts::class)->runLookups());
        Http::assertSentCount(5);
    }

    #[Test]
    public function a_404_that_is_not_from_vatsim_changes_nothing(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'needs_lookup' => true]);
        Http::fake(['api.vatsim.net/*' => Http::response('<html>nginx</html>', 404)]);

        app(VatssaVatsimFacts::class)->runLookups();

        $this->assertSame([3, 'SSA', 1], [(int) $this->fact(1000001)->rating, $this->fact(1000001)->division, (int) $this->fact(1000001)->needs_lookup]);
    }

    #[Test]
    public function lookups_stand_aside_for_the_atc_hours_job(): void
    {
        // The schedule reads the clock when it is built, as each minute's schedule:run does.
        $lookupsAt = function (int $hour, int $minute) {
            $this->travelTo(now()->setTime($hour, $minute));
            app()->forgetInstance(Schedule::class);

            return collect(app(Schedule::class)->events())
                ->first(fn ($e) => str_contains((string) $e->command, 'vatsim-pull') && str_contains((string) $e->command, '--lookups'));
        };

        $this->assertFalse($lookupsAt(1, 10)->filtersPass(app()));
        $this->assertTrue($lookupsAt(2, 0)->filtersPass(app()));
    }

    #[Test]
    public function the_bot_gets_facts_and_unknown_cids_go_in_line(): void
    {
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'source' => 'org', 'needs_lookup' => false]);

        $answer = app(VatssaVatsimFacts::class)->forBot([1000001, 2000000]);

        $this->assertSame(3, $answer['data'][1000001]['rating']);
        $this->assertArrayNotHasKey(2000000, $answer['data']);
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

    #[Test]
    public function being_off_the_list_alone_never_changes_the_user(): void
    {
        $this->user(1000002);
        $this->org([$this->item(1000001)]);
        app(VatssaVatsimFacts::class)->pull();
        $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division'));

        foreach ([Http::response(['detail' => 'Not Found.'], 404), Http::response([], 500)] as $answer) {
            DB::table('vatssa_vatsim_members')->where('cid', 1000002)->update(['needs_lookup' => true]);
            Http::swap(new Factory);
            Http::fake(['api.vatsim.net/*' => $answer]);
            app(VatssaVatsimFacts::class)->runLookups();
            $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division'));
        }
    }

    #[Test]
    public function the_nightly_list_updates_ratings_and_brings_people_back(): void
    {
        $this->user(1000001, 'SSA', 3);
        $this->user(1000002, 'EUD', 4);
        $this->org([['id' => 1000001, 'rating' => 5, 'division_id' => 'SSA', 'region_id' => 'AFR', 'subdivision_id' => null], $this->item(1000002, 4)]);
        app(VatssaVatsimFacts::class)->pull();

        $user = DB::table('users')->where('id', 1000001)->first();
        $this->assertSame([5, 'C1', 'Enroute Controller', 'AFR'], [(int) $user->rating, $user->rating_short, $user->rating_long, $user->region]);
        $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division'));
        $this->assertSame('org', $this->fact(1000001)->source);
    }

    #[Test]
    public function a_rating_cc_cannot_represent_is_left_as_it_was(): void
    {
        $this->user(1000001, 'SSA', 5);
        $this->org([$this->item(1000001, 6)]);
        app(VatssaVatsimFacts::class)->pull();

        $this->assertSame(5, (int) DB::table('users')->where('id', 1000001)->value('rating'));
        $this->assertNotNull(User::find(1000001)->rating);
    }

    #[Test]
    public function a_confirmed_leaver_loses_their_training_at_the_nightly_clean_up(): void
    {
        Notification::fake();
        $user = $this->user(1000001);
        $training = Training::factory()->create(['user_id' => $user->id, 'area_id' => Area::factory()->create()->id, 'status' => TrainingStatus::IN_QUEUE->value, 'type' => 1]); // upstream's clean-up skips type 5
        DB::table('vatssa_vatsim_members')->insert(['cid' => 1000001, 'rating' => 3, 'division' => 'SSA', 'needs_lookup' => true]);
        Http::fake(['api.vatsim.net/v2/members/1000001' => Http::response(['id' => 1000001, 'rating' => 3, 'division_id' => 'EUD', 'region_id' => 'EMEA', 'subdivision_id' => null])]);

        app(VatssaVatsimFacts::class)->runLookups();
        $this->artisan('update:member:details')->assertSuccessful();

        $this->assertSame(TrainingStatus::CLOSED_BY_SYSTEM->value, (int) DB::table('trainings')->where('id', $training->id)->value('status'));
    }

    #[Test]
    public function the_daily_limit_on_leavers_holds_and_the_bot_is_told(): void
    {
        $cids = range(1000001, 1000000 + VatssaVatsimFacts::MAX_LEAVES_PER_DAY + 2);
        foreach ($cids as $cid) {
            $this->user($cid);
            DB::table('vatssa_vatsim_members')->insert(['cid' => $cid, 'rating' => 3, 'division' => 'SSA', 'needs_lookup' => true]);
        }
        Http::fake(fn ($request) => Http::response(['id' => (int) basename($request->url()), 'rating' => 3, 'division_id' => 'EUD']));

        app(VatssaVatsimFacts::class)->runLookups(100);
        $this->assertSame(VatssaVatsimFacts::MAX_LEAVES_PER_DAY, DB::table('users')->whereIn('id', $cids)->where('division', 'EUD')->count());
        $this->assertSame(2, app(VatssaVatsimFacts::class)->forBot([1000001])['leaves_waiting']);

        $sent = count(Http::recorded());
        app(VatssaVatsimFacts::class)->runLookups(100);
        $this->assertCount($sent, Http::recorded()); // no calls wasted while the limit holds

        $this->travel(1)->days();
        app(VatssaVatsimFacts::class)->runLookups(100);
        $this->assertSame(count($cids), DB::table('users')->whereIn('id', $cids)->where('division', 'EUD')->count());
    }

    #[Test]
    public function with_writing_off_users_stay_put_and_leavers_can_be_previewed(): void
    {
        config(['vatssa.vatsim_write_users' => false]);
        $this->user(1000001, 'SSA', 3);
        $this->user(1000002, 'SSA', 3);
        $this->org([$this->item(1000001, 5)]);
        app(VatssaVatsimFacts::class)->pull();
        $this->assertSame(3, (int) DB::table('users')->where('id', 1000001)->value('rating'));
        $this->assertSame(5, (int) $this->fact(1000001)->rating);

        Http::swap(new Factory);
        Http::fake(['api.vatsim.net/v2/members/1000002' => Http::response(['id' => 1000002, 'rating' => 3, 'division_id' => 'EUD'])]);
        app(VatssaVatsimFacts::class)->runLookups();

        $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division'));
        $this->assertSame('EUD', $this->fact(1000002)->division);
        $this->artisan('vatssa:vatsim-leavers')->expectsOutputToContain('1 confirmed')->assertSuccessful();
        $this->assertSame('SSA', DB::table('users')->where('id', 1000002)->value('division'));
    }

    #[Test]
    public function the_shrink_guard_survives_a_stale_settings_save(): void
    {
        $this->org(array_map(fn ($i) => $this->item(1000000 + $i), range(1, 10)));
        app(VatssaVatsimFacts::class)->pull();
        DB::table(config('settings.table', 'settings'))->delete();

        Http::swap(new Factory);
        $this->org(array_map(fn ($i) => $this->item(1000000 + $i), range(1, 5)));
        $this->artisan('vatssa:vatsim-pull')->assertFailed();
    }

    #[Test]
    public function one_bad_row_does_not_block_the_others(): void
    {
        DB::table('vatssa_vatsim_members')->insert([['cid' => 1000001, 'needs_lookup' => true], ['cid' => 1000002, 'needs_lookup' => true]]);
        if (DB::getDriverName() === 'sqlite') { // MySQL refuses the long value by itself
            DB::unprepared("CREATE TRIGGER vatssa_test_long BEFORE UPDATE OF division ON vatssa_vatsim_members WHEN length(NEW.division) > 10 BEGIN SELECT RAISE(ABORT, 'too long'); END");
        }
        Http::fake(fn ($request) => (int) basename($request->url()) === 1000001
            ? Http::response(['id' => 1000001, 'rating' => 3, 'division_id' => str_repeat('X', 500)])
            : Http::response(['id' => 1000002, 'rating' => 3, 'division_id' => 'EUD']));

        app(VatssaVatsimFacts::class)->runLookups(2);

        $this->assertSame([1, true], [(int) $this->fact(1000001)->needs_lookup, $this->fact(1000001)->checked_at !== null]);
        $this->assertSame(0, (int) $this->fact(1000002)->needs_lookup);
    }

    #[Test]
    public function the_lookup_lock_expires_after_a_crash(): void
    {
        $this->app->make(Kernel::class);
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'vatsim-pull') && str_contains((string) $e->command, '--lookups'));

        $this->assertNotNull($event);
        $this->assertSame(15, $event->expiresAt);
    }

    #[Test]
    public function an_erased_member_is_not_put_back_in_line(): void
    {
        $user = $this->user(1000001);
        $user->email = Sync::PSEUDONYMISED_EMAIL;
        $user->save();

        VatssaVatsimFacts::refresh(1000001);
        $this->assertNull($this->fact(1000001));
    }
}
