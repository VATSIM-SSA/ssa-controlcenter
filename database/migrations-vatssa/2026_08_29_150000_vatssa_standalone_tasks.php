<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a request does not have to be about a training.
 *
 * Upstream's `tasks` table requires both a subject user and a subject training,
 * because upstream only ever creates a task from a training page. That makes
 * whole categories of work unrecordable:
 *
 *   "review the S2 syllabus"                 about nobody
 *   "this member wants to visit"             about a person, no training
 *   "check why the mentor index is stale"    about neither
 *
 * The result is that anything not tied to a training happens in Discord, which
 * is exactly what the request system exists to replace. Both columns are
 * nullable now, and the task screen can raise one against a desk directly.
 *
 * The foreign keys stay. A task CAN point at a training; it simply need not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('subject_user_id')->nullable()->change();
            $table->unsignedBigInteger('subject_training_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately not reversed. Going back to NOT NULL would fail on any
        // standalone task that exists by then, and a migration that cannot roll
        // back cleanly should say so rather than throwing halfway.
    }
};
