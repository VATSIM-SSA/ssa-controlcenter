<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class ApiKey extends Model
{
    use HasFactory;

    public $table = 'api_keys';

    public $timestamps = false;

    public $fillable = [
        'id', 'token_hash', 'name', 'last_used_at', 'read_only', 'created_at', 'expires_at',
    ];

    protected $casts = [
        'read_only' => 'boolean',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * The token is never stored, so it is never returned either.
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * How a presented bearer token becomes a stored row.
     *
     * SHA-256 rather than bcrypt: these are 128-bit random tokens, not
     * passwords. There is no dictionary to slow down, and this runs on every
     * API request.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live key for a presented token, or null.
     *
     * Expiry is applied HERE rather than at the call site, so a future caller
     * cannot authenticate against an expired key by forgetting to check.
     */
    public static function forToken(?string $token): ?self
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        // The deploy window, handled rather than hoped about.
        //
        // deploy-cc.sh recreates the container BEFORE it runs migrations, so
        // for the length of the health check this code is talking to the old
        // schema -- where `token_hash` does not exist yet. Without this, every
        // API request carrying a key 500s for a minute or two on the deploy
        // that introduces hashing.
        //
        // So: try the hash, and if the column is not there yet, fall back to
        // the pre-migration lookup where the token WAS the id. The fallback
        // stops working the moment the migration lands, which is exactly when
        // it should.
        try {
            return static::query()
                ->where('token_hash', static::hashToken($token))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->first();
        } catch (QueryException $e) {
            if (! Schema::hasColumn('api_keys', 'token_hash')) {
                return static::query()->where('id', $token)->first();
            }

            throw $e;
        }
    }
}
