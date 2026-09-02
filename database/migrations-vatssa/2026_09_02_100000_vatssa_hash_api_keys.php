<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * VATSSA: API keys stop being stored in the clear.
 *
 * ## What was wrong
 *
 * `ApiToken` middleware did `ApiKey::find($request->bearerToken())` -- the
 * token WAS the primary key, stored exactly as it was issued. So read access
 * to one table was read access to every integration credential in usable form,
 * and anybody who could see a database dump, a backup, or the output of a
 * careless query had them all.
 *
 * The keys are not trivial to abuse blind, but `/api/users?include[]=email`
 * returns every member's name and email address to any key holder, read-only
 * included. That is the whole membership, from one leaked string.
 *
 * ## What this does
 *
 * Adds `token_hash` and `expires_at`, backfills the hash from the token that
 * is already there, and then REPLACES the stored `id` with a fresh random
 * value that is not a credential.
 *
 * **Existing keys keep working.** Authentication now looks the presented token
 * up by its SHA-256, which still matches; what changes is that the clear text
 * is no longer anywhere in the database. Nobody has to be told to rotate
 * anything on the day this runs, which is the difference between a migration
 * that gets applied and one that waits for a maintenance window.
 *
 * Rotation is still worth doing afterwards, because the old value has been at
 * rest in the clear for as long as the key has existed. That is a decision for
 * whoever holds the integrations, not something a migration should force.
 *
 * ## Why SHA-256 and not bcrypt
 *
 * These are 128-bit random tokens, not passwords. There is no dictionary to
 * attack, so the slow hash buys nothing -- and it would be run on every single
 * API request. A fast hash over a high-entropy secret is the right shape here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_keys')) {
            return;
        }

        if (! Schema::hasColumn('api_keys', 'token_hash')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->string('token_hash', 64)->nullable()->after('id');
                $table->timestamp('expires_at')->nullable()->after('last_used_at');
            });
        }

        // Backfill, then blind the id. Row by row, because each hash is
        // derived from that row's own token.
        foreach (DB::table('api_keys')->whereNull('token_hash')->get() as $key) {
            DB::table('api_keys')
                ->where('id', $key->id)
                ->update([
                    'token_hash' => hash('sha256', (string) $key->id),
                    // A new id that is an identifier rather than a secret.
                    'id' => (string) Str::uuid(),
                ]);
        }

        // Unique only after the backfill: adding it first would fail on the
        // nulls this migration exists to fill.
        if (Schema::hasColumn('api_keys', 'token_hash')) {
            try {
                Schema::table('api_keys', function (Blueprint $table) {
                    $table->unique('token_hash');
                });
            } catch (Throwable $e) {
                // Already indexed, or a driver that will not add it twice.
                // Not worth failing a deploy over an index that exists.
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reversible. The clear text this replaced is gone,
        // and inventing it back is neither possible nor desirable.
    }
};
