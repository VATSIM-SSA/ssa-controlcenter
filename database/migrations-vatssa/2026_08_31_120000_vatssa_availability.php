<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: when people are free.
 *
 * ## Why this is not Rallly
 *
 * Rallly and Crab.fit are both good, both open source, and neither has a
 * documented API. The whole point of collecting availability here is that
 * other things READ it -- the CPT workflow pings examiners with a student's
 * free slots, the events team clears them against unpublished plans, and a
 * confirmation writes a booking. A tool the rest of the system cannot query is
 * a screenshot.
 *
 * Control Center also already knows who everybody is, holds the examiner
 * endorsements, and owns the bookings calendar to overlay. Integrating a third
 * party would have been more work than this table.
 *
 * ## The shape
 *
 * A POLL is a question: "when can you do this?" It has a purpose (a CPT, a
 * mentoring session, a staff meeting), a window, and optionally a training it
 * belongs to.
 *
 * A RESPONSE is one person's answer, stored as a list of slot start times. A
 * row per slot per person would be tidier in theory and is wrong in practice:
 * a month of half-hour slots is 1,488 rows per person, rewritten on every
 * change, for data that is only ever read as a whole.
 *
 * ## Slots are UTC, always
 *
 * VATSSA spans four hours of time zones and VATSIM runs on Zulu. Storing local
 * time and converting on read is how a CPT gets confirmed for the wrong hour,
 * and there is no worse bug in this workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_availability_polls', function (Blueprint $table) {
            $table->id();

            // 'cpt', 'mentoring', 'meeting'. Not an enum column: the set will
            // grow, and a migration to add a purpose is friction that pushes
            // people back to asking in Discord.
            $table->string('purpose', 20)->default('mentoring');

            $table->string('title', 120);
            $table->text('description')->nullable();

            // The window people may mark. A month by default -- long enough to
            // find a slot, short enough that nobody is guessing.
            $table->date('starts_on');
            $table->date('ends_on');

            // Half-hour by default. Some purposes want coarser: a staff meeting
            // does not need 30-minute resolution across a month.
            $table->unsignedSmallInteger('slot_minutes')->default(30);

            $table->unsignedBigInteger('training_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            // Set when the answer is settled, so a poll stops asking. NOT a
            // deletion: the CPT workflow needs to show what was agreed, and
            // "why was it that time" is the first question after a clash.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('confirmed_slot')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();

            // The student presses this. Until then the poll is a draft and
            // nobody is pinged -- an examiner asked to look at a half-filled
            // grid learns to ignore the next ping.
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['purpose', 'confirmed_at']);
        });

        Schema::create('vatssa_availability_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('poll_id');
            $table->unsignedBigInteger('user_id');

            // Slot start times, ISO, UTC. See the note above on why this is a
            // list rather than a row each.
            $table->json('slots');

            // What this person is TO the poll -- 'student', 'examiner',
            // 'mentor', 'events'. The events team marks which slots are clear
            // rather than when they are free, and the grid renders those two
            // differently.
            $table->string('role', 20)->default('participant');

            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
            $table->foreign('poll_id')->references('id')->on('vatssa_availability_polls')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_availability_responses');
        Schema::dropIfExists('vatssa_availability_polls');
    }
};
