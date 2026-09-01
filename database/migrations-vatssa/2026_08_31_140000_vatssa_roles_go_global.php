<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: every role becomes global.
 *
 * ## Why
 *
 * VATSSA has no per-area staff. There is one ATC training manager, one set of
 * pipeline coordinators, one events team, and a mentor who mentors -- not a
 * mentor for Johannesburg who is somehow not a mentor for Cape Town. Upstream
 * is built for a division with genuinely separate areas and offers an area
 * picker on every grant; here that picker is a question with one right answer,
 * asked every time, and a question like that eventually gets answered wrong.
 *
 * ## What this does
 *
 * Collapses every area-scoped assignment into one global row per person per
 * role. Somebody who was a mentor in three areas becomes a mentor, once.
 *
 * ## Why the column still allows an area
 *
 * `config/roles.php` keeps `scope` as it was rather than flipping to 'global'.
 * `RoleAssignment` refuses an area on a global role, and upstream's own test
 * suite creates 181 area-scoped assignments across 35 files -- flipping the
 * catalogue would turn every one of them into an error and put CI back where
 * it was this morning.
 *
 * So the DATA is global and the GRANT PATH is global -- UserPolicy refuses an
 * area, and the picker no longer offers one. The column can still hold an area
 * that nothing in this application will ever put there. When upstream's tests
 * stop needing it, `scope` becomes 'global' and this note goes away.
 *
 * ## Reversible, but not restorable
 *
 * `down()` cannot put back which areas somebody held, because the whole point
 * is to stop recording that. Rolling back leaves everybody global, which is a
 * working state -- `User::hasRole()` treats a global assignment as satisfying
 * any area check, which is what makes this safe in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scoped = DB::table('role_user')->whereNotNull('area_id')->get();

        if ($scoped->isEmpty()) {
            return;
        }

        foreach ($scoped->groupBy(fn ($row) => $row->user_id . ':' . $row->role) as $rows) {
            $first = $rows->first();

            $alreadyGlobal = DB::table('role_user')
                ->where('user_id', $first->user_id)
                ->where('role', $first->role)
                ->whereNull('area_id')
                ->exists();

            // One row survives per person per role. Promoting the first and
            // deleting the rest, rather than deleting all and inserting, keeps
            // whatever `created_at` recorded -- "mentor since March" is worth
            // more than a tidy insert.
            if (! $alreadyGlobal) {
                DB::table('role_user')->where('id', $first->id)->update(['area_id' => null]);
                $rows = $rows->skip(1);
            }

            DB::table('role_user')->whereIn('id', $rows->pluck('id'))->delete();
        }
    }

    public function down(): void
    {
        // Nothing. See the note above: which areas somebody held is exactly the
        // fact this migration exists to stop keeping.
    }
};
