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
            $table->unsignedBigInteger('user_id')->index(); // the member's CID (users.id)
            $table->unsignedBigInteger('requested_by')->nullable(); // null = queued automatically
            $table->string('source', 10)->default('button'); // button | auto
            $table->timestamp('created_at')->useCurrent()->index();
            $table->string('status', 20)->nullable(); // set by the bot; null = not handled yet
            $table->string('detail', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_sync_requests');
    }
};
