<?php

namespace App\Models;

use App\Contracts\DescribesActivityChanges;
use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Helpers\LogName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Feedback extends Model implements DescribesActivityChanges
{
    use HasFactory, LogsActivity, Notifiable;

    /**
     * Only log staff edits. MUST stay static — spatie v5 checks
     * isset(static::$recordEvents); a non-static property is ignored and every
     * event (including public submissions) would be logged.
     *
     * @var array<int, string>
     */
    protected static array $recordEvents = ['updated'];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'feedback',
        'submitter_user_id',
        'reference_user_id',
        'reference_position_id',
    ];

    /**
     * The action fields are NOT fillable, on purpose.
     *
     * They only ever move together -- a status, who decided it, and when -- and
     * `action()` is the one place that sets all three. Leaving them mass
     * assignable would let a request body set a status without an actor, which
     * is a piece of feedback that says it was dealt with by nobody.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => FeedbackStatus::class,
        'sentiment' => FeedbackSentiment::class,
        'actioned_at' => 'datetime',
    ];

    /**
     * Record reference re-assignments to the activity log under the "feedback"
     * category, storing old→new for the two foreign keys.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(LogName::Feedback)
            // The staff decision is logged alongside the reference edits: who
            // closed or forwarded a piece of feedback, and when, is exactly the
            // question somebody asks three months later. The note is left out
            // deliberately -- it can carry a member's personal circumstances,
            // and the log is read by more people than the feedback report is.
            ->logOnly(['reference_user_id', 'reference_position_id', 'status', 'sentiment'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName): string => "Feedback {$eventName}");
    }

    /**
     * Present the logged reference foreign keys as resolved names. The `link`
     * closures are stubbed until the position and controller-feedback routes
     * exist; wiring them here is all that is needed to make the log entries
     * link through — the generic log view needs no changes.
     *
     * {@inheritDoc}
     */
    public static function activityChangeReferences(): array
    {
        return [
            'reference_user_id' => [
                'label' => 'Controller',
                'model' => User::class,
                'display' => fn (User $user): string => "{$user->name} ({$user->id})",
                'link' => null, // future: fn (User $user) => route('reports.feedback', ['controller' => $user->id])
            ],
            'reference_position_id' => [
                'label' => 'Position',
                'model' => Position::class,
                'display' => fn (Position $position): string => $position->callsign,
                'link' => null, // future: fn (Position $position) => $position->path()
            ],
        ];
    }

    /**
     * Scope feedback to what the given user may see: correlated feedback within
     * their permitted areas (or all correlated when global), plus uncorrelated
     * feedback when they hold that permission. Single source of truth for
     * feedback visibility — used by the report listing and the filter option
     * providers, so a crafted filter can never widen it.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $correlatedScope = $user->accessibleAreasForPermission('feedback.correlated.view');
        $canViewUncorrelated = $user->accessibleAreasForPermission('feedback.uncorrelated.view')->hasAccess();

        $query->where(function (Builder $q) use ($correlatedScope, $canViewUncorrelated) {
            if ($correlatedScope->isGlobal) {
                $q->whereNotNull('reference_position_id');
            } else {
                $q->whereHas('referencePosition', fn (Builder $q) => $q->whereIn('area_id', $correlatedScope->areas->pluck('id')));
            }

            if ($canViewUncorrelated) {
                $q->orWhereNull('reference_position_id');
            }
        });
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitter_user_id');
    }

    public function referenceUser()
    {
        return $this->belongsTo(User::class, 'reference_user_id');
    }

    public function referencePosition()
    {
        return $this->belongsTo(Position::class, 'reference_position_id');
    }

    /** The staff member who actioned this, if anybody has. */
    public function actionedBy()
    {
        return $this->belongsTo(User::class, 'actioned_by_id');
    }

    /**
     * Record the staff decision: what this was, what to do with it, and why.
     *
     * THE ONE PLACE the action fields are written. They only make sense
     * together -- a status without an actor is feedback that says it was dealt
     * with by nobody -- so they are not mass assignable and every caller comes
     * through here.
     *
     * Re-actioning is allowed and is not an edit of history: `actioned_at`
     * moves to the new decision, because the question the column answers is
     * "when was this last decided", and a division correcting a mis-click
     * should not have to live with it.
     *
     * The feedback TEXT is never touched. A submission is a record of what
     * somebody said, and a division that can rewrite it into something more
     * palatable does not have feedback, it has a newsletter.
     */
    public function action(
        FeedbackStatus $status,
        User $actor,
        ?FeedbackSentiment $sentiment = null,
        ?string $note = null,
    ): void {
        $this->status = $status;
        $this->sentiment = $sentiment;
        $this->staff_note = $note;
        $this->actioned_by_id = $actor->id;
        $this->actioned_at = now();

        $this->save();
    }

    /** Feedback nobody has dealt with yet: the default queue. */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', FeedbackStatus::OPEN);
    }

    /**
     * Feedback a controller is allowed to read about themselves.
     *
     * Only what staff have explicitly forwarded. Everything else -- open, or
     * read and closed -- stays internal, which is the whole reason the two
     * outcomes are separate states.
     */
    public function scopeForwardedTo(Builder $query, User $user): void
    {
        $query->where('status', FeedbackStatus::FORWARDED)
            ->where('reference_user_id', $user->id);
    }
}
