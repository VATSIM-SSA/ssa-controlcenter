<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: Discord role sync requests, from the profile button or queued automatically
// when CC data that decides a role changes. Read by the role sync bot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_discord_sync_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('source', 10)->default('button');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->string('status', 20)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_sync_requests');
    }
};
