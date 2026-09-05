<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: what a member was to the division, and since when.
 *
 * ## Why this table exists at all
 *
 * Every value on it is DERIVED -- from the VATSIM division field, the roster,
 * and the transfer/visit system. Nothing here is set by hand, and the profile
 * that displays it asserts nothing of its own. So the obvious question is why
 * store it rather than compute it on every page load.
 *
 * The answer is the only thing a derivation cannot produce: SINCE WHEN. Today's
 * state says a member is home; it cannot say they became home on 2 June, and no
 * amount of re-deriving recovers a date that was never written down. That is
 * the whole job of this table -- it is a record of transitions, not a cache.
 *
 * ## Why two axes in one table
 *
 * `axis` is 'relationship' (home / international / visiting / transferring) or
 * 'roster' (approved controller, or not). They are separate questions and a
 * member has an answer to both at once -- a visiting controller can hold
 * approved-controller permissions, which is ordinary rather than a
 * contradiction.
 *
 * They share a table because they share a shape: a value, a date it took
 * effect, and one write path that appends only when the derived answer differs
 * from the last row. Two tables would have meant two of everything to say the
 * same sentence twice.
 *
 * ## History starts the day this ships
 *
 * Control Center stores the roster as current state with no events behind it,
 * and the division field arrives from VATSIM with no history attached. There is
 * nothing to backfill from, and inventing dates would be worse than admitting
 * the record starts here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_member_status_history', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // 'relationship' or 'roster'. See App\Helpers\Vatssa\StatusAxis.
            $table->string('axis', 20);

            // The value that took effect. A string rather than an enum column
            // so a new relationship never needs a migration to be recordable.
            $table->string('value', 40);

            // When it took effect, which is not when the row was written: the
            // sync runs on a schedule, so a change is noticed some hours after
            // it happened. `created_at` keeps the noticing; this keeps the
            // change.
            $table->timestamp('effective_from');

            // What produced it, in words, for the reader of a history six
            // months from now. "Transfer request #41 completed" answers a
            // question that "home" on its own does not.
            $table->string('note')->nullable();

            $table->timestamps();

            // The query this table exists to serve: one member's history on one
            // axis, newest first.
            $table->index(['user_id', 'axis', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_member_status_history');
    }
};
