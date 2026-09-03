<?php

use App\Helpers\FeedbackStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff can action feedback: read it, say what it was, and decide whether the
 * controller sees it.
 *
 * Closes #1467. The feedback report is append-only today, so a division that
 * receives feedback steadily ends up scrolling past everything it has already
 * dealt with to find the one thing it has not.
 *
 * ## The `forwarded` boolean is replaced, not kept beside the status
 *
 * `forwarded` has existed since the feedback table was added in 2023. Nothing
 * has ever read it or written it: it is in `$fillable`, the factory sets it
 * false, and that is the whole of its life in the codebase.
 *
 * Leaving it next to a status whose values include `forwarded` would be two
 * sources of truth for one fact, and the kind that stays consistent right up
 * until somebody writes only one of them. It is backfilled into the status
 * first, so a division that HAS been setting it out of band keeps its data, and
 * `down()` restores both the column and the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->string('status', 20)->default(FeedbackStatus::OPEN->value)->after('feedback');
            $table->string('sentiment', 20)->nullable()->after('status');

            // The staff note. Deliberately NOT on the feedback text itself: the
            // submission is a record of what somebody said and must not be
            // edited to make it more palatable, so context goes beside it.
            $table->text('staff_note')->nullable()->after('sentiment');

            $table->unsignedBigInteger('actioned_by_id')->nullable()->after('reference_position_id');
            $table->timestamp('actioned_at')->nullable()->after('actioned_by_id');

            // The default queue is "open", so this index is the one every load
            // of the report uses.
            $table->index(['status', 'created_at']);

            // nullOnDelete, not cascade: losing the staff member who actioned
            // something must not lose the fact that it was actioned.
            $table->foreign('actioned_by_id')->references('id')->on('users')->nullOnDelete();
        });

        // Anything already flagged forwarded keeps that meaning. In a stock
        // installation this matches nothing, because nothing ever set it.
        DB::table('feedback')
            ->where('forwarded', true)
            ->update(['status' => FeedbackStatus::FORWARDED->value]);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('forwarded');
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->boolean('forwarded')->default(false)->after('feedback');
        });

        DB::table('feedback')
            ->where('status', FeedbackStatus::FORWARDED->value)
            ->update(['forwarded' => true]);

        Schema::table('feedback', function (Blueprint $table) {
            $table->dropForeign(['actioned_by_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'sentiment', 'staff_note', 'actioned_by_id', 'actioned_at']);
        });
    }
};
