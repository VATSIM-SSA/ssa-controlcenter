<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: mentor capacity, and the resources mentors need.
 *
 * These were going to be a separate site at mentors.vatssa.com, behind
 * Cloudflare Access, with its own VATSIM login, its own database and its own
 * copy of "who is a mentor" pulled from Control Center's API.
 *
 * That is three authentication layers and a second source of truth to keep a
 * student list and half a dozen links. Control Center already knows who mentors
 * whom, already has the login, and already has the page -- `/mentor` exists and
 * lists your students today. Two tables and a panel finish the job, and the
 * mentor portal repository stops needing to exist.
 *
 * ## Capacity
 *
 * Per mentor per rating, because a mentor who can run three S2s is not
 * necessarily willing to run three C1s. A row absent means the default from
 * `config('vatssa.default_mentor_capacity')`, so a division that does not care
 * about per-person limits never touches this table.
 *
 * Capacity is a LIMIT, not a rule: nothing enforces it. It exists so a
 * coordinator assigning a mentor can see who is full before they ask, and so a
 * mentor can say "I could take one more" without a Discord message. Enforcing
 * it would mean blocking an assignment somebody has already agreed to in
 * person, which is not a thing software should do.
 *
 * ## Resources
 *
 * A list of links. Sounds trivial, and is exactly the thing that lives in a
 * pinned Discord message nobody can find, gets a broken URL, and produces
 * "where is the syllabus again" once a fortnight forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_mentor_capacity', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // Null means "across all ratings" -- the simple case, and the one
            // most divisions want. A rating-specific row overrides it.
            $table->unsignedInteger('rating_id')->nullable();
            $table->unsignedSmallInteger('student_limit');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('rating_id')->references('id')->on('ratings')->cascadeOnDelete();
            $table->unique(['user_id', 'rating_id'], 'vatssa_capacity_unique');
        });

        Schema::create('vatssa_resources', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('url');
            $table->string('icon', 40)->default('fa-link');
            $table->string('description')->nullable();
            // Who the link is for. 'mentor' is the only audience today; the
            // column exists so an examiner or student list can be added without
            // a second table.
            $table->string('audience', 20)->default('mentor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['audience', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_resources');
        Schema::dropIfExists('vatssa_mentor_capacity');
    }
};
