<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: the things a training manager should be able to change without a
 * developer.
 *
 * ## What was hard-coded, and why that is a problem
 *
 * **Training types** lived in `TrainingController::$types`, a static array of
 * five entries. Adding "Endorsement training" or "Familiarisation for a new
 * FIR" meant an edit, a review, a merge and a deploy -- for a label and an
 * icon. Worse, it is an UPSTREAM file, so every edit is a merge conflict on
 * every future release, for ever.
 *
 * **Request desks** lived in `RequestTarget::TIERS`, a class constant. The
 * desks a request can be sent to are a description of how VATSSA is organised
 * this year, and that changes more often than the code does.
 *
 * Ratings were already database rows -- `ratings`, with `vatsim_rating` NULL
 * meaning "endorsement rather than a VATSIM rating" -- but nothing in the
 * interface could add one. That gap is closed by the admin page, not by this
 * migration.
 *
 * ## The shape
 *
 * Both tables are seeded with exactly what the constants held, so the day this
 * runs nothing changes. `TrainingType::all()` and `RequestDesk::all()` fall
 * back to the constants when the table is empty or missing, so an unmigrated
 * database behaves the way it did yesterday rather than showing a member an
 * empty dropdown.
 *
 * `active` rather than deleting: a training type that is retired still has to
 * render on the trainings that used it, and a desk that is retired still has
 * to label the requests already sitting on it. Nothing here is ever really
 * deleted, only stopped from being offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vatssa_training_types')) {
            Schema::create('vatssa_training_types', function (Blueprint $table) {
                // Not auto-increment: the id IS the value stored on
                // trainings.type, and those rows already exist with 1-5.
                $table->unsignedInteger('id')->primary();
                $table->string('name', 60);
                $table->string('icon', 60)->default('fas fa-circle');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (DB::table('vatssa_training_types')->count() === 0) {
            DB::table('vatssa_training_types')->insert([
                ['id' => 1, 'name' => 'Standard', 'icon' => 'fas fa-circle', 'sort_order' => 1, 'active' => true, 'description' => 'The normal route to a rating.', 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'name' => 'Refresh', 'icon' => 'fas fa-sync', 'sort_order' => 2, 'active' => true, 'description' => 'Returning to a rating that has lapsed.', 'created_at' => now(), 'updated_at' => now()],
                ['id' => 3, 'name' => 'Transfer', 'icon' => 'fas fa-exchange-alt', 'sort_order' => 3, 'active' => true, 'description' => 'Arriving from another division with the rating already held.', 'created_at' => now(), 'updated_at' => now()],
                ['id' => 4, 'name' => 'Fast-track', 'icon' => 'fas fa-fast-forward', 'sort_order' => 4, 'active' => true, 'description' => 'Shortened for somebody with demonstrable experience.', 'created_at' => now(), 'updated_at' => now()],
                ['id' => 5, 'name' => 'Familiarisation', 'icon' => 'fas fa-compress-arrows-alt', 'sort_order' => 5, 'active' => true, 'description' => 'Getting to know a position or an FIR, without a rating at the end.', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (! Schema::hasTable('vatssa_request_desks')) {
            Schema::create('vatssa_request_desks', function (Blueprint $table) {
                $table->string('key', 40)->primary();
                $table->string('label', 80);
                $table->string('hint', 255)->nullable();
                // Whether the desk is staffed per rating. The coordinator desk
                // is; membership and leadership are not.
                $table->boolean('per_rating')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (DB::table('vatssa_request_desks')->count() === 0) {
            DB::table('vatssa_request_desks')->insert([
                ['key' => 'coordinator', 'label' => 'Pipeline coordinator', 'hint' => 'The coordinator for this rating. Start here.', 'per_rating' => true, 'sort_order' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'membership', 'label' => 'Membership', 'hint' => 'Rating updates, transfers, visiting controllers, anything about somebody\'s standing.', 'per_rating' => false, 'sort_order' => 2, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'training-manager', 'label' => 'ATC training manager', 'hint' => 'Training policy, examiner matters, anything a coordinator cannot decide.', 'per_rating' => false, 'sort_order' => 3, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'leadership', 'label' => 'Division leadership', 'hint' => 'VATSSA1 and VATSSA2. Division-level decisions, and rarely the right first stop.', 'per_rating' => false, 'sort_order' => 4, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_training_types');
        Schema::dropIfExists('vatssa_request_desks');
    }
};
