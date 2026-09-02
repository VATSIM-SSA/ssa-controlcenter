<?php

use App\Models\Vatssa\RequestTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: every task sits on a desk, and the column says so.
 *
 * `vatssa_tier` was nullable because upstream creates tasks that know nothing
 * about desks. The cost was invisible work: `RequestTarget::scopeToDesks()`
 * matches on the tier, so a null-tier row appears on NO desk queue at all. It
 * is visible only to the single person `assignee_user_id` names -- which is the
 * per-person ownership the desk model exists to replace, arriving through the
 * back door on every task the fork's own form did not create.
 *
 * Backfill first, then NOT NULL. The observer fills the tier in on `creating`,
 * so nothing new can arrive without one; this closes the path that skips the
 * observer entirely -- a raw insert, a seeder, a bulk update.
 *
 * The backfill mirrors the observer's own rule so old rows and new ones land on
 * the same desk: coordinator where the task has a rating to route on, ATC
 * training manager where it has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->whereNull('vatssa_tier')
            ->whereNotNull('vatssa_rating_id')
            ->update(['vatssa_tier' => RequestTarget::COORDINATOR]);

        DB::table('tasks')
            ->whereNull('vatssa_tier')
            ->update(['vatssa_tier' => RequestTarget::TRAINING_MANAGER]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('vatssa_tier', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('vatssa_tier', 20)->nullable()->change();
        });
    }
};
