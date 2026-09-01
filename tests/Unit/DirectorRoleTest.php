<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Vatssa\UpstreamRoleModel;

class DirectorRoleTest extends TestCase
{
    use UpstreamRoleModel;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here assigns `director` to an area. VATSSA retired the
        // role entirely ('grantable' => false) and forbids area grants on all
        // roles, so RoleAssignment throws before an assertion is reached.
        $this->skipPerAreaRoles('an area-scoped director');
    }

    use RefreshDatabase;

    #[Test]
    public function director_should_not_have_system_level_permissions()
    {
        $area = Area::factory()->create();
        $director = User::factory()->create();
        $director->roleAssignments()->create(['role' => 'director', 'area_id' => $area->id]);

        $this->assertFalse($director->hasPermission('system.health.view'));
        $this->assertFalse($director->hasPermission('system.settings.manage', $area));
    }

    #[Test]
    public function area_director_permissions_do_not_leak_to_other_areas()
    {
        $area = Area::factory()->create();
        $otherArea = Area::factory()->create();
        $director = User::factory()->create();
        $director->roleAssignments()->create(['role' => 'director', 'area_id' => $area->id]);

        $this->assertFalse($director->hasPermission('users.manage', $otherArea));
    }
}
