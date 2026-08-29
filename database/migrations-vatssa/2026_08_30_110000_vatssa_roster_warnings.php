<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a record that somebody was told their roster place is about to lapse.
 *
 * Two jobs, and the second is the one worth having.
 *
 * It stops the daily run sending the same warning seven times. And it lets the
 * PROFILE show that the warning went out and when -- so when a controller says
 * nobody told them, there is an answer that is not somebody's memory.
 *
 * Cleared when they become active again, so the next cycle warns afresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_roster_warnings', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('warned_at');
            $table->timestamp('expires_on');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_roster_warnings');
    }
};
