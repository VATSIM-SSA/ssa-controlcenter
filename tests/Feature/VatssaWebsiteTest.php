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

    private const FILE_AREAS = ['documents', 'staff-files'];

    private bool $purgeSucceeds = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('vatssa-homepage');
        Storage::fake('vatssa-policy');
        Storage::fake('vatssa-minutes');
        Storage::fake('vatssa-documents');
        Storage::fake('vatssa-staff-shared');
        Storage::fake('vatssa-archive');

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

    private function stored(string $path): mixed
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
        ], $this->stored('stafflist.json'));

        // The previous version is kept, privately.
        $history = Storage::disk('vatssa-archive')->files('json/staff');
        $this->assertCount(1, $history);
        $this->assertSame($original, Storage::disk('vatssa-archive')->get($history[0]));
        $this->assertSame([], Storage::disk('vatssa-homepage')->files('_history'));

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

        $this->assertSame(['sam-lee', 'sam-lee-2', 'sam-lee-3'], array_column($this->stored('stafflist.json')['Team'], 'id'));
    }

    #[Test]
    public function the_data_envelope_is_kept_if_the_file_uses_one(): void
    {
        Storage::disk('vatssa-homepage')->put('stafflist.json', json_encode(['data' => ['Team' => [['id' => 'a', 'name' => 'A', 'role' => 'R']]]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $this->version('stafflist.json'),
            'rows' => [['id' => 'a', 'team' => 'Team', 'name' => 'A', 'role' => 'R2']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['data' => ['Team' => [['id' => 'a', 'name' => 'A', 'role' => 'R2']]]], $this->stored('stafflist.json'));
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
        ]], $this->stored('rva.json'));
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

        $winners = $this->stored('screenshot-winner.json')['data'];
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

        Storage::disk('vatssa-minutes')->assertExists('vatssa-staff-minutes-2026-11-12-public.pdf');
        $this->assertSame(['minutes' => [[
            'id' => 'vatssa-staff-minutes-2026-11-12-public.pdf',
            'title' => 'Staff Meeting Minutes',
            'description' => '12 November 2026',
            'url' => 'https://files.vatssa.com/meeting-minutes/vatssa-staff-minutes-2026-11-12-public.pdf',
        ]]], $this->stored('minutes.json'));
    }

    #[Test]
    public function an_existing_file_is_never_overwritten_from_a_new_row(): void
    {
        Storage::disk('vatssa-policy')->put('rva-policy.pdf', 'linked from elsewhere');

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.policies.save'), [
            'version' => 'missing',
            'rows' => [[
                'id' => '',
                'title' => 'rVA Policy',
                'description' => 'Partner airlines',
                'file' => UploadedFile::fake()->create('rva policy.pdf', 100, 'application/pdf'),
            ]],
        ])->assertSessionHasErrors('rows.0.file');

        $this->assertSame('linked from elsewhere', Storage::disk('vatssa-policy')->get('rva-policy.pdf'));
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
        $versions = Storage::disk('vatssa-archive')->files('policy/rva_policy.pdf');
        $this->assertCount(1, $versions);
        $this->assertStringEndsWith('__rva_policy.pdf', $versions[0]);
        $this->assertSame('version 1', Storage::disk('vatssa-archive')->get($versions[0]));
        $this->assertSame($url, $this->stored('policies.json')['policies'][0]['url']);
        $this->assertSame('Partner airlines, v1.2', $this->stored('policies.json')['policies'][0]['description']);

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
    // Archiving instead of deleting
    // -----------------------------------------------------------------

    #[Test]
    public function archiving_a_document_takes_it_off_the_website_but_keeps_it(): void
    {
        $url = 'https://files.vatssa.com/meeting-minutes/old-meeting.pdf';
        Storage::disk('vatssa-minutes')->put('old-meeting.pdf', 'old minutes');
        Storage::disk('vatssa-minutes')->put('new-meeting.pdf', 'new minutes');
        Storage::disk('vatssa-homepage')->put('minutes.json', json_encode(['minutes' => [
            ['id' => 'new-meeting.pdf', 'title' => 'New', 'description' => '2 Nov', 'url' => 'https://files.vatssa.com/meeting-minutes/new-meeting.pdf'],
            ['id' => 'old-meeting.pdf', 'title' => 'Old', 'description' => '1 Nov', 'url' => $url],
        ]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.minutes.save'), [
            'version' => $this->version('minutes.json'),
            'rows' => [
                ['id' => 'new-meeting.pdf', 'title' => 'New', 'description' => '2 Nov'],
                ['id' => 'old-meeting.pdf', 'title' => 'Old', 'description' => '1 Nov', 'delete' => '1'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['new-meeting.pdf'], array_column($this->stored('minutes.json')['minutes'], 'id'));
        Storage::disk('vatssa-minutes')->assertMissing('old-meeting.pdf');
        Storage::disk('vatssa-minutes')->assertExists('new-meeting.pdf');
        $archived = Storage::disk('vatssa-archive')->files('meeting-minutes/old-meeting.pdf');
        $this->assertCount(1, $archived);
        $this->assertSame('old minutes', Storage::disk('vatssa-archive')->get($archived[0]));
        Http::assertSent(fn ($request) => in_array($url, $request['files'] ?? [], true));
    }

    // -----------------------------------------------------------------
    // Staff photos and rVA logos
    // -----------------------------------------------------------------

    #[Test]
    public function a_staff_photo_is_stored_and_the_old_one_archived(): void
    {
        $oldPhoto = 'https://files.vatssa.com/homepage-data/staff/jane-doe-aaaaaa.png';
        Storage::disk('vatssa-homepage')->put('staff/jane-doe-aaaaaa.png', 'old photo');
        Storage::disk('vatssa-homepage')->put('stafflist.json', json_encode(['Team' => [
            ['id' => 'jane-doe', 'name' => 'Jane Doe', 'role' => 'R', 'photo' => $oldPhoto],
        ]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $this->version('stafflist.json'),
            'rows' => [[
                'id' => 'jane-doe', 'team' => 'Team', 'name' => 'Jane Doe', 'role' => 'R', 'photo' => $oldPhoto,
                'photo_file' => UploadedFile::fake()->image('me.png', 400, 400),
            ]],
        ])->assertSessionHasNoErrors();

        $photo = $this->stored('stafflist.json')['Team'][0]['photo'];
        $this->assertMatchesRegularExpression('#^https://files\.vatssa\.com/homepage-data/staff/jane-doe-[a-z0-9]{6}\.png$#', $photo);
        Storage::disk('vatssa-homepage')->assertExists(substr($photo, strlen('https://files.vatssa.com/homepage-data/')));
        Storage::disk('vatssa-homepage')->assertMissing('staff/jane-doe-aaaaaa.png');
        $this->assertCount(1, Storage::disk('vatssa-archive')->files('homepage-data/staff/jane-doe-aaaaaa.png'));
    }

    #[Test]
    public function a_person_blanked_off_the_list_does_not_leave_a_public_photo(): void
    {
        $photo = 'https://files.vatssa.com/homepage-data/staff/old-person-bbbbbb.png';
        Storage::disk('vatssa-homepage')->put('staff/old-person-bbbbbb.png', 'photo');
        Storage::disk('vatssa-homepage')->put('stafflist.json', json_encode(['Team' => [
            ['id' => 'keep', 'name' => 'Keep', 'role' => 'R'],
            ['id' => 'old-person', 'name' => 'Old Person', 'role' => 'R', 'photo' => $photo],
        ]]));

        // The editor cleared the text fields instead of ticking Remove.
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.staff.save'), [
            'version' => $this->version('stafflist.json'),
            'rows' => [
                ['id' => 'keep', 'team' => 'Team', 'name' => 'Keep', 'role' => 'R'],
                ['id' => 'old-person', 'team' => '', 'name' => '', 'role' => '', 'photo' => $photo],
            ],
        ])->assertSessionHasNoErrors();

        Storage::disk('vatssa-homepage')->assertMissing('staff/old-person-bbbbbb.png');
        $this->assertCount(1, Storage::disk('vatssa-archive')->allFiles('homepage-data/staff'));
    }

    #[Test]
    public function a_form_cannot_carry_an_image_that_is_not_on_the_list(): void
    {
        Storage::disk('vatssa-homepage')->put('rva.json', json_encode(['data' => [['name' => 'A', 'site' => 'https://a.example/', 'tier' => 1]]]));

        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.rvas.save'), [
            'version' => $this->version('rva.json'),
            'rows' => [['name' => 'A', 'site' => 'https://a.example/', 'tier' => '1', 'logo' => 'https://evil.example/logo.png']],
        ])->assertSessionHasErrors('rows.0.logo');
    }

    #[Test]
    public function an_rva_logo_is_stored_and_removing_it_archives_it(): void
    {
        $editor = $this->userWithRole('content-editor');

        $this->actingAs($editor)->post(route('vatssa.website.rvas.save'), [
            'version' => 'missing',
            'rows' => [['name' => 'Bonair Virtual', 'site' => 'https://a.example/', 'tier' => '1', 'logo_file' => UploadedFile::fake()->image('logo.webp', 600, 300)]],
        ])->assertSessionHasNoErrors();

        $logo = $this->stored('rva.json')['data'][0]['logo'];
        $this->assertMatchesRegularExpression('#/homepage-data/rvas/bonair-virtual-[a-z0-9]{6}\.webp$#', $logo);

        $this->actingAs($editor)->post(route('vatssa.website.rvas.save'), [
            'version' => $this->version('rva.json'),
            'rows' => [['name' => 'Bonair Virtual', 'site' => 'https://a.example/', 'tier' => '1', 'logo' => $logo, 'remove_logo' => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('logo', $this->stored('rva.json')['data'][0]);
        $this->assertSame([], Storage::disk('vatssa-homepage')->files('rvas'));
        $this->assertCount(1, Storage::disk('vatssa-archive')->allFiles('homepage-data/rvas'));
    }

    // -----------------------------------------------------------------
    // Other files and Staff files
    // -----------------------------------------------------------------

    #[Test]
    public function content_editors_manage_both_file_areas_and_others_cannot(): void
    {
        $editor = $this->userWithRole('content-editor');
        $manager = $this->userWithRole('atc-training-manager');

        foreach (self::FILE_AREAS as $area) {
            $this->actingAs($editor)->get(route("vatssa.website.{$area}"))->assertOk();
            $this->actingAs($manager)->get(route("vatssa.website.{$area}"))->assertForbidden();
            $this->actingAs($manager)->post(route("vatssa.website.{$area}.upload"), ['file' => UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')])->assertForbidden();
        }
    }

    #[Test]
    public function an_upload_keeps_a_clean_name_and_never_overwrites_by_accident(): void
    {
        $editor = $this->userWithRole('content-editor');

        $this->actingAs($editor)->post(route('vatssa.website.documents.upload'), [
            'file' => UploadedFile::fake()->create('Code of Conduct 2026.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        Storage::disk('vatssa-documents')->assertExists('code-of-conduct-2026.pdf');

        $this->actingAs($editor)->post(route('vatssa.website.documents.upload'), [
            'file' => UploadedFile::fake()->create('code of conduct 2026.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('vatssa-archive')->allFiles());

        $this->actingAs($editor)->post(route('vatssa.website.documents.upload'), [
            'file' => UploadedFile::fake()->create('code of conduct 2026.pdf', 20, 'application/pdf'),
            'replace' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertCount(1, Storage::disk('vatssa-archive')->files('documents/code-of-conduct-2026.pdf'));
        Http::assertSent(fn ($request) => in_array('https://files.vatssa.com/documents/code-of-conduct-2026.pdf', $request['files'] ?? [], true));
    }

    #[Test]
    public function scripts_are_refused(): void
    {
        foreach (['page.html' => 'text/html', 'image.svg' => 'image/svg+xml', 'run.php' => 'text/x-php'] as $name => $mime) {
            $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.documents.upload'), [
                'file' => UploadedFile::fake()->create($name, 1, $mime),
            ])->assertSessionHasErrors('file');
        }

        $this->assertSame([], Storage::disk('vatssa-documents')->allFiles());
    }

    #[Test]
    public function a_folder_is_slugged_and_archiving_moves_the_file(): void
    {
        $editor = $this->userWithRole('content-editor');

        $this->actingAs($editor)->post(route('vatssa.website.staff-files.upload'), [
            'file' => UploadedFile::fake()->create('Budget 2027.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'folder' => 'Finance Team',
        ])->assertSessionHasNoErrors();
        Storage::disk('vatssa-staff-shared')->assertExists('finance-team/budget-2027.xlsx');

        $this->actingAs($editor)->get(route('vatssa.website.staff-files.download', ['path' => 'finance-team/budget-2027.xlsx']))->assertOk();
        $this->actingAs($editor)->get(route('vatssa.website.staff-files.download', ['path' => '../homepage-data/stafflist.json']))->assertNotFound();
        $this->actingAs($editor)->get(route('vatssa.website.staff-files.download', ['path' => 'not-there.pdf']))->assertNotFound();

        $this->actingAs($editor)->post(route('vatssa.website.staff-files.archive'), ['path' => 'finance-team/budget-2027.xlsx'])->assertSessionHasNoErrors();
        Storage::disk('vatssa-staff-shared')->assertMissing('finance-team/budget-2027.xlsx');
        $this->assertCount(1, Storage::disk('vatssa-archive')->files('staff-shared/finance-team/budget-2027.xlsx'));
    }

    // -----------------------------------------------------------------
    // The archive
    // -----------------------------------------------------------------

    #[Test]
    public function only_admin_can_see_the_archive_or_delete_for_good(): void
    {
        Storage::disk('vatssa-archive')->put('policy/a.pdf/20261112-000000-abcd__a.pdf', 'old');
        $path = 'policy/a.pdf/20261112-000000-abcd__a.pdf';

        $this->actingAs($this->userWithRole('content-editor'))->get(route('vatssa.website.archive'))->assertForbidden();
        $this->actingAs($this->userWithRole('content-editor'))->post(route('vatssa.website.archive.delete'), ['path' => $path, 'confirm' => '1'])->assertForbidden();

        $admin = $this->userWithRole('admin');
        $this->actingAs($admin)->get(route('vatssa.website.archive'))->assertOk()->assertSee('a.pdf');
        $this->actingAs($admin)->get(route('vatssa.website.archive.download', ['path' => $path]))->assertOk();

        $this->actingAs($admin)->post(route('vatssa.website.archive.delete'), ['path' => $path])->assertSessionHasErrors('confirm');
        Storage::disk('vatssa-archive')->assertExists($path);

        $this->actingAs($admin)->post(route('vatssa.website.archive.delete'), ['path' => $path, 'confirm' => '1'])->assertSessionHasNoErrors();
        Storage::disk('vatssa-archive')->assertMissing($path);
    }

    #[Test]
    public function the_archive_permission_is_admin_only(): void
    {
        $roles = app(\App\Services\PermissionMatrix::class)->rolesFor('website.archive.manage');
        $this->assertSame(['admin'], $roles);

        foreach (['website.documents.manage', 'website.staff-files.manage'] as $permission) {
            $roles = app(\App\Services\PermissionMatrix::class)->rolesFor($permission);
            sort($roles);
            $this->assertSame(['admin', 'content-editor'], $roles, $permission);
        }
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
