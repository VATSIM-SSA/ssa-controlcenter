<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return bool
     */
    public function index(User $user)
    {
        return $user->hasPermission('users.manage');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return bool
     */
    public function view(User $user, User $model)
    {
        return $user->is($model) || $user->hasPermission('users.manage') || $user->isTeaching($model);
    }

    /**
     * Determine whether the user can view the access table.
     *
     * @return bool
     */
    public function viewAccess(User $user)
    {
        return $user->hasPermission('users.access.view');
    }

    /**
     * Determine whether the user can view the reports of themselves or another user.
     *
     * @return bool
     */
    public function viewReports(User $user, User $model)
    {
        return $user->is($model) || $user->hasPermission('fir.management.reports.view');
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return bool
     */
    public function update(User $user, User $model)
    {
        return $user->hasPermission('users.manage');
    }

    /**
     * Determine whether the user may grant or revoke the requested role
     * for the model user. A null area means a global (area-less) assignment.
     */
    public function updateRole(User $user, User $model, string $requestedRole, ?Area $requestedArea): bool
    {
        if (! $this->update($user, $model)) {
            return false;
        }

        // VATSSA: roles flagged ungrantable are refused here, not merely
        // hidden from the picker. A control that is absent from a page is not
        // a control that cannot be reached -- the request is still a POST, and
        // the only thing that ever stopped one is a check on this side.
        //
        // Covers admin (managed exclusively through the user:makeadmin CLI
        // command) and the two retired roles kept only so RoleAssignment's
        // validator recognises them. See config/roles.php.
        if (config("roles.roles.{$requestedRole}.grantable", true) === false) {
            return false;
        }

        // Global (area-less) assignments require the role's scope to allow them
        if ($requestedArea === null
            && ! in_array(config("roles.roles.{$requestedRole}.scope"), ['both', 'global'], true)) {
            return false;
        }

        $permission = "roles.{$requestedRole}.manage";

        // Grant authority must be held at (or above) the grant's scope. A global grant always
        // needs global authority; a role declaring grant_scope 'global' needs it even in an area.
        $requiresGlobalAuthority = $requestedArea === null
            || config("roles.roles.{$requestedRole}.grant_scope", 'area') === 'global';

        return $requiresGlobalAuthority
            ? $user->hasGlobalPermission($permission)
            : $user->hasPermission($permission, $requestedArea);
    }
}
