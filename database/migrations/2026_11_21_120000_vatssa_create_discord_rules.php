<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: Discord rules. CC decides who has which Discord role; the bot applies it.
// A rule is a name, conditions that must all hold, and the Discord roles it gives.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vatssa_discord_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('vatssa_discord_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('vatssa_discord_rules')->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('params');
            $table->boolean('negated')->default(false);
        });

        Schema::create('vatssa_discord_rule_roles', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained('vatssa_discord_rules')->cascadeOnDelete();
            $table->string('discord_role_id', 21);
            $table->primary(['rule_id', 'discord_role_id']);
        });

        // No foreign key to the rule: the audit outlives a deleted rule.
        Schema::create('vatssa_discord_rule_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->index();
            $table->string('rule_name', 80);
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->unsignedBigInteger('by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // The server's roles as the bot last saw them. `retired`: in no rule, and staff chose
        // to take it off everyone, so the bot keeps managing it until nobody has it.
        Schema::create('vatssa_discord_guild_roles', function (Blueprint $table) {
            $table->string('discord_role_id', 21)->primary();
            $table->string('name', 100)->nullable();
            $table->unsignedInteger('colour')->default(0);
            $table->integer('position')->default(0);
            $table->boolean('assignable')->default(false);
            $table->boolean('retired')->default(false);
            $table->timestamp('seen_at')->nullable();
        });

        Schema::table('vatssa_discord_members', function (Blueprint $table) {
            $table->json('role_ids')->nullable(); // as the bot last saw them, for the rule preview
            $table->boolean('needs_sync')->default(false); // joined with no CID, so no queue row
        });
    }

    public function down(): void
    {
        Schema::table('vatssa_discord_members', fn (Blueprint $table) => $table->dropColumn(['role_ids', 'needs_sync']));
        foreach (['guild_roles', 'rule_audit', 'rule_roles', 'rule_conditions', 'rules'] as $name) {
            Schema::dropIfExists("vatssa_discord_{$name}");
        }
    }
};
