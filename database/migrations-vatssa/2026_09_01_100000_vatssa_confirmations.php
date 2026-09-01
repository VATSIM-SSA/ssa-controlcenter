<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: when somebody joined, and every deadline they have been given.
 *
 * ## Two separate problems, one migration, because they answer one question
 *
 * "Has this student done what we asked, and by when." Upstream answers a
 * quarter of it -- `training_interests` covers the interest confirmation and
 * nothing else -- while the three platform deadlines the pipeline enforces
 * lived only in the bot's SQLite and in whatever the student could find in
 * their inbox. A coordinator asking "did we actually chase them" had to ask the
 * bot's owner.
 *
 * ## Why a table rather than columns on the training
 *
 * Because these repeat. A student can be asked to join Discord, leave, and be
 * asked again; the theory deadline moves when leave is taken. Columns hold the
 * latest of each and quietly destroy the history, which is the half that
 * matters when somebody disputes being closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vatssa_user_platforms', function (Blueprint $table) {
            // Nullable, and shown as "?" rather than blank.
            //
            // Every row that exists today has no date, and will never get one:
            // Discord's join timestamp comes off the guild member object, and
            // Moodle's off the user record, so both are only knowable from the
            // moment the sweep starts asking for them. A blank cell reads as
            // "not joined"; a question mark reads as "we did not ask", which is
            // the truth and stops somebody treating an old row as a finding.
            $table->timestamp('discord_joined_at')->nullable()->after('on_discord');
            $table->timestamp('moodle_registered_at')->nullable()->after('on_moodle');
        });

        Schema::create('vatssa_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('training_id');

            // The four things we email a due date about. Interest is upstream's
            // and stays in `training_interests` -- this table does not copy it,
            // the view unions the two. Duplicating it would mean two rows that
            // can disagree about the same fact.
            $table->string('type', 32);

            $table->timestamp('sent_at');
            $table->timestamp('deadline');
            $table->timestamp('confirmed_at')->nullable();

            // 0 = still open, 1 = invalidated (the requirement went away, e.g.
            // leave was granted), 2 = missed. Mirrors `training_interests`
            // rather than inventing a second vocabulary for the same three
            // states, so the merged table reads as one thing.
            $table->unsignedTinyInteger('expired')->default(0);

            // How many times we have chased. The Discord ladder escalates
            // before it removes, and "how many emails did he get" is the first
            // question asked when somebody appeals a removal.
            $table->unsignedTinyInteger('reminders')->default(0);

            $table->timestamps();

            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();

            // One open row per training per type. A second open Discord
            // deadline on one training is always a bug -- either a double send
            // or a lost close -- and this makes it fail at the write rather
            // than show up as two rows nobody can choose between.
            $table->index(['training_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_confirmations');

        Schema::table('vatssa_user_platforms', function (Blueprint $table) {
            $table->dropColumn(['discord_joined_at', 'moodle_registered_at']);
        });
    }
};
