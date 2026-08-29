<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: where somebody is, on the platforms Control Center cannot see.
 *
 * Current state, one row per user -- not history. The bot writes it on the
 * daily sweep and the profile panel reads it.
 */
class UserPlatform extends Model
{
    protected $table = 'vatssa_user_platforms';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'discord_user_id', 'on_discord',
        'moodle_user_id', 'on_moodle', 'vatsim_member', 'checked_at',
    ];

    protected $casts = [
        'on_discord' => 'boolean',
        'on_moodle' => 'boolean',
        'vatsim_member' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this reading is old enough that it should not be read as fact.
     *
     * The sweep is daily. A panel showing a flat "not on Discord" from a check
     * that has not run for a week is worse than showing nothing at all.
     */
    public function isStale(): bool
    {
        return $this->checked_at === null || $this->checked_at->lt(now()->subDays(2));
    }
}
