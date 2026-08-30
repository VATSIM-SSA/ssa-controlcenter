<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: what the automation did, and what it noticed and could not fix.
 *
 * The pipeline moves people between stages, sends emails, enrols them in
 * Moodle, kicks suspended members from Discord and returns mentorless students
 * to the queue. Until now every one of those was invisible: the effect landed
 * in the database and the reason lived in a container log nobody reads.
 *
 * That is the thing that makes automation frightening to run. Not that it acts
 * -- that it acts silently, so the only way to notice a wrong decision is for
 * somebody to complain.
 *
 * ## Two kinds of row, and the second is the point
 *
 * ACTIONS are things the system did. Moved a training, sent a warning, enrolled
 * a student.
 *
 * OBSERVATIONS are things it noticed and deliberately did NOT act on: a desk
 * with nobody on it, a rating whose Moodle course is unconfigured, a training
 * that has been mentorless for a fortnight. Software that stays quiet about
 * what it cannot handle is worse than software that does nothing, because
 * silence reads as "fine".
 *
 * ## It does not replace the training timeline
 *
 * Anything about one training is ALSO written to `training_activities`, so it
 * shows on that training's own timeline where the people working it will see
 * it. This table is the division-wide view -- the question "what has the
 * automation been doing" has no answer on a per-training page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_action_log', function (Blueprint $table) {
            $table->id();

            // 'bot', 'system', or a CID. Kept as a string because the actor is
            // usually not a user, and a nullable user_id would make "the bot"
            // and "we do not know" indistinguishable.
            $table->string('actor', 20)->default('system');

            // A stable key -- 'training.returned_to_queue', 'discord.kicked'.
            // Grouped and filtered on, so it must not be prose.
            $table->string('action', 60);

            // Prose for a human, written at the time. Not regenerated from the
            // key later: what mattered was true when it happened.
            $table->string('summary', 255);

            // 'info' for something done, 'warning' for something noticed and
            // not done. The whole page filters on this.
            $table->string('level', 10)->default('info');

            $table->unsignedBigInteger('training_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Whatever else mattered. Deliberately loose -- an action log that
            // needs a migration to record a new kind of detail stops being
            // used.
            $table->json('context')->nullable();

            $table->timestamps();

            // nullOnDelete throughout: the log outlives what it describes. A
            // deleted training must not erase the record of what was done to
            // it, which is most of the reason to keep a log at all.
            $table->foreign('training_id')->references('id')->on('trainings')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['level', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('training_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_action_log');
    }
};
