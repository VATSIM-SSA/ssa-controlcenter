<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: whether somebody is actually enrolled in a theory course.
 *
 * `on_moodle` only says they have an account. That is not the same question,
 * and conflating the two hides the most common stall in the whole pipeline:
 * a student who registered on Moodle, was never enrolled in a course, and is
 * sitting there waiting for something to happen. Their platform panel shows two
 * green ticks and nothing is wrong as far as anybody can see.
 *
 * Three states, and the difference between the last two matters:
 *
 *   null        not enrolled in anything -- the stall above
 *   'active'    enrolled and able to work
 *   'suspended' enrolled once, suspended since. The attempts survive, which is
 *               why the pipeline suspends rather than unenrols: a returning
 *               student keeps every past result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vatssa_user_platforms', function (Blueprint $table) {
            $table->string('moodle_enrolment', 20)->nullable()->after('on_moodle');
            // Which course, so the panel can say "S2 theory" rather than "yes".
            $table->string('moodle_course', 20)->nullable()->after('moodle_enrolment');
        });
    }

    public function down(): void
    {
        Schema::table('vatssa_user_platforms', function (Blueprint $table) {
            $table->dropColumn(['moodle_enrolment', 'moodle_course']);
        });
    }
};
