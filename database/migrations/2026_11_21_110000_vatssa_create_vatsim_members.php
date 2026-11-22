<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: each member's VATSIM rating and division, including people with no CC account
// and whether they are on last night's division list. `users` stays the source of truth
// for CC accounts; see VatssaVatsimFacts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_vatsim_members', function (Blueprint $table) {
            $table->unsignedBigInteger('cid')->primary();
            $table->tinyInteger('rating')->nullable();
            $table->string('division', 10)->nullable();
            $table->boolean('in_org')->default(false); // on last night's division list
            $table->string('source', 10)->nullable(); // org, cc, lookup
            $table->boolean('needs_lookup')->default(true);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->index(['needs_lookup', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_vatsim_members');
    }
};
