<?php

namespace Tests\Unit;

use App\Services\PermissionMatrix;
use Tests\TestCase;

class RolesConfigTest extends TestCase
{
    public function test_every_matrix_role_is_a_defined_role(): void
    {
        $definedRoles = array_keys(config('roles.roles'));

        foreach (array_keys(config('roles.matrix')) as $role) {
            $this->assertContains($role, $definedRoles, "Matrix references undefined role {$role}");
        }
    }

    public function test_every_role_grants_at_least_one_permission(): void
    {
        $matrix = new PermissionMatrix;

        foreach (array_keys(config('roles.matrix')) as $role) {
            // VATSSA: a retired role grants nothing, on purpose. `moderator`
            // and `director` are kept in the catalogue only so existing
            // assignments and RoleAssignment's validator still recognise them;
            // they are 'grantable' => false and hold an empty permission set.
            //
            // The invariant is still worth asserting for every role somebody
            // can actually be given -- a live role that grants nothing is a bug.
            if (config("roles.roles.{$role}.grantable", true) === false) {
                continue;
            }

            $this->assertNotEmpty($matrix->permissionsFor($role), "Role '{$role}' grants no permissions.");
        }
    }

    public function test_permission_catalogue_is_unique_and_non_empty(): void
    {
        $permissions = config('roles.permissions');

        $this->assertNotEmpty($permissions);
        $this->assertSame(array_unique($permissions), $permissions, 'The permission catalogue contains duplicates.');
    }

    public function test_all_returns_the_catalogue(): void
    {
        $this->assertSame(array_values(config('roles.permissions')), (new PermissionMatrix)->all());
    }

    public function test_no_orphan_permissions(): void
    {
        $matrix = new PermissionMatrix;

        foreach ($matrix->all() as $permission) {
            $this->assertNotEmpty($matrix->rolesFor($permission), "Permission '{$permission}' is granted to no role.");
        }
    }

    public function test_role_management_permissions_are_registered_and_granted(): void
    {
        $matrix = new PermissionMatrix;
        // VATSSA: 'grantable' => false rather than a hard-coded exclusion of
        // admin. Upstream excludes admin because it is CLI-only; here the same
        // is true of the two retired roles, and a flag says which is which
        // without this list needing to be kept in step by hand.
        $grantableRoles = array_keys(array_filter(
            config('roles.roles'),
            fn ($role) => ($role['grantable'] ?? true) !== false,
        ));

        foreach ($grantableRoles as $role) {
            $permission = "roles.{$role}.manage";
            $this->assertContains($permission, $matrix->all(), "{$permission} is not registered in the catalogue.");
            $this->assertNotEmpty($matrix->rolesFor($permission), "{$permission} is granted by no role.");
        }
    }
}
