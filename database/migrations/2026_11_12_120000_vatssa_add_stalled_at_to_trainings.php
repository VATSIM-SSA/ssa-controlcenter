<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: staff flag a training as stalled. Null = not stalled; otherwise when
// it was flagged. Set and cleared by hand from the training page.
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
