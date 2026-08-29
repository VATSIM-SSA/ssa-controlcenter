<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: tell the database about AWAITING_MENTOR.
 *
 * `trainings.status` carries a column comment listing every status. It is the
 * only documentation anybody reading the database directly will ever see, and
 * after adding a status it is wrong.
 *
 * NOTE THAT NO ROWS ARE TOUCHED. The new status is appended as 4 rather than
 * inserted as 2 precisely so that nothing has to be. See
 * `App\Helpers\TrainingStatus` for why renumbering a live status column is a
 * worse idea than it looks -- `training_activities` stores past transitions as
 * bare integers, and renumbering rewrites history silently.
 *
 * Comment-only, so `down()` restores the old wording and nothing else.
 */
return new class extends Migration
{
    private const NEW = '-4: Closed by system, -3: Closed on student’s request, -2: Closed on TA request, '
        . '-1: Completed, 0: In queue, 1: Pre-training, 2: Active training, 3: Awaiting exam, '
        . '4: Awaiting mentor (VATSSA; sits between 1 and 2 in the lifecycle, appended so nothing renumbers)';

    private const OLD = '-4: Closed by system, -3: Closed on student’s request, -2: Closed on TA request, '
        . '-1: Completed, 0: In queue, 1: Pre-training, 2: Active training, 3: Awaiting exam';

    public function up(): void
    {
        $this->comment(self::NEW);
    }

    public function down(): void
    {
        $this->comment(self::OLD);
    }

    /**
     * Column comments are MySQL syntax. On any other driver this is a no-op
     * rather than an error -- the test suite runs on SQLite, and a migration
     * that cannot run there would fail every test for a comment.
     */
    private function say(string $message): void
    {
        if (isset($this->output)) {
            $this->output->writeln("<comment>{$message}</comment>");
        }
    }

    private function comment(string $text): void
    {
        if (! Schema::hasTable('trainings')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Wrapped, because this is documentation and nothing more. The comment
        // is for whoever reads the schema directly; no code reads it. A DDL
        // statement that fails -- a column definition that has drifted, a user
        // without ALTER -- must not be the thing that stops a deploy over a
        // sentence.
        try {
            DB::statement(sprintf(
                "ALTER TABLE `trainings` MODIFY `status` TINYINT NOT NULL DEFAULT 0 COMMENT %s",
                DB::getPdo()->quote($text)
            ));
        } catch (\Throwable $e) {
            $this->say("could not update the trainings.status comment: " . $e->getMessage());
        }
    }
};
