<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: which Discord accounts in the VATSSA server are linked to which CID.
// Written only by the role sync bot after each sweep; shown on the user profile.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_discord_presence', function (Blueprint $table) {
            $table->string('discord_id', 21)->primary(); // a snowflake: kept as text, never a float
            $table->unsignedBigInteger('user_id')->index(); // CID
            $table->string('name', 100)->nullable(); // Discord username
            $table->timestamp('seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_presence');
    }
};