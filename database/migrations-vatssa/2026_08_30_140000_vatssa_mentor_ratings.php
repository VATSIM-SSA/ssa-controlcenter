<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a mentor's ceiling, and their per-rating limits.
 *
 * Capacity turned out to be two different questions wearing one number.
 *
 *   HOW MANY students may they run -- in total, and per rating
 *   HOW FAR up the ladder may they teach at all
 *
 * The second is the one that matters and was missing. Somebody cleared to
 * mentor S2 is not thereby cleared to mentor C1, and until the ATC training
 * manager says otherwise they should not appear as an option for one. A single
 * "limit" number could never express that.
 *
 * ## Everything here is the training manager's to set
 *
 * There is no mentor-editable maximum any more. A mentor asks -- through the
 * request desk, like everything else -- and the training manager decides. That
 * is the whole reason the capacity request type exists, and leaving a
 * self-service number beside it would have made the request pointless.
 *
 * ## The two limits interact, and the smaller wins
 *
 * A mentor with a total of 5 and an S2 limit of 4 can run four S2s and one of
 * something else, not four and four. `MentorCapacity::roomFor()` is the only
 * place that arithmetic lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vatssa_mentor_capacity', function (Blueprint $table) {
            // The highest rating they may mentor at all. Null means the ATC
            // training manager has not said, which is NOT the same as "any" --
            // see MentorCapacity::maxRating().
            $table->unsignedInteger('max_rating_id')->nullable()->after('rating_id');
            $table->foreign('max_rating_id')->references('id')->on('ratings')->nullOnDelete();
        });

        // The total, kept apart from the per-rating rows so it cannot be
        // confused with one. A row here is "across everything", not "for the
        // rating that happens to be null".
        Schema::create('vatssa_mentor_ceiling', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedSmallInteger('total_limit')->nullable();
            $table->unsignedInteger('max_rating_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('max_rating_id')->references('id')->on('ratings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_mentor_ceiling');

        Schema::table('vatssa_mentor_capacity', function (Blueprint $table) {
            $table->dropForeign(['max_rating_id']);
            $table->dropColumn('max_rating_id');
        });
    }
};
