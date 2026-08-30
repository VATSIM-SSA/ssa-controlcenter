<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: who was mentoring what, as of the last look.
 *
 * ## Why a shadow table rather than a hook
 *
 * `training_mentor` is a plain pivot and `detach()` fires no Eloquent events,
 * so there is nothing to listen to. Upstream detaches a mentor in at least
 * three places -- `UpdateMemberDetails` when somebody leaves the division,
 * `UserDelete` on a data-deletion request, and `TrainingController` when a
 * person edits the training -- and none of them tells anybody.
 *
 * Patching those three would mean three more modified upstream files, a
 * conflict on every release, and a promise I cannot keep: that those are the
 * only three. A daily diff against this table catches every path, including
 * the ones nobody has found yet.
 *
 * ## What it makes possible
 *
 * The mentorless check alone misses a SWAP. Take student X off mentor A, give
 * them to mentor B, and the training is never mentorless -- so A is never told
 * they lost a student, and keeps a slot reserved for somebody who is not
 * coming back. That slot is one the next student in the queue cannot have.
 *
 * ## An empty table means "no prior knowledge"
 *
 * Not "nobody was mentoring anybody". The first run seeds it silently, or the
 * whole division would be emailed that every mentor had lost every student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_training_mentors', function (Blueprint $table) {
            $table->unsignedBigInteger('training_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('seen_at')->useCurrent();

            $table->primary(['training_id', 'user_id']);

            // cascadeOnDelete, unlike the action log. This is a cache of a
            // current fact rather than a record of what happened: a deleted
            // training has no mentor to have lost.
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_training_mentors');
    }
};
