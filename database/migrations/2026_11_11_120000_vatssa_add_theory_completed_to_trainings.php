<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: staff record that a student has passed the theory. Set by hand from
// the training page until the Moodle link exists; nothing else reads it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->boolean('theory_completed')->default(false)->after('pre_training_completed');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('theory_completed');
        });
    }
};
