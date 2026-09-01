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
        'user_id', 'discord_user_id', 'on_discord', 'discord_joined_at',
        'moodle_user_id', 'on_moodle', 'moodle_registered_at',
        'moodle_enrolment', 'moodle_course',
        'vatsim_member', 'checked_at',
    ];

    protected $casts = [
        'on_discord' => 'boolean',
        'on_moodle' => 'boolean',
        'vatsim_member' => 'boolean',
        'checked_at' => 'datetime',
        // Nullable for ever on rows written before the sweep started asking
        // for them. The views show "?" rather than blank -- see
        // vatssa/parts/platform-lines.blade.php.
        'discord_joined_at' => 'datetime',
        'moodle_registered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether they are enrolled in a theory course right now.
     *
     * Distinct from `on_moodle`, which only says they have an account. A
     * student with an account and no enrolment is the commonest stall in the
     * whole pipeline and the hardest to see: two green ticks, nothing
     * obviously wrong, and nothing happening.
     */
    public function isEnrolled(): bool
    {
        return $this->moodle_enrolment === 'active';
    }

    public function enrolmentLabel(): string
    {
        return match ($this->moodle_enrolment) {
            'active' => $this->moodle_course ? $this->moodle_course . ' theory' : 'Enrolled',
            'suspended' => 'Enrolment suspended',
            default => 'Not enrolled in a course',
        };
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
