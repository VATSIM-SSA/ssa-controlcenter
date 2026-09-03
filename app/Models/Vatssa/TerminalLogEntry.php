<?php

namespace App\Models\Vatssa;

use App\Helpers\Vatssa\TerminalLogReason;
use App\Helpers\Vatssa\TerminalLogType;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one thing somebody did on VATSIM Terminal.
 *
 * ## Nothing is written silently
 *
 * Automatic logging is allowed and a button still has to be pressed. Control
 * Center cannot see Terminal, so it cannot know an action happened there --
 * writing a row on the strength of a Control Center action would be recording a
 * belief, not an event. A rating upgrade completed here OFFERS a pre-filled
 * row; a person confirms it.
 *
 * ## The actor and the recorder are different people
 *
 * `actor` is who did it on Terminal, and it is editable, because half of these
 * rows are entered afterwards about somebody else's action. `recorded_by` is
 * who typed the row, is never editable, and is the answer to "who says this
 * happened". Collapsing the two would make every backfilled row claim the
 * wrong person did the work.
 */
class TerminalLogEntry extends Model
{
    protected $table = 'vatssa_terminal_log';

    protected $fillable = [
        'type', 'reason', 'user_id', 'actor_user_id', 'actor_name', 'recorded_by',
        'membership_request_id', 'comment_code', 'rating_from_id', 'rating_to_id',
        'discipline_found', 'discipline_context', 'notes', 'performed_at',
    ];

    protected $casts = [
        'type' => TerminalLogType::class,
        'reason' => TerminalLogReason::class,
        'discipline_found' => 'boolean',
        'performed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A row that names nobody as having done it is a row that says the
        // action happened by itself. Enforced at the model rather than only in
        // the form, because the seeder and the bridge both write these too.
        static::saving(function (self $entry) {
            if ($entry->actor_user_id === null && trim((string) $entry->actor_name) === '') {
                throw new \InvalidArgumentException(
                    'A Terminal log entry must say who did it: a Control Center account, or a name.'
                );
            }

            // `performed_at` is NOT NULL, and "now" is the right answer for
            // anything not being backfilled. Defaulting it here rather than at
            // each caller: the form, the seeder and the bridge all write these,
            // and a caller who forgets currently gets a constraint violation
            // rather than the obvious behaviour.
            $entry->performed_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who did it on Terminal, when that person has a Control Center account. */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** Who typed the row. Never the same question as who did it. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function membershipRequest(): BelongsTo
    {
        return $this->belongsTo(MembershipRequest::class);
    }

    public function ratingFrom(): BelongsTo
    {
        return $this->belongsTo(Rating::class, 'rating_from_id');
    }

    public function ratingTo(): BelongsTo
    {
        return $this->belongsTo(Rating::class, 'rating_to_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(TerminalComment::class, 'comment_code', 'code');
    }

    /**
     * Who did it, however that was recorded.
     *
     * The account's name when there is one, the typed name when there is not.
     * One accessor so no view has to know there are two columns.
     */
    public function actorLabel(): string
    {
        return $this->actor?->name ?? (string) $this->actor_name;
    }

    /** Whether this row was a disciplinary check at all. */
    public function isDisciplinaryCheck(): bool
    {
        return $this->discipline_found !== null;
    }

    public function scopeOfType(Builder $query, TerminalLogType $type): void
    {
        $query->where('type', $type);
    }

    public function scopeAbout(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }
}
