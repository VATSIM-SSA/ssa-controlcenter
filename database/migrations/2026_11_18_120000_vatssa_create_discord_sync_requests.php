<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: "Sync Discord roles" requests from a user's profile, read by the role sync bot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_discord_sync_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index(); // the member's CID (users.id)
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_sync_requests');
    }
};
