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
            ->assertExactJson(['data' => [['id' => $ids[1], 'user_id' => 1002], ['id' => $ids[2], 'user_id' => 1003]]]);
        $this->getJson($url, ['Authorization' => 'Bearer bot-key'])->assertJsonCount(3, 'data');
        $this->getJson($url . '?after=x', ['Authorization' => 'Bearer bot-key'])->assertUnprocessable();
    }
}
