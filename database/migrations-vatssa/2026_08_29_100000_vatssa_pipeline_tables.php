<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: the five tables the training pipeline needs.
 *
 * All added, none touching an upstream table, so upstream can restructure
 * anything it likes without meeting these. Each one exists because Control
 * Center v7.0.0 has nowhere to put the fact:
 *
 *   platforms        -- CC has no concept of Discord, and Moodle only as a link
 *   theory attempts  -- CC has no theory field at all
 *   message log      -- CC cannot see what its own mailer sent
 *   templates        -- CC's editor is append-only, on three emails, per area
 *   moodle courses   -- the rating -> course map, editable without a deploy
 *
 * Run with: php artisan migrate --path=database/migrations-vatssa
 */
return new class extends Migration
{
    public function up(): void
    {
        // Where somebody is, on the platforms Control Center cannot see.
        // Keyed on the user, one row each: this is current state, not history.
        Schema::create('vatssa_user_platforms', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedBigInteger('discord_user_id')->nullable();
            $table->boolean('on_discord')->default(false);
            $table->unsignedInteger('moodle_user_id')->nullable();
            $table->boolean('on_moodle')->default(false);
            // Not a VATSIM member at all -- a bot, or a test account. Distinct
            // from "absent from Discord", and the panel must say so rather than
            // showing a broken tick.
            $table->boolean('vatsim_member')->default(true);
            // The sweep is daily, so a panel that does not show its age invites
            // somebody to read a day-old false as a fact.
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('discord_user_id');
        });

        // Every attempt at every rating's theory exam.
        //
        // KEYED ON PERSON PLUS RATING, NEVER ON TRAINING. A result owned by a
        // training dies with it: close the training, open a new one, and the
        // pass is gone even though the person still knows the material. Keyed
        // this way, every S2 training that person ever opens sees the same row.
        Schema::create('vatssa_theory_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('rating', 10);
            $table->unsignedInteger('moodle_course_id');
            $table->unsignedInteger('moodle_quiz_id');
            $table->unsignedInteger('moodle_attempt_id')->nullable();
            $table->decimal('grade', 5, 2)->nullable();
            $table->boolean('passed')->default(false);
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // The bot re-reads the same attempts every poll, so writes must be
            // idempotent. This is what makes them so.
            $table->unique(['user_id', 'moodle_quiz_id', 'moodle_attempt_id'], 'vatssa_attempt_unique');
            $table->index(['user_id', 'rating', 'taken_at']);
        });

        // What a student has actually been told, by anybody.
        //
        // Subjects and kinds only. The body never travels: the log answers
        // "what was this student told, and when", and the email itself is the
        // detail. CPT marks in particular must not appear here.
        Schema::create('vatssa_message_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('training_id')->nullable();
            $table->string('subject');
            $table->string('kind', 40)->default('other');
            // 'bot' or 'control-center'. Both send through the same relay, so
            // the sender address cannot tell them apart -- the message id can.
            $table->string('source', 20)->default('control-center');
            $table->string('message_id')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->nullOnDelete();
            // The Brevo poll overlaps its window on purpose, to survive
            // downtime. Without this, the overlap duplicates every row.
            $table->unique(['user_id', 'message_id'], 'vatssa_message_unique');
            $table->index(['training_id', 'sent_at']);
        });

        // The bot's messages, editable in the window everybody already uses.
        //
        // Control Center's own template editor cannot carry these: it is
        // append-only, on three emails, per area. The bodies are hardcoded in
        // Blade.
        Schema::create('vatssa_message_templates', function (Blueprint $table) {
            $table->string('key', 20)->primary();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('channel', 20)->default('email');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Which rating needs which Moodle course, and what counts as a pass.
        //
        // ONE QUIZ PER COURSE COUNTS: the final one, which is that rating's
        // theory exam. Earlier quizzes in a course are practice and are not
        // tracked at all. A rating absent from this table needs no theory,
        // which is how the visiting, transfer and refresher tracks fall out
        // without a special case.
        Schema::create('vatssa_moodle_courses', function (Blueprint $table) {
            $table->string('rating', 10)->primary();
            $table->unsignedInteger('course_id');
            $table->unsignedInteger('exam_quiz_id');
            $table->decimal('pass_mark', 5, 2)->default(75);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_moodle_courses');
        Schema::dropIfExists('vatssa_message_templates');
        Schema::dropIfExists('vatssa_message_log');
        Schema::dropIfExists('vatssa_theory_attempts');
        Schema::dropIfExists('vatssa_user_platforms');
    }
};
