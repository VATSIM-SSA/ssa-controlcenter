<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// VATSSA: the CID VATSIM gave for a Discord account, including people with no CC account.
// user_id stays the link to a CC user. Not unique: two accounts can briefly claim one CID.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vatssa_discord_members', function (Blueprint $table) {
            $table->unsignedBigInteger('vatsim_cid')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('vatssa_discord_members', function (Blueprint $table) {
            $table->dropIndex(['vatsim_cid']);
            $table->dropColumn('vatsim_cid');
        });
    }
};
