<?php

use App\Helpers\Vatssa\MembershipRequestState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: the membership module's own table.
 *
 * ## Why not a Training with a new type
 *
 * A membership request shares a LOOK with a training -- a queue, states, a
 * detail page with boxes, internal comments, an activity feed -- and none of
 * the substance. No mentor, no rating pipeline, no theory, no sessions, no
 * reports. Putting one in `trainings` would mean a row where most columns mean
 * nothing, and every training query in the application would have to remember
 * to exclude it. Borrow the patterns, not the table.
 *
 * ## One table, two state machines
 *
 * Five types. Visiting and transfer run the full seven-state workflow; rating
 * upgrade, staff inquiry and other run open -> complete. The alternative was
 * four unreachable states on three of the types, and a state nobody can reach
 * is one somebody eventually sets by accident. See decisions/log.md.
 *
 * ## The disciplinary check is three columns, not one
 *
 * A tick is enough when the record is clean. When it is not, the context is
 * required -- "we looked and there was something" with no note is worse than
 * not having looked. `checked_at` separates BOTH of those from "nobody has
 * looked yet", which is the state every request starts in and the one the
 * visiting endorsement is gated on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_membership_requests', function (Blueprint $table) {
            $table->id();

            $table->string('type', 20);
            $table->string('state', 30)->default(MembershipRequestState::OPEN->value);

            // Who it is ABOUT. Not who filed it: a rating upgrade is raised by
            // the system from a training, and a staff inquiry by leadership.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by')->nullable();

            // The rating in question, for an upgrade. Null on everything else.
            $table->unsignedInteger('rating_id')->nullable();

            // The familiarisation training this opened, or the training an
            // upgrade came from. Nullable and nullOnDelete: losing a training
            // must not lose the membership record of what was decided.
            $table->unsignedBigInteger('training_id')->nullable();

            // The TVCP snapshot at submission -- what was true WHEN THEY ASKED,
            // so a decision six weeks later can be read in the context it was
            // made in. Visiting and transfer only.
            $table->json('checks')->nullable();

            $table->boolean('disciplinary_clean')->nullable();
            $table->text('disciplinary_context')->nullable();
            $table->timestamp('disciplinary_checked_at')->nullable();
            $table->unsignedBigInteger('disciplinary_checked_by')->nullable();

            // A decline is one of the three TVCP 5.4 grounds and nothing else.
            // There is no free-text no: 5.5 requires the reason in writing and
            // in the member's record, and a reason somebody typed is not one of
            // the three grounds the policy allows.
            $table->string('decline_ground', 40)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->text('note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // The desk queue reads state first, then date. Every load of the
            // open list uses this.
            $table->index(['state', 'created_at']);
            $table->index(['user_id', 'type']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rating_id')->references('id')->on('ratings')->nullOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->nullOnDelete();
            $table->foreign('disciplinary_checked_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_membership_requests');
    }
};
