<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: that a controller was warned their roster place is about to lapse.
 *
 * Kept so the daily run does not warn seven times, and so the profile can say
 * the warning went out and when. When somebody says nobody told them, that is
 * an answer rather than a recollection.
 */
class RosterWarning extends Model
{
    protected $table = 'vatssa_roster_warnings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'warned_at', 'expires_on'];

    protected $casts = [
        'warned_at' => 'datetime',
        'expires_on' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this person has already been warned for the current cycle.
     *
     * A warning older than the date it was warning about belongs to a previous
     * cycle -- they recovered and are lapsing again -- and should not silence
     * this one.
     */
    public static function alreadyWarned(int $userId): bool
    {
        $warning = static::find($userId);

        return $warning !== null && $warning->expires_on->isFuture();
    }

    public static function record(int $userId, $expiresOn): void
    {
        static::updateOrCreate(['user_id' => $userId], [
            'warned_at' => now(),
            'expires_on' => $expiresOn,
        ]);
    }
}
