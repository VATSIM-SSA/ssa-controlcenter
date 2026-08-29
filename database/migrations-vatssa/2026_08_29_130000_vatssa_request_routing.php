<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: requests go to a DESK, not to a name somebody typed.
 *
 * Upstream's request form asks who to send it to, offers a datalist of every
 * user holding any role, and three "most frequently used" shortcuts. That works
 * when everybody knows the org chart. In practice a request lands on whoever
 * came to mind, and sits unread.
 *
 * So the requester picks one of four desks instead:
 *
 *   coordinator       the pipeline coordinator FOR THAT RATING
 *   training-manager  the ATC training manager
 *   vatssa1           the Division Director
 *   vatssa2           the Deputy Division Director
 *
 * and this table says who each desk currently is. Several people per desk is
 * normal and supported -- a rating can have two coordinators, and the request
 * goes to whichever of them has the fewest open tasks.
 *
 * ## Why a table rather than the existing roles
 *
 * Control Center scopes roles by AREA. VATSSA's pipelines are per RATING, which
 * is a different axis entirely: the S2 coordinator is not "the coordinator for
 * Southern Africa". Reusing `role_user` would have meant bending area to mean
 * rating, and every area-scoped permission check in upstream would then be
 * quietly wrong.
 *
 * Holding the `pipeline-coordinator` ROLE is still what grants the permissions.
 * This says who receives the work, which is a separate question with a separate
 * answer -- an ATM holds every coordinator permission and is nobody's default
 * coordinator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_request_targets', function (Blueprint $table) {
            $table->id();
            $table->string('tier', 20);
            // Only the coordinator desk is per-rating. Null on every other tier,
            // which is what makes one table serve all four.
            $table->unsignedInteger('rating_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('rating_id')->references('id')->on('ratings')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tier', 'rating_id', 'user_id'], 'vatssa_target_unique');
            $table->index(['tier', 'rating_id']);
        });

        // The desk a task was sent to, kept ON the task.
        //
        // Without this the tier is lost the moment it is resolved to a person,
        // and a request sitting in somebody's inbox cannot say what it was
        // addressed to. It is what lets the whole desk see its own queue rather
        // than only the one person the round-robin happened to pick.
        //
        // Nullable, because every task upstream creates has no tier, and both
        // columns are vatssa_-prefixed so upstream can never collide with them.
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('vatssa_tier', 20)->nullable()->after('type');
            $table->unsignedInteger('vatssa_rating_id')->nullable()->after('vatssa_tier');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['vatssa_tier', 'vatssa_rating_id']);
        });

        Schema::dropIfExists('vatssa_request_targets');
    }
};
