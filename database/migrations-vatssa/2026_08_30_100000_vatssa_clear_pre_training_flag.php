<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: clear upstream's "pre-training completed" flag.
 *
 * The button that set it is gone. It was a self-declared tickbox with nothing
 * behind it: it gated no transition, blocked no rule and was read by nothing.
 * Its only effect was a tick beside the status, which alongside a stage now
 * called "Theory phase" read as "the theory is done" when it meant nothing of
 * the sort. The theory pass comes from Moodle.
 *
 * CLEARING THE DATA IS WHAT LETS THE BLADE STAY VERBATIM UPSTREAM.
 * `training/index.blade.php` only shows the tick when the flag is true, so with
 * every row false the file needs no VATSSA edit at all -- one fewer modified
 * file, and one fewer conflict on every future release.
 *
 * The column stays. Dropping it would be a second divergence for no gain, and
 * upstream may yet do something with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('trainings', 'pre_training_completed')) {
            DB::table('trainings')->where('pre_training_completed', true)
                ->update(['pre_training_completed' => false]);
        }
    }

    public function down(): void
    {
        // Not reversible: the old values are not recorded anywhere, and
        // inventing them would be worse than leaving the flag off.
    }
};
