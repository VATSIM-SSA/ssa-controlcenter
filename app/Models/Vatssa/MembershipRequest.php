<?php

namespace App\Models\Vatssa;

use App\Helpers\Vatssa\MembershipRequestState;
use App\Helpers\Vatssa\MembershipRequestType;
use App\Models\Rating;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: a transfer, a visit, or a piece of Terminal work.
 *
 * Five types in one table. Two of them are filed by a person and run the full
 * seven-state workflow; three are Terminal work the desk records about somebody
 * and run open -> complete. See MembershipRequestType, MembershipRequestState
 * and decisions/log.md, 2026-09-03.
 *
 * ## The disciplinary check is the gate on everything
 *
 * TVCP 5.4 allows exactly three grounds for refusing a transfer or a visit, and
 * one of them is a disciplinary history in the last twelve months. That check
 * happens on VATSIM Terminal, by a person, and Control Center cannot see it --
 * so the record of it is what this table holds, and the visiting endorsement is
 * gated on it rather than on who is pressing the button.
 *
 * ## What this model will NOT do
 *
 * It does not decide. `approve()` and `decline()` are deliberately absent for
 * now: approval is a policy act with an obligation attached (5.5 requires a
 * written reason in the member's record on a decline) and it belongs with the
 * controller that can also write that record. The model holds the shape.
 */
class MembershipRequest extends Model
{
    protected $table = 'vatssa_membership_requests';

    protected $fillable = [
        'type', 'state', 'user_id', 'created_by', 'rating_id', 'training_id',
        'checks', 'note',
    ];

    /**
     * The disciplinary and decision fields are NOT fillable, on purpose.
     *
     * Each is a set that only moves together -- a verdict, a person and a time
     * -- and a request that says it was checked by nobody is worse than one
     * that says it was never checked. `recordDisciplinaryCheck()` is the one
     * place they are written.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'type' => MembershipRequestType::class,
        'state' => MembershipRequestState::class,
        'checks' => 'array',
        'disciplinary_clean' => 'boolean',
        'disciplinary_checked_at' => 'datetime',
        'decided_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function disciplinaryCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disciplinary_checked_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Open a request, in the state its type starts in.
     *
     * A factory method rather than a plain create(), because the starting state
     * is a fact about the TYPE and getting it from the caller is how a transfer
     * ends up starting at `open` and skipping its disciplinary check.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function open(
        MembershipRequestType $type,
        User $about,
        ?User $filedBy = null,
        array $attributes = [],
    ): self {
        return static::create([
            'type' => $type,
            'state' => $type->initialState(),
            'user_id' => $about->id,
            'created_by' => $filedBy?->id,
            // Only where it means something. An empty snapshot on a rating
            // upgrade would make the column mean two different things
            // depending on the row.
            'checks' => $type->carriesTvcpChecks() ? ($attributes['checks'] ?? null) : null,
        ] + $attributes);
    }

    /**
     * Record the Terminal disciplinary check.
     *
     * A tick is enough when the record is clean. When it is not, the context is
     * REQUIRED -- "we looked and there was something" with no note is worse
     * than not having looked, because the next person cannot act on it and
     * cannot tell whether anybody did.
     *
     * @throws \InvalidArgumentException when a finding has no context
     */
    public function recordDisciplinaryCheck(bool $clean, User $checkedBy, ?string $context = null): void
    {
        if (! $clean && trim((string) $context) === '') {
            throw new \InvalidArgumentException(
                'A disciplinary finding needs context. Record what was found, not only that something was.'
            );
        }

        $this->disciplinary_clean = $clean;
        // Cleared on a clean check rather than left behind: a stale note from a
        // previous finding sitting under a green tick is the worst of both.
        $this->disciplinary_context = $clean ? null : $context;
        $this->disciplinary_checked_at = now();
        $this->disciplinary_checked_by = $checkedBy->id;

        $this->save();
    }

    /**
     * Whether the Terminal check has been done at all.
     *
     * Distinct from `disciplinary_clean`, and the difference is the point: null
     * means nobody has looked, which is not the same as looked-and-clean. The
     * visiting endorsement is gated on this, so conflating them would issue
     * endorsements on unread records.
     */
    public function disciplinaryChecked(): bool
    {
        return $this->disciplinary_checked_at !== null;
    }

    /**
     * Whether a visiting endorsement may be assigned off the back of this.
     *
     * Three conditions, and each rules out a real mistake:
     *   - it is a VISITING request, because a familiarisation training can be
     *     run without anybody visiting;
     *   - the check has been done;
     *   - and it came back clean.
     */
    public function mayAssignVisitingEndorsement(): bool
    {
        return $this->type === MembershipRequestType::VISITING
            && $this->disciplinaryChecked()
            && $this->disciplinary_clean === true;
    }

    /**
     * Move a request to a new state.
     *
     * THE ONE PLACE the state changes, because `closed_at` has to move with it.
     * They were separated once already: `closed_at` is not fillable -- like
     * every other field that is part of a set -- so an `update(['state' => ...,
     * 'closed_at' => now()])` wrote the state and silently dropped the
     * timestamp, leaving a finished request that claimed never to have closed.
     *
     * Reopening clears it, so a request that comes back from a failed
     * familiarisation does not keep a closing date it no longer has.
     */
    public function moveTo(MembershipRequestState $state): void
    {
        $this->state = $state;
        $this->closed_at = $state->isFinished() ? now() : null;

        $this->save();
    }

    /** Requests the membership desk has to act on. */
    public function scopeOnTheDesk(Builder $query): void
    {
        $query->whereIn('state', array_column(MembershipRequestState::onTheDesk(), 'value'));
    }

    /** Approved, actioned, and waiting on the training pipeline. */
    public function scopePendingTraining(Builder $query): void
    {
        $query->where('state', MembershipRequestState::PENDING_TRAINING);
    }

    public function scopeFinished(Builder $query): void
    {
        $query->whereIn('state', [
            MembershipRequestState::COMPLETE->value,
            MembershipRequestState::CLOSED->value,
            MembershipRequestState::CLOSED_BY_MEMBER->value,
        ]);
    }

    /**
     * What a MEMBER may see of their own requests.
     *
     * Visiting and transfer only. The other three are Terminal work the desk
     * records about somebody, and listing one back as "your request" would show
     * them something they never asked for.
     */
    public function scopeFiledBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->whereIn('type', array_column(MembershipRequestType::memberFiled(), 'value'));
    }

    /**
     * Whether the rating upgrade for one part of a training has been done.
     *
     * ## Why this exists
     *
     * The training page warns before signing off a part that no upgrade was
     * requested for it. That check used to read COMPLETED RatingUpgrade tasks.
     * Rating upgrades are membership requests now, so the task is never
     * created and the check silently became "always false" -- the warning would
     * have shown on every sign-off for ever, and a warning that is always on is
     * one people learn to click past.
     *
     * ## Both sources, and that is not temporary
     *
     * Historical rows are Tasks and always will be; new ones are membership
     * requests. Answering from only one of the two would make the warning wrong
     * for half the trainings in the database, so the caller asks both. That is
     * the honest shape of a system that changed how it records something.
     *
     * Mirrors how RatingUpgrade picked its target rating: an explicit
     * `rating_id` when the request names one, otherwise the training's highest
     * VATSIM rating.
     */
    public static function upgradeCompletedFor(Training $training, Rating $part): bool
    {
        return static::where('type', MembershipRequestType::RATING_UPGRADE)
            ->where('state', MembershipRequestState::COMPLETE)
            ->where('training_id', $training->id)
            ->get()
            ->contains(function (self $request) use ($training, $part) {
                if ($request->rating_id !== null) {
                    return (int) $request->rating_id === $part->id;
                }

                return $training->getHighestVatsimRating()?->id === $part->id;
            });
    }

    /**
     * Whether this member already has one of these open.
     *
     * One of the four checks that genuinely BLOCKS a new request -- see
     * CC-TRANSFERS-VISITS-SPEC.md. Scoped to member-filed types, because a
     * rating upgrade sitting on the desk must not stop somebody asking to
     * transfer.
     */
    public static function hasOpenFor(User $user, MembershipRequestType $type): bool
    {
        return static::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNotIn('state', [
                MembershipRequestState::COMPLETE->value,
                MembershipRequestState::CLOSED->value,
                MembershipRequestState::CLOSED_BY_MEMBER->value,
            ])
            ->exists();
    }
}
