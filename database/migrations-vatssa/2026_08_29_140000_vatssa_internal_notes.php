<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: internal notes, at two scopes with two audiences.
 *
 * Control Center's existing comment is an activity-log entry on a training,
 * visible to anybody who can see the training -- the mentor included. There is
 * nowhere at all to record the things staff actually need to write down:
 *
 *   disciplinary history           a warning given, and what for
 *   standing in the division       why somebody was removed, or refused
 *   sensitive interactions         a complaint, a safeguarding concern
 *   problems with the division     the awkward context behind a decision
 *
 * Two scopes, because those are two different kinds of fact:
 *
 *   TRAINING notes  -- about this training. ATC training manager and admin.
 *   USER notes      -- about the person, across every training. Admin only.
 *
 * A user note outlives the training that prompted it, which is the entire
 * point: closing a training must not erase the reason it was closed.
 *
 * ## The audience is written on every note
 *
 * `App\Models\Vatssa\InternalNote::audience()` states in words who can read a
 * note, and the form says it before you type. Somebody writing something
 * sensitive has to know exactly who will see it -- a note written believing it
 * was admin-only and readable by a manager is worse than no note at all.
 *
 * ## Nothing here is exposed
 *
 * Not on the bridge, not on the roster endpoint, not in the API. These tables
 * are read by two Blade panels and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_internal_notes', function (Blueprint $table) {
            $table->id();
            // 'training' or 'user'. The scope decides the audience.
            $table->string('scope', 20);
            $table->unsignedBigInteger('user_id');
            // Set on a training note, so the note can be shown in context.
            // Null on a user note, which belongs to the person, not a training.
            $table->unsignedBigInteger('training_id')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            // nullOnDelete, NOT cascade. A deleted training must not take the
            // disciplinary record with it -- that is the case the record exists
            // for.
            $table->foreign('training_id')->references('id')->on('trainings')->nullOnDelete();
            // Likewise the author: who wrote it is useful, but losing the note
            // because they left the division would be absurd.
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['user_id', 'scope']);
            $table->index(['training_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_internal_notes');
    }
};
