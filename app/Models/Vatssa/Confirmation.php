<?php

namespace App\Models\Vatssa;

use App\Models\Training;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * VATSSA: a deadline somebody was given, and whether they met it.
 *
 * Upstream's `training_interests` is the same shape for one case. This covers
 * the other three, and the training page unions them into one table -- because
 * a coordinator asking "what have we asked this person for" does not care which
 * subsystem sent it.
 *
 * @property int $training_id
 * @property string $type
 * @property Carbon $sent_at
 * @property Carbon $deadline
 * @property Carbon|null $confirmed_at
 * @property int $expired
 * @property int $reminders
 */
class Confirmation extends Model
{
    /** Confirm you still want the training. Upstream's, kept for the union. */
    public const INTEREST = 'interest';

    /** Join the Discord server. Mandatory: mentoring happens in voice. */
    public const JOIN_DISCORD = 'join_discord';

    /** Register on Moodle. */
    public const JOIN_MOODLE = 'join_moodle';

    /** Pass the theory exam. The ninety-day one. */
    public const COMPLETE_MOODLE = 'complete_moodle';

    /** Still waiting. */
    public const OPEN = 0;

    /** The requirement went away -- leave granted, training closed, exempted. */
    public const INVALIDATED = 1;

    /** The deadline passed without it being met. */
    public const MISSED = 2;

    protected $table = 'vatssa_confirmations';

    /**
     * `expired` is deliberately absent.
     *
     * It is a lifecycle field, and every legitimate transition goes through a
     * named method below. A fillable `expired` means one careless update()
     * marks a missed deadline as met.
     */
    protected $fillable = [
        'training_id', 'type', 'sent_at', 'deadline', 'confirmed_at', 'reminders',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'deadline' => 'datetime',
        'confirmed_at' => 'datetime',
        'expired' => 'integer',
        'reminders' => 'integer',
    ];

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /**
     * The four types, in the order a student meets them.
     *
     * Not alphabetical and not insertion order: this is the sequence somebody
     * actually goes through, so a list sorted by it reads as a journey rather
     * than as a bag of records.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::INTEREST => 'Interest',
            self::JOIN_DISCORD => 'Join Discord',
            self::JOIN_MOODLE => 'Join Moodle',
            self::COMPLETE_MOODLE => 'Complete Moodle',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->type] ?? $this->type;
    }

    /**
     * Whether this is still waiting on the student.
     *
     * Note it is not `! $this->confirmed_at`: an invalidated row is not
     * confirmed and is not waiting on anybody.
     */
    public function isOpen(): bool
    {
        return $this->confirmed_at === null && $this->expired === self::OPEN;
    }

    /**
     * Past its deadline and still open.
     *
     * Distinct from MISSED, which is the settled state written by the sweep.
     * Between the deadline passing and the sweep running, a row is overdue but
     * not yet missed, and the page should say so rather than pretending the
     * sweep is instant.
     */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->deadline->isPast();
    }

    /** They did it. Idempotent: confirming twice is not an error. */
    public function confirm(): void
    {
        if ($this->confirmed_at !== null) {
            return;
        }

        $this->forceFill(['confirmed_at' => now()])->save();
    }

    /**
     * The requirement went away.
     *
     * Never call this for a missed deadline -- an invalidated row reads as
     * "we stopped asking", and using it to tidy up a real miss erases the only
     * evidence that somebody was chased and did not answer.
     */
    public function invalidate(): void
    {
        if (! $this->isOpen()) {
            return;
        }

        $this->forceFill(['expired' => self::INVALIDATED])->save();
    }

    /** The deadline passed. Written by the daily sweep, not by a request. */
    public function miss(): void
    {
        if (! $this->isOpen()) {
            return;
        }

        $this->forceFill(['expired' => self::MISSED])->save();
    }
}
