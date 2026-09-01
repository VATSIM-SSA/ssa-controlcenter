<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: the practical exam booking workflow.
 *
 * ## Why this is its own table and not columns on TrainingExamination
 *
 * `training_examinations` is the RESULT: who examined, on what position, on
 * what date, pass or fail. It is written once, after the fact, and it is the
 * division's permanent exam record.
 *
 * This is the arrangement of the thing -- five parties, eight handoffs, and a
 * fortnight of waiting before the row upstream cares about even exists. Mixing
 * them would mean a permanent exam record full of half-booked exams that never
 * happened, and every report that reads it having to know the difference.
 *
 * When an exam is sat, the examiner files a `TrainingExamination` as they
 * always have. This table's job is finished at that point and it says so by
 * moving to COMPLETED.
 *
 * ## Everything hangs off one stage column
 *
 * Not `authorised_at`, `cleared_at`, `confirmed_at` as separate flags. Those
 * answer "what has happened" and never answer the question everybody actually
 * asks, which is "whose turn is it". See App\Helpers\ExamStage.
 *
 * The timestamps are still here, because "when did the events team clear this"
 * is a real question after the fact -- but the STAGE is the truth and the
 * timestamps are history.
 *
 * ## Availability is a poll, not a column
 *
 * `vatssa_availability_polls` already collects when people are free, with a
 * grid, a heat map and per-role responses. The student's availability and the
 * events team's clearances are two roles on one poll, so this points at it
 * rather than growing its own copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_exams', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('training_id');
            $table->unsignedSmallInteger('stage')->default(0);

            // Who asked, and who let it through. Both nullable so the row
            // survives either of them leaving the division.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('authorised_by')->nullable();
            $table->timestamp('authorised_at')->nullable();

            // The availability grid. Created when the student is asked, so it
            // does not exist for an exam nobody has authorised yet.
            $table->unsignedBigInteger('poll_id')->nullable();
            $table->timestamp('availability_submitted_at')->nullable();
            $table->timestamp('events_cleared_at')->nullable();

            // The examiner takes it and names a slot in one action. Two steps
            // would let somebody claim an exam and never book it, which is the
            // failure the whole workflow exists to prevent.
            $table->unsignedBigInteger('examiner_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();

            // The events team's checklist after confirmation. Booleans rather
            // than a stage each, because they happen in parallel and any of
            // them can be the one still outstanding.
            $table->boolean('banner_made')->default(false);
            $table->boolean('on_discord')->default(false);
            $table->boolean('on_myvatsim')->default(false);
            $table->boolean('on_social')->default(false);
            $table->boolean('vatsim_approved')->default(false);
            $table->timestamp('published_at')->nullable();

            // Why it was called off. Required by the controller when cancelling:
            // a cancelled exam with no reason is a row three people will ask
            // about and nobody can answer.
            $table->string('outcome_note', 255)->nullable();

            $table->timestamps();

            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('authorised_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('examiner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
            $table->foreign('poll_id')->references('id')->on('vatssa_availability_polls')->nullOnDelete();

            // The two questions every page asks: what is open, and what is
            // happening soon.
            $table->index(['stage', 'scheduled_for']);
            $table->index('training_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_exams');
    }
};
