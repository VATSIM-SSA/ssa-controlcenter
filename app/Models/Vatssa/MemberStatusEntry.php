<?php

namespace App\Models\Vatssa;

use App\Helpers\Vatssa\DivisionalRelationship;
use App\Helpers\Vatssa\StatusAxis;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one change in what a member was to the division.
 *
 * A row is written only when the derived answer differs from the row before it,
 * so the table reads as a list of transitions rather than a sample every time
 * the sync ran.
 *
 * @see database/migrations-vatssa/2026_09_05_100000_vatssa_member_status_history.php
 */
class MemberStatusEntry extends Model
{
    protected $table = 'vatssa_member_status_history';

    /** The roster values. Strings, because the axis column decides the meaning. */
    public const ROSTER_APPROVED = 'approved';

    public const ROSTER_NOT_APPROVED = 'not-approved';

    protected $fillable = ['user_id', 'axis', 'value', 'effective_from', 'note'];

    protected $casts = [
        'axis' => StatusAxis::class,
        'effective_from' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForAxis(Builder $query, StatusAxis $axis): void
    {
        $query->where('axis', $axis);
    }

    public function scopeAbout(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * The relationship this row records, when it is a relationship row.
     *
     * Null on a roster row rather than a throw: the history view renders both
     * kinds from one list, and asking it to know which is which before it can
     * read a value would push the axis check into every template.
     */
    public function relationship(): ?DivisionalRelationship
    {
        return $this->axis === StatusAxis::RELATIONSHIP
            ? DivisionalRelationship::tryFrom($this->value)
            : null;
    }

    /** Whether a roster row means they were on it. */
    public function isOnRoster(): bool
    {
        return $this->value === self::ROSTER_APPROVED;
    }

    /**
     * What to show for this row, whichever axis it is on.
     *
     * One accessor so the history template does not branch on the axis to work
     * out how to print a value.
     */
    public function label(): string
    {
        return $this->relationship()?->label()
            ?? ($this->isOnRoster() ? 'Approved controller' : 'Off the roster');
    }

    public function color(): string
    {
        return $this->relationship()?->color()
            ?? ($this->isOnRoster() ? 'success' : 'secondary');
    }
}
