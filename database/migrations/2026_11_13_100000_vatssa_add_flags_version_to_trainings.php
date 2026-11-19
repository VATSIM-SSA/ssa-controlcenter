<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a counter bumped on every change to a training's flags (Requires
 * attention, Stalled), under the row lock. Flag forms carry it so a change
 * from an older page is refused. See App\Services\VatssaTrainingFlags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->unsignedInteger('flags_version')->default(0)->after('attention_at');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('flags_version');
        });
    }
};
