<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: groups. A named list of people kept by VATSIM ID, so members need no CC
// account yet. Ported from the EuroScope plugin package (vatssa-plugin, 10 Nov 2026),
// group part only. Members and managers are never deleted, only marked removed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vatssa_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('vatssa_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vatssa_group_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('vatssa_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->boolean('can_manage_settings')->default(false);
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vatssa_group_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('vatssa_groups')->cascadeOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('detail', 255)->nullable();
            $table->unsignedBigInteger('by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vatssa_group_events');
        Schema::dropIfExists('vatssa_group_managers');
        Schema::dropIfExists('vatssa_group_members');
        Schema::dropIfExists('vatssa_groups');
    }
};
