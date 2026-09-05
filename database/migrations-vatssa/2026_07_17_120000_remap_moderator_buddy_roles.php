<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA role remap, for the cutover from the pre-v7 model.
 *
 * Runs AFTER upstream's own groups/permissions to `role_user` migration, so by
 * the time this runs `role_user` already holds rows with role IN
 * ('admin', 'director', 'moderator', 'mentor', 'buddy') carried over from the
 * old system. None of those except `admin` and `mentor` exist in the VATSSA
 * `config/roles.php`, so every other row has to be placed or removed here.
 *
 * `admin` and `mentor` rows are left untouched. Both keys are hardcoded
 * elsewhere in upstream and are kept deliberately.
 *
 * On a fresh database (dev, staging, CI) there are no legacy rows and this is a
 * no-op that passes silently. It only has work to do against a production
 * import.
 *
 * Not reversible: the mapping is lossy. Roll back by restoring the
 * pre-migration dump.
 *
 * ---------------------------------------------------------------------------
 * BLOCKER before the cutover: STAFF_CID_MAP is empty. Fill it from the real
 * staff list. Produce the list of CIDs that need placing with:
 *
 *   SELECT user_id, area_id FROM role_user WHERE role IN ('moderator','director');
 *
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    /**
     * CID => target role, or CID => null to drop the row entirely.
     *
     * Valid targets: 'atc-training-manager', 'pipeline-coordinator',
     * 'nav-editor', 'feedback-team', 'mentor'.
     *
     * 'admin' is deliberately NOT a valid target here. It is granted only
     * through `php artisan user:makeadmin`, so that the live admin list is
     * never something a migration decided.
     *
     * An unmapped CID is a hard failure, not a warning. A staff member who
     * arrives at go-live with no permissions and no error in the log is the
     * worst outcome available, so every legacy row must be an explicit decision.
     */
    private const STAFF_CID_MAP = [
        // 1234567 => 'atc-training-manager',
        // 2345678 => 'pipeline-coordinator',
        // 3456789 => null,   // no longer staff, drop the row
    ];

    /**
     * CIDs whose 'buddy' row becomes 'mentor'. Every other buddy row is deleted.
     * VATSSA has no buddy concept, so this is normally empty.
     */
    private const BUDDY_TO_MENTOR_CIDS = [
        // 4567890,
    ];

    private const VALID_TARGETS = [
        'atc-training-manager',
        'pipeline-coordinator',
        'nav-editor',
        'feedback-team',
        'mentor',
    ];

    public function up(): void
    {
        $this->assertMapIsSane();

        $legacy = DB::table('role_user')
            ->whereIn('role', ['moderator', 'director'])
            ->get();

        if ($legacy->isNotEmpty()) {
            $this->assertEveryLegacyRowIsMapped($legacy);

            foreach ($legacy as $row) {
                $target = self::STAFF_CID_MAP[$row->user_id];

                if ($target === null) {
                    DB::table('role_user')->where('id', $row->id)->delete();

                    continue;
                }

                DB::table('role_user')->where('id', $row->id)->update([
                    'role' => $target,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('role_user')
            ->where('role', 'buddy')
            ->whereIn('user_id', self::BUDDY_TO_MENTOR_CIDS)
            ->update(['role' => 'mentor', 'updated_at' => now()]);

        DB::table('role_user')->where('role', 'buddy')->delete();

        $this->assertNoUnresolvableRolesRemain();
    }

    public function down(): void
    {
        // Not reversible. Restore the pre-migration dump.
    }

    /**
     * Catch a typo in the map before it writes anything. A role that is not in
     * config/roles.php produces a row the matrix cannot resolve, which looks
     * from the UI exactly like "the permission is wrong".
     */
    private function assertMapIsSane(): void
    {
        foreach (self::STAFF_CID_MAP as $cid => $target) {
            if ($target === null) {
                continue;
            }

            if (! in_array($target, self::VALID_TARGETS, true)) {
                throw new RuntimeException("STAFF_CID_MAP: '{$target}' (CID {$cid}) is not a valid target role.");
            }

            if (! array_key_exists($target, config('roles.roles', []))) {
                throw new RuntimeException("STAFF_CID_MAP: '{$target}' (CID {$cid}) is not in config/roles.php.");
            }
        }
    }

    private function assertEveryLegacyRowIsMapped(Collection $legacy): void
    {
        $unmapped = $legacy
            ->reject(fn ($row) => array_key_exists($row->user_id, self::STAFF_CID_MAP))
            ->pluck('user_id')
            ->unique()
            ->sort()
            ->values();

        if ($unmapped->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'STAFF_CID_MAP does not cover ' . $unmapped->count() . ' legacy role holder(s): '
            . $unmapped->implode(', ') . '. Add each CID with a target role, or with null to drop the row. '
            . 'Leaving them unmapped would put staff live with no permissions and no error.'
        );
    }

    /**
     * The final safety net. After this migration nothing in role_user may name a
     * role the matrix cannot resolve.
     */
    private function assertNoUnresolvableRolesRemain(): void
    {
        $configured = array_keys(config('roles.roles', []));

        $orphans = DB::table('role_user')
            ->whereNotIn('role', $configured)
            ->pluck('role')
            ->unique()
            ->sort()
            ->values();

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'role_user still holds role(s) absent from config/roles.php after the remap: '
                . $orphans->implode(', ')
            );
        }
    }
};
