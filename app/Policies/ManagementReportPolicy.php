<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ManagementReportPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can access the training reports
     *
     * @return bool
     */
    public function accessTrainingReports(User $user, $filterArea)
    {
        if ($filterArea) {
            return $user->hasPermission('training.statistics.view', Area::find($filterArea));
        }

        return $user->hasPermission('training.statistics.view');
    }

    /**
     * Determine whether the user can access the training activities report
     *
     * @return bool
     */
    public function accessActivityReports(User $user, $filterArea)
    {
        if ($filterArea) {
            return $user->hasPermission('training.activities.view', Area::find($filterArea));
        }

        return $user->hasPermission('training.activities.view');
    }

    /**
     * Determine whether the user see the training report index
     *
     * @return bool
     */
    public function viewMentors(User $user)
    {
        return $user->hasPermission('fir.management.reports.view');
    }

    /** Determine whether the user can see the feedback index
     *
     */
    public function viewFeedback(User $user): bool
    {
        return $user->accessibleAreasForPermission('feedback.correlated.view')->hasAccess();
    }

    /**
     * Determine whether the user can see the access report
     *
     * @return bool
     */
    public function viewAccessReport(User $user)
    {
        // VATSSA: its own permission, split out of fir.management.reports.view.
        // That permission also opens the training request queue, the mentor
        // index and manual training creation, so gating the access report on it
        // meant anybody who works the queue could audit every role in the
        // division. See config/roles.php.
        return $user->hasPermission('fir.management.access.view');
    }
}
