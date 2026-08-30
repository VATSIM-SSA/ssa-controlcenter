<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: people who do not have to be on Discord, or on Moodle.
 *
 * Training now requires both before somebody can apply. That is the right rule
 * for almost everybody -- a student the division cannot reach is a student the
 * division cannot train, and every stall in the old pipeline started with
 * somebody who was never on Discord in the first place.
 *
 * It is the wrong rule for a few. Someone whose country blocks Discord, a
 * transferring controller mid-move, an account genuinely stuck in support. A
 * requirement with no exit turns those into "no training, ever", and the way
 * that gets solved in practice is somebody quietly disabling the rule for
 * everybody.
 *
 * ## Not a boolean on the user
 *
 * WHO granted it and WHY are the parts that make an exemption reviewable.
 * A flag with no reason is indistinguishable from a mistake six months later,
 * and nobody dares remove it.
 *
 * ATC training manager and admin only; see `config/roles.php`
 * `training.platform-requirement.override`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_platform_exemptions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();

            // Separate, because they are separate problems. Discord is usually
            // "cannot", Moodle is usually "not yet".
            $table->boolean('discord')->default(false);
            $table->boolean('moodle')->default(false);

            $table->string('reason', 255);

            // nullOnDelete: the exemption outlives the manager who granted it.
            // Losing the reason because somebody left is how an exemption
            // becomes unreviewable.
            $table->unsignedBigInteger('granted_by')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_platform_exemptions');
    }
};
