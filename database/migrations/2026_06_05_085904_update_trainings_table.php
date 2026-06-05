<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // 1. Update the status column with the new comment mapping
            $table->tinyInteger('status')
                ->default(0)
                ->comment('-4: Closed by system, -3: Closed on student’s request, -2: Closed on TA request, -1: Completed, 0: In queue, 1: Pre-training, 2: Awaiting Mentor, 3: Active training, 4: Awaiting exam')
                ->change();

            // 2. Add the new nullable discord_thread_url column
            $table->string('discord_thread_url')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // 1. Revert the status column comment back to the original mapping
            $table->tinyInteger('status')
                ->default(0)
                ->comment('-4: Closed by system, -3: Closed on student’s request, -2: Closed on TA request, -1: Completed, 0: In queue, 1: Pre-training, 2: Active training, 3: Awaiting exam')
                ->change();

            // 2. Drop the newly added column
            $table->dropColumn('discord_thread_url');
        });
    }
};
