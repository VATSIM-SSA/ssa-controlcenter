<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: Discord sync v2, phase 1. Replaces the v1 request log and presence list, which
// only ever held data the bot rebuilds on its next sweep.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('vatssa_discord_sync_requests');
        Schema::dropIfExists('vatssa_discord_presence');

        // One row per CID. A new change on a row being worked on raises `generation`,
        // so the bot's answer for the older state sends the row round again.
        Schema::create('vatssa_discord_queue', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('reason', 10);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->boolean('fresh')->default(false); // ask VATSIM again instead of using the cache
            $table->string('state', 10)->default('pending'); // pending, leased, idle
            $table->unsignedInteger('generation')->default(1);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('queued_at');
            $table->string('result', 20)->nullable();
            $table->string('detail', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['state', 'available_at']);
        });

        // user_id is set only for a CC user, and is unique: one Discord account per CID.
        Schema::create('vatssa_discord_members', function (Blueprint $table) {
            $table->string('discord_id', 21)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->boolean('vatsim_linked')->nullable(); // null: VATSIM has not answered yet
            $table->string('name', 100)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->boolean('in_server')->default(true)->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_seen_at');
        });

        Schema::create('vatssa_discord_member_history', function (Blueprint $table) {
            $table->id();
            $table->string('discord_id', 21)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event', 20);
            $table->string('detail', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_member_history');
        Schema::dropIfExists('vatssa_discord_members');
        Schema::dropIfExists('vatssa_discord_queue');
    }
};
