<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Position;
use App\Models\User;
use App\Services\PermissionMatrix;
use Database\Seeders\VatssaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The VATSSA divergence, asserted.
 *
 * Why this file exists at all: the fork keeps its divergence in ADDED files
 * wherever it can, because an added file cannot conflict on an upstream
 * absorption. The cost of that choice is that git then gives no signal when
 * upstream changes underneath one. A conflict is the drift detector for the one
 * file VATSSA modifies (`config/roles.php`); this test file is the drift
 * detector for everything VATSSA adds.
 *
 * It runs in CI on every PR, which means it runs on the `integration` -> `dev`
 * PR of every upstream absorption. That is the moment you want to hear about it.
 *
 * Upstream's own `tests/Unit/RolesConfigTest.php` already covers the generic
 * invariants: no orphan permissions, no duplicate catalogue entries, every
 * matrix role defined, every grantable role carrying a `roles.*.manage`. Do not
 * duplicate those here. What follows is only what is true of VATSSA and would
 * not be true of upstream.
 */
class VatssaTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = [
        'admin',
        'atc-training-manager',
        'pipeline-coordinator',
        'mentor',
        'nav-editor',
        'feedback-team',
    ];

    #[Test]
    public function the_role_list_is_exactly_the_six_vatssa_roles(): void
    {
        // Catches an upstream role reappearing after a badly resolved conflict
        // in config/roles.php, which is the most likely way this file breaks.
        $this->assertSame(self::ROLES, array_keys(config('roles.roles')));
    }

    #[Test]
    public function atc_training_manager_is_a_superset_of_pipeline_coordinator(): void
    {
        // ATM is written out longhand rather than inheriting, because the matrix
        // has no inheritance mechanism. Editing one and forgetting the other is
        // the standing risk, and this is what catches it.
        $matrix = new PermissionMatrix;

        $missing = array_diff(
            $matrix->permissionsFor('pipeline-coordinator'),
            $matrix->permissionsFor('atc-training-manager'),
        );

        $this->assertSame([], array_values($missing));
    }

    #[Test]
    public function atc_training_manager_may_grant_mentor_and_nothing_else(): void
    {
        // In v7.0.0 this is pure config, which is why the UserPolicy override
        // was dropped. If a future upstream release moves grant authority back
        // into code, this fails and the override has to come back.
        $matrix = new PermissionMatrix;

        $grants = array_values(array_filter(
            $matrix->permissionsFor('atc-training-manager'),
            fn (string $p) => str_starts_with($p, 'roles.'),
        ));

        $this->assertSame(['roles.mentor.manage'], $grants);
    }

    #[Test]
    public function only_admin_may_grant_every_role(): void
    {
        $matrix = new PermissionMatrix;

        foreach (self::ROLES as $role) {
            if ($role === 'admin') {
                continue;
            }

            $this->assertContains(
                "roles.{$role}.manage",
                $matrix->permissionsFor('admin'),
                "admin cannot grant {$role}",
            );
        }
    }

    #[Test]
    public function admin_is_not_grantable_through_the_ui(): void
    {
        $this->assertNotContains('roles.admin.manage', config('roles.permissions'));
        $this->assertSame('global', config('roles.roles.admin.scope'));
    }

    #[Test]
    public function every_grant_permission_names_a_real_role(): void
    {
        // UserPolicy::updateRole builds "roles.{$role}.manage" from the role key.
        // A rename on one side and not the other silently means nobody can grant
        // anything, with no error anywhere.
        foreach (config('roles.permissions') as $permission) {
            if (! str_starts_with($permission, 'roles.')) {
                continue;
            }

            $role = substr($permission, strlen('roles.'), -strlen('.manage'));

            $this->assertArrayHasKey($role, config('roles.roles'), "{$permission} names no role");
        }
    }

    #[Test]
    public function the_reference_migration_loads_areas_and_positions(): void
    {
        // RefreshDatabase has already run every migration path, this one
        // included, so the rows are simply expected to be here.
        $this->assertSame('Southern Africa', Area::find(1)->name);
        $this->assertSame('Central Africa', Area::find(4)->name);

        $this->assertSame(401, Position::count());
        $this->assertSame(27, Position::distinct()->count('fir'));

        // Every position must belong to an area, or it is invisible to the
        // area-scoped screens that are the whole point of the role model.
        $this->assertSame(0, Position::whereNull('area_id')->count());
    }

    #[Test]
    public function the_reference_migration_is_idempotent(): void
    {
        $before = DB::table('positions')->orderBy('callsign')->get()->toArray();

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations-vatssa'])
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('positions')->orderBy('callsign')->get()->toArray());
    }

    #[Test]
    public function the_seeder_runs_and_assigns_the_expected_roles(): void
    {
        // Exercises the added seeder end to end. If upstream renames a model,
        // changes a factory signature, or alters the role_user shape, this is
        // where it surfaces.
        $this->seed(VatssaSeeder::class);

        $expected = [
            10000004 => [['nav-editor', 1], ['feedback-team', null]],
            10000005 => [['pipeline-coordinator', null]],
            10000008 => [['pipeline-coordinator', 1]],
            10000009 => [['atc-training-manager', null]],
            10000010 => [['admin', null]],
        ];

        foreach ($expected as $cid => $assignments) {
            $actual = User::find($cid)->roleAssignments()
                ->get()
                ->map(fn ($a) => [$a->role, $a->area_id])
                ->sortBy(fn ($a) => $a[0])
                ->values()
                ->all();

            $this->assertEquals(collect($assignments)->sortBy(fn ($a) => $a[0])->values()->all(), $actual, "CID {$cid}");
        }

        // No role_user row may reference a role the matrix cannot resolve.
        $orphans = DB::table('role_user')
            ->whereNotIn('role', self::ROLES)
            ->pluck('role')
            ->unique()
            ->all();

        $this->assertSame([], $orphans);
    }

    #[Test]
    public function the_seeder_refuses_to_run_in_production(): void
    {
        // The one guard that stands between a mistyped artisan command and
        // eleven fake controllers in the live member list.
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);

        $this->seed(VatssaSeeder::class);
    }
}
