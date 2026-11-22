<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: small values the Discord sync shares between processes. Not the settings table:
// Setting::save() deletes keys created after that process loaded its settings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_discord_state', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->text('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_discord_state');
    }
};
