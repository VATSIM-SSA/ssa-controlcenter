<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: who can open a poll becomes a choice, not a rule in code.
 *
 * ## The bug this fixes
 *
 * `AvailabilityController::store()` finished with "Ask away. Send people the
 * link on this page", and `AvailabilityPoll::isVisibleTo()` then returned 403
 * to anybody who was not the creator, the student it was about, an existing
 * respondent, or staff working the queue.
 *
 * So the link did not work for the people it was for. The tool was usable only
 * for polls attached to a training, which is the one case the exam workflow
 * created automatically -- and that workflow is now gone.
 *
 * ## The three modes
 *
 * `invited` (the default, and what every existing poll becomes) -- a response
 * row is the invitation. One name or twenty; the difference between "only this
 * person" and "only these few people" is how many you invite, not a separate
 * setting.
 *
 * `link` -- anybody signed in who has the URL. Deliberately still behind
 * authentication: an unauthenticated poll would be a new public route
 * publishing when named members are at home, which is a bigger decision than a
 * convenience setting should make quietly.
 *
 * Existing rows default to `invited`, which is exactly what they behave like
 * today, so this migration changes nothing about any poll that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vatssa_availability_polls')) {
            return;
        }

        if (Schema::hasColumn('vatssa_availability_polls', 'visibility')) {
            return;
        }

        Schema::table('vatssa_availability_polls', function (Blueprint $table) {
            $table->string('visibility', 20)->default('invited')->after('purpose');
        });

        DB::table('vatssa_availability_polls')->update(['visibility' => 'invited']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('vatssa_availability_polls', 'visibility')) {
            Schema::table('vatssa_availability_polls', function (Blueprint $table) {
                $table->dropColumn('visibility');
            });
        }
    }
};
