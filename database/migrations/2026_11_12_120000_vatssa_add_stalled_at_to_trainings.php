<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: staff flag a training as stalled. Null = not set; otherwise when it
 * was set. See App\Services\VatssaTrainingFlags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->timestamp('stalled_at')->nullable()->after('theory_completed');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('stalled_at');
        });
    }
};
