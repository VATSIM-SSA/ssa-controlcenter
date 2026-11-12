<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// VATSSA: website content edited from CC (content-editor role, website.*
// permissions, MinIO writes, Cloudflare purge).
class VatssaWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['screenshot', 'staff', 'rvas', 'policies', 'minutes'];

    private bool $purgeSucceeds = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('vatssa-homepage');
        Storage::fake('vatssa-policy');
        Storage::fake('vatssa-minutes');

        config([
            'vatssa-website.cloudflare.zone_id' => 'zone123',
            'vatssa-website.cloudflare.api_token' => 'token123',
            'vatssa-website.cloudflare.purge_everything' => false,
        ]);
        // One stub, switched by a property: a second Http::fake() would not
        // replace the first.
        Http::fake(['api.cloudflare.com/*' => fn () => $this->purgeSucceeds
            ? Http::response(['success' => true, 'errors' => []])
            : Http::response(['success' => false, 'errors' => [['code' => 10000]]], 403)]);
    }

    private function userWithRole(string $role, ?int $areaId = null): User
    {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role' => $role, 'area_id' => $areaId]);

        return $user->fresh();
    }

    private function version(string $path): string
    {
        $disk = Storage::disk('vatssa-homepage');

        return $disk->exists($path) ? hash('sha256', $disk->get($path)) : 'missing';
    }

    private function json(string $path): mixed
    {
        return json_decode(Storage::disk('vatssa-homepage')->get($path), true);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    #[Test]
    public function the_content_editor_role_is_global_only(): void
    {
        $this->assertSame('global', config('roles.roles.content-editor.scope'));

        $this->expectException(\InvalidArgumentException::class);
        User::factory()->create()->roleAssignments()->create([
            'role' => 'content-editor',
            'area_id' => Area::factory()->create()->id,
        ]);
    }

    #[Test]
    public function only_admin_holds_the_website_permissions_besides_content_editor(): void
    {
        foreach (self::PAGES as $page) {
            $permission = config("vatssa-website.pages.{$page}.permission");
            $this->assertContains($permission, config('roles.permissions'), "{$permission} is not in the catalogue");

            $roles = app(\App\Services\PermissionMatrix::class)->rolesFor($permission);
            sort($roles);
            $this->assertSame(['admin', 'content-editor'], $roles, $permission);
        }
    }

    #[Test]
    public function admin_and_content_editor_open_every_page(): void
    {
        foreach (['admin', 'content-editor'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get(route('vatssa.website.index'))->assertOk()->assertSee('collapseVatssaWebsite', false);
            foreach (self::PAGES as $page) {
                $this->actingAs($user)->get(route("vatssa.website.{$page}"))->assertOk();
            }
        }
    }

    #[Test]
    public function other_staff_are_refused_everywhere(): void
    {
        $area = Area::factory()->create();
        $users = [
            $this->userWithRole('atc-training-manager'),
            $this->userWithRole('feedback-team'),
            $this->userWithRole('mentor', $area->id),
            User::factory()->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user)->get(route('vatssa.website.index'))->assertForbidden();
            foreach (self::PAGES as $page) {
                $this->actingAs($user)->get(route("vatssa.website.{$page}"))->assertForbidden();
                $this->actingAs($user)->post(route("vatssa.website.{$page}.save"), ['version' => 'missing'])->assertForbidden();
            }
        }

        Storage::disk('vatssa-homepage')->assertDirectoryEmpty('/');
    }

    #[Test]
    public function each_page_has_its_own_permission(): void
    {
        // A future role holding one permission gets one page and nothing else.
        config([
            'roles.roles.staff-list-only' => ['name' => 'Staff list only', 'description' => 'test', 'scope' => 'global'],
            'roles.matrix.staff-list-only' => ['website.staff.manage'],
        ]);
        $user = $this->userWithRole('staff-list-only');

        $this->actingAs($user)->get(route('vatssa.website.staff'))->assertOk();
        foreach (['screenshot', 'rvas', 'policies', 'minutes'] as $page) {
            $this->actingAs($user)->get(route("vatssa.website.{$page}"))->assertForbidden();
        }
    }

    #[Test]
    public function admin_can_grant_content_editor_globally_and_others_cannot(): void
    {
        $admin = $this->userWithRole('admin');
        $manager = $this->userWithRole('atc-training-manager');
        $target = User::factory()->create();
        $area = Area::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('updateRole', [$target, 'content-editor', null]));
        $this->assertFalse(Gate::forUser($manager)->allows('updateRole', [$target, 'content-editor', null]));
        $this->assertFalse(Gate::forUser($manager)->allows('updateRole', [$target, 'content-editor', $area]));
    }

    // -----------------------------------------------------------------
    // Staff list
    // -----------------------------------------------------------------

    #[Test]
    public function the_staff_list_is_saved_in_the_homepage_shape(): void
    {
        $original = json_encode([
            'Leadership Team' => [
                ['id' => 'jane-doe-ddd', 'name' => 'Jane Doe', 'role' => 'Director', 'email' => 'one@vatssa.com', 'cid' => '1000001 - VATSSA1'],
            ],
            'Staff Team' => [
                ['id' => 'old-person', 'name' => 'Old Person', 'role' => 'Events', 'email' => 'events@vatssa.com'],
            ],
        ]);
        Storage::disk('vatssa-homepage')->put('stafflist.json', $original);

        $this->actingAs($this->userWithRole('content-editor'))
            ->post(route('vatssa.website.staff.save'), [
                'version' => $this->version('stafflist.json'),
                'rows' => [
                    ['id' => 'jane-doe-ddd', 'team' => 'Leadership Team', 'name' => 'Jane Doe-Smith', 'role' => 'Director', 'email' => 'one@vatssa.com', 'cid' => '1000001 - VATSSA1', 'order' => '10'],
                    ['id' => 'old-person', 'team' => 'Staff Team', 'name' => 'Old Person', 'role' => 'Events', 'email' => 'events@vatssa.com', 'order' => '20', 'delete' => '1'],
                    ['id' => '', 'team' => 'Staff Team', 'name' => 'vacant', 'role' => 'Events Manager', 'email' => 'events@vatssa.com', 'cid' => 'VATSSA5', 'order' => '40'],
                    ['id' => '', 'team' => 'Staff Team', 'name' => 'New Person', 'role' => 'Membership', 'email' => '', 'cid' => '', 'order' => '30'],
                    ['id' => '', 'team' => '', 'name' => '', 'role' => '', 'email' => '', 'cid' => '', 'order' => ''],
                ],
            ])
            ->assertRedirect(route('vatssa.website.staff'))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'Leadership Team' => [
                // The id names the photo, so it survives a name change.
                ['id' => 'jane-doe-ddd', 'name' => 'Jane Doe-Smith', 'role' => 'Director', 'email' => 'one@vatssa.com', 'cid' => '1000001 - VATSSA1'],
            ],
            'Staff Team' => [
                ['id' => 'new-person', 'name' => 'New Person', 'role' => 'Membership'],
                ['id' => 'vacant', 'name' => 'vacant', 'role' => 'Events Manager', 'email' => 'events@vatssa.com', 'cid' => 'VATSSA5'],
            ],
        ], $this->json('stafflist.json'));

        // The previous version is kept.
        $history = Storage::disk('vatssa-homepage')->files('_history');
        $this->assertCount(1, $history);
        $this->assertSame($original, Storage::disk('vatssa-homepage')->get($history[0]));

        $this->assertDatabaseHas('activity_logs', ['log_name' => 'other', 'description' => 'Website: Staff list saved (3 entries)']);
    }

    #[Test]
    public function two_new_people_with_the_same_name_get_different_ids(): void
    {
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => 'missing',
            'rows' => [
                ['id' => 'sam-lee', 'team' => 'Team', 'name' => 'Sam Lee', 'role' => 'A'],
                ['id' => '', 'team' => 'Team', 'name' => 'Sam Lee', 'role' => 'B'],
                ['id' => '', 'team' => 'Team', 'name' => 'Sam Lee', 'role' => 'C'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['sam-lee', 'sam-lee-2', 'sam-lee-3'], array_column($this->json('stafflist.json')['Team'], 'id'));
    }

    #[Test]
    public function the_data_envelope_is_kept_if_the_file_uses_one(): void
    {
        Storage::disk('vatssa-homepage')->put('stafflist.json', json_encode(['data' => ['Team' => [['id' => 'a', 'name' => 'A', 'role' => 'R']]]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $this->version('stafflist.json'),
            'rows' => [['id' => 'a', 'team' => 'Team', 'name' => 'A', 'role' => 'R2']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['data' => ['Team' => [['id' => 'a', 'name' => 'A', 'role' => 'R2']]]], $this->json('stafflist.json'));
    }

    #[Test]
    public function an_empty_staff_list_is_refused(): void
    {
        Storage::disk('vatssa-homepage')->put('stafflist.json', '{"Team":[{"id":"a","name":"A","role":"R"}]}');

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $this->version('stafflist.json'),
            'rows' => [['id' => 'a', 'team' => 'Team', 'name' => 'A', 'role' => 'R', 'delete' => '1']],
        ])->assertSessionHasErrors('rows');

        $this->assertSame('{"Team":[{"id":"a","name":"A","role":"R"}]}', Storage::disk('vatssa-homepage')->get('stafflist.json'));
    }

    #[Test]
    public function a_stale_form_saves_nothing(): void
    {
        Storage::disk('vatssa-homepage')->put('stafflist.json', '{"Team":[{"id":"a","name":"A","role":"R"}]}');
        $staleVersion = $this->version('stafflist.json');
        Storage::disk('vatssa-homepage')->put('stafflist.json', '{"Team":[{"id":"b","name":"B","role":"R"}]}');

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $staleVersion,
            'rows' => [['id' => 'a', 'team' => 'Team', 'name' => 'A', 'role' => 'R']],
        ])->assertSessionHasErrors('version');

        $this->assertSame('{"Team":[{"id":"b","name":"B","role":"R"}]}', Storage::disk('vatssa-homepage')->get('stafflist.json'));
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // rVAs
    // -----------------------------------------------------------------

    #[Test]
    public function the_rva_list_is_saved_with_numeric_tiers_and_https_links(): void
    {
        $editor = $this->userWithRole('content-editor');

        $this->actingAs($editor)->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [['name' => 'Plain Air', 'site' => 'http://plain.example', 'tier' => '1']],
        ])->assertSessionHasErrors('rows.0.site');
        $this->assertFalse(Storage::disk('vatssa-homepage')->exists('rva.json'));

        $this->actingAs($editor)->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [
                ['name' => 'Second Air', 'site' => 'https://second.example/', 'signup' => '', 'tier' => '2', 'description' => '', 'order' => '20'],
                ['name' => 'First Air', 'site' => 'https://first.example/', 'signup' => 'https://first.example/join', 'tier' => '1', 'description' => 'Hub in FAOR', 'order' => '10'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['data' => [
            ['name' => 'First Air', 'site' => 'https://first.example/', 'signup' => 'https://first.example/join', 'tier' => 1, 'description' => 'Hub in FAOR'],
            ['name' => 'Second Air', 'site' => 'https://second.example/', 'tier' => 2],
        ]], $this->json('rva.json'));
    }

    // -----------------------------------------------------------------
    // Screenshot winner
    // -----------------------------------------------------------------

    #[Test]
    public function a_new_winner_goes_first_with_a_fresh_image_name(): void
    {
        Storage::disk('vatssa-homepage')->put('screenshot-winner.json', json_encode(['data' => [
            ['name' => 'Old Winner - 1000002', 'link' => 'https://files.vatssa.com/homepage-data/screenshot-comp/oct26.png', 'month' => 'Oct26'],
            ['name' => 'Broken entry'],
        ]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.screenshot.save'), [
            'version' => $this->version('screenshot-winner.json'),
            'name' => 'New Winner',
            'cid' => '1000003',
            'month' => 'Nov26',
            'image' => UploadedFile::fake()->image('Winner Photo.jpg', 1920, 1080),
        ])->assertSessionHasNoErrors();

        $winners = $this->json('screenshot-winner.json')['data'];
        $this->assertCount(2, $winners, 'the malformed entry is dropped, the valid one kept');
        $this->assertSame('New Winner - 1000003', $winners[0]['name']);
        $this->assertSame('Nov26', $winners[0]['month']);
        $this->assertMatchesRegularExpression('#^https://files\.vatssa\.com/homepage-data/screenshot-comp/nov26-[a-z0-9]{6}\.jpg$#', $winners[0]['link']);
        $this->assertSame('Old Winner - 1000002', $winners[1]['name']);

        $path = substr($winners[0]['link'], strlen('https://files.vatssa.com/homepage-data/'));
        Storage::disk('vatssa-homepage')->assertExists($path);
    }

    #[Test]
    public function a_small_picture_is_refused(): void
    {
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.screenshot.save'), [
            'version' => 'missing',
            'name' => 'New Winner',
            'cid' => '1000003',
            'month' => 'Nov26',
            'image' => UploadedFile::fake()->image('small.jpg', 800, 600),
        ])->assertSessionHasErrors('image');

        $this->assertSame([], Storage::disk('vatssa-homepage')->allFiles());
    }

    // -----------------------------------------------------------------
    // Policies and minutes
    // -----------------------------------------------------------------

    #[Test]
    public function a_new_document_keeps_a_clean_file_name(): void
    {
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.minutes.save'), [
            'version' => 'missing',
            'rows' => [[
                'id' => '',
                'title' => 'Staff Meeting Minutes',
                'description' => '12 November 2026',
                'file' => UploadedFile::fake()->create('VATSSA Staff Minutes 2026-11-12 (public).pdf', 100, 'application/pdf'),
            ]],
        ])->assertSessionHasNoErrors();

        Storage::disk('vatssa-minutes')->assertExists('vatssa_staff_minutes_2026-11-12_public.pdf');
        $this->assertSame(['minutes' => [[
            'id' => 'vatssa_staff_minutes_2026-11-12_public.pdf',
            'title' => 'Staff Meeting Minutes',
            'description' => '12 November 2026',
            'url' => 'https://files.vatssa.com/meeting-minutes/vatssa_staff_minutes_2026-11-12_public.pdf',
        ]]], $this->json('minutes.json'));
    }

    #[Test]
    public function an_existing_file_is_never_overwritten_from_a_new_row(): void
    {
        Storage::disk('vatssa-policy')->put('rva_policy.pdf', 'linked from elsewhere');

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.policies.save'), [
            'version' => 'missing',
            'rows' => [[
                'id' => '',
                'title' => 'rVA Policy',
                'description' => 'Partner airlines',
                'file' => UploadedFile::fake()->create('rva_policy.pdf', 100, 'application/pdf'),
            ]],
        ])->assertSessionHasErrors('rows.0.file');

        $this->assertSame('linked from elsewhere', Storage::disk('vatssa-policy')->get('rva_policy.pdf'));
        $this->assertFalse(Storage::disk('vatssa-homepage')->exists('policies.json'));
    }

    #[Test]
    public function a_new_version_replaces_the_file_under_the_same_name_and_purges_it(): void
    {
        $url = 'https://files.vatssa.com/policy/rva_policy.pdf';
        Storage::disk('vatssa-policy')->put('rva_policy.pdf', 'version 1');
        Storage::disk('vatssa-homepage')->put('policies.json', json_encode(['policies' => [
            ['id' => 'rva_policy.pdf', 'title' => 'rVA Policy', 'description' => 'Partner airlines', 'url' => $url],
        ]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.policies.save'), [
            'version' => $this->version('policies.json'),
            'rows' => [[
                'id' => 'rva_policy.pdf',
                'title' => 'rVA Policy',
                'description' => 'Partner airlines, v1.2',
                'file' => UploadedFile::fake()->create('anything.pdf', 100, 'application/pdf'),
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertNotSame('version 1', Storage::disk('vatssa-policy')->get('rva_policy.pdf'));
        $this->assertSame($url, $this->json('policies.json')['policies'][0]['url']);
        $this->assertSame('Partner airlines, v1.2', $this->json('policies.json')['policies'][0]['description']);

        Http::assertSent(fn ($request) => in_array($url, $request['files'] ?? [], true)
            && in_array('https://files.vatssa.com/homepage-data/policies.json', $request['files'], true)
            && in_array('https://vatssa.com/about/policies', $request['files'], true));
    }

    #[Test]
    public function a_form_cannot_point_an_entry_at_another_object(): void
    {
        Storage::disk('vatssa-homepage')->put('policies.json', json_encode(['policies' => []]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.policies.save'), [
            'version' => $this->version('policies.json'),
            'rows' => [['id' => 'someone-elses.pdf', 'title' => 'Fake', 'description' => 'Fake']],
        ])->assertSessionHasErrors('rows.0.id');
    }

    #[Test]
    public function a_non_pdf_is_refused(): void
    {
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.policies.save'), [
            'version' => 'missing',
            'rows' => [['id' => '', 'title' => 'Bad', 'description' => 'Bad', 'file' => UploadedFile::fake()->create('bad.pdf', 10, 'text/html')]],
        ])->assertSessionHasErrors('rows.0.file');

        $this->assertSame([], Storage::disk('vatssa-policy')->allFiles());
    }

    // -----------------------------------------------------------------
    // Cloudflare
    // -----------------------------------------------------------------

    #[Test]
    public function purge_everything_is_one_switch(): void
    {
        config(['vatssa-website.cloudflare.purge_everything' => true]);

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [['name' => 'A', 'site' => 'https://a.example/', 'tier' => '1']],
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache'
            && $request['purge_everything'] === true
            && $request->hasHeader('Authorization', 'Bearer token123'));
    }

    #[Test]
    public function a_failed_purge_still_saves_and_warns(): void
    {
        $this->purgeSucceeds = false;

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [['name' => 'A', 'site' => 'https://a.example/', 'tier' => '1']],
        ])->assertSessionHasNoErrors()->assertSessionHas('vatssa_website_warning');

        Storage::disk('vatssa-homepage')->assertExists('rva.json');
    }

    #[Test]
    public function without_cloudflare_settings_nothing_is_sent_and_the_editor_is_told(): void
    {
        config(['vatssa-website.cloudflare.api_token' => null]);

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [['name' => 'A', 'site' => 'https://a.example/', 'tier' => '1']],
        ])->assertSessionHas('vatssa_website_warning');

        Http::assertNothingSent();
    }
}
