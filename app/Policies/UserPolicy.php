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
        // VATSSA: `users.profile.view` added. A pipeline coordinator works the
        // training queue and could not open the page of the person whose
        // training they were working -- the list of somebody's trainings lives
        // there and nowhere else, so the queue sent them to a 403.
        //
        // Reading a record and editing one are separate: this grants the page,
        // `users.manage` still gates every control on it.
        return $user->is($model)
            || $user->hasPermission('users.manage')
            || $user->hasPermission('users.profile.view')
            || $user->isTeaching($model);
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

        // VATSSA: every role is granted globally, and only globally.
        //
        // There are no per-area staff here. One ATC training manager, one set
        // of pipeline coordinators, one events team, and a mentor who mentors
        // -- not a mentor for Johannesburg who is somehow not a mentor for Cape
        // Town. Upstream offers an area on every grant because it is built for
        // a division where that is a real question; here it is a question with
        // one right answer, asked every time, and those get answered wrong
        // eventually.
        //
        // Refused HERE rather than by flipping every scope to 'global' in the
        // catalogue: RoleAssignment throws on an area given to a global role,
        // and upstream's suite creates 181 area-scoped assignments across 35
        // files. This closes the door without breaking their tests.
        if ($requestedArea !== null) {
            return false;
        }

        // Kept from upstream, and now unreachable with an area -- a role whose
        // scope forbids a global grant cannot be granted at all here. That is
        // correct rather than a gap: 'area' scope means "only meaningful per
        // area", and VATSSA has decided nothing is.
        if (! in_array(config("roles.roles.{$requestedRole}.scope"), ['both', 'global'], true)) {
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
