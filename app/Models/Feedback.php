<?php

namespace App\Models;

use App\Contracts\DescribesActivityChanges;
use App\Contracts\RequiresAcknowledgement;
use App\Helpers\FeedbackActivityType;
use App\Helpers\FeedbackDelivery;
use App\Helpers\FeedbackSentiment;
use App\Helpers\FeedbackStatus;
use App\Helpers\LogName;
use App\Services\FeedbackStaffDiscord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Feedback extends Model implements DescribesActivityChanges, RequiresAcknowledgement
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
     * Post every new submission to the staff Discord channel, if one is set.
     * Later changes reach it through FeedbackActivity, which every review
     * action writes.
     */
    protected static function booted(): void
    {
        static::created(fn (self $feedback) => app(FeedbackStaffDiscord::class)->refreshLater($feedback->id));
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'feedback',
        'submitter_user_id',
        'reference_user_id',
        'reference_position_id',
        'area_id',
        'pilot_callsign',
        'event_date',
        'departure_airport',
        'arrival_airport',
        'session_callsign',
        'session_started_at',
        'session_ended_at',
        'status',
        'sentiment',
        'closed_by_user_id',
        'closed_at',
        'sent_version',
        'sent_message',
        'sent_by_user_id',
        'sent_at',
        'discord_posted_at',
        'discord_message_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'event_date' => 'date',
        'session_started_at' => 'datetime',
        'session_ended_at' => 'datetime',
        'status' => FeedbackStatus::class,
        'sentiment' => FeedbackSentiment::class,
        'closed_at' => 'datetime',
        'sent_version' => FeedbackDelivery::class,
        'sent_at' => 'datetime',
        'discord_posted_at' => 'datetime',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * Record reference re-assignments to the activity log under the "feedback"
     * category, storing old→new for the reference foreign keys.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(LogName::Feedback)
            ->logOnly(['reference_user_id', 'reference_position_id', 'area_id'])
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
            'area_id' => [
                'label' => 'Area',
                'model' => Area::class,
                'display' => fn (Area $area): string => $area->name,
                'link' => null,
            ],
        ];
    }

    /**
     * Match feedback belonging to any of the given areas, explicit or inherited
     * from the referenced position. Query-side twin of the `area` accessor;
     * use it rather than rolling the same condition, so visibility,
     * authorization and filtering cannot disagree.
     *
     * Pass an iterable: `collect()` flattens a lone model into its attributes.
     *
     * @param  Builder<self>  $query
     * @param  Collection<int, Area|int>|array<int, Area|int>  $areas
     */
    public function scopeInArea(Builder $query, Collection|array $areas): void
    {
        $areaIds = collect($areas)->map(fn (Area|int $area): int => $area instanceof Area ? $area->id : $area)->all();

        $query->where(fn (Builder $q) => $q
            ->whereIn('area_id', $areaIds)
            ->orWhereHas('referencePosition', fn (Builder $q) => $q->whereIn('area_id', $areaIds)));
    }

    /**
     * Scope feedback to what the given user may see: correlated feedback within
     * their permitted areas (or all correlated when global), plus uncorrelated
     * feedback when they hold that permission. Single source of truth for
     * feedback visibility — used by the report listing and the filter option
     * providers, so a crafted filter can never widen it.
     *
     * An explicit area correlates a feedback just as a referenced position
     * does, so only feedback carrying neither is uncorrelated.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $correlatedScope = $user->accessibleAreasForPermission('feedback.correlated.view');
        $canViewUncorrelated = $user->accessibleAreasForPermission('feedback.uncorrelated.view')->hasAccess();

        $query->where(function (Builder $q) use ($correlatedScope, $canViewUncorrelated) {
            if ($correlatedScope->isGlobal) {
                $q->where(fn (Builder $q) => $q->whereNotNull('reference_position_id')->orWhereNotNull('area_id'));
            } else {
                $q->inArea($correlatedScope->areas);
            }

            if ($canViewUncorrelated) {
                $q->orWhere(fn (Builder $q) => $q->whereNull('reference_position_id')->whereNull('area_id'));
            }
        });
    }

    /**
     * Feedback that was sent to the given controller, as they may read it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSentTo(Builder $query, User $user): void
    {
        $query->where('reference_user_id', $user->id)->whereNotNull('sent_at');
    }

    public function isOpen(): bool
    {
        return $this->status === FeedbackStatus::OPEN;
    }

    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * The words the controller received: the rewritten version if staff wrote
     * one, otherwise the original. Null when nothing was sent.
     */
    public function sentText(): ?string
    {
        if (! $this->wasSent()) {
            return null;
        }

        return $this->sent_version === FeedbackDelivery::REWRITTEN ? (string) $this->sent_message : $this->feedback;
    }

    /**
     * Close the review. What the controller has (if anything) is unaffected;
     * sending and withdrawing go through FeedbackDeliveryService.
     */
    public function close(User $by, ?string $comment = null): void
    {
        $this->update([
            'status' => FeedbackStatus::CLOSED,
            'closed_by_user_id' => $by->id,
            'closed_at' => now(),
        ]);

        $this->recordActivity(FeedbackActivityType::CLOSED, $by);

        if (filled($comment)) {
            $this->recordActivity(FeedbackActivityType::COMMENT, $by, comment: $comment);
        }
    }

    /**
     * Put a closed entry back in the review queue, for example to discuss it
     * again. What the controller has stays as it is.
     */
    public function reopen(User $by): void
    {
        $this->update([
            'status' => FeedbackStatus::OPEN,
            'closed_by_user_id' => null,
            'closed_at' => null,
        ]);

        $this->recordActivity(FeedbackActivityType::REOPENED, $by);
    }

    public function setSentiment(?FeedbackSentiment $sentiment, User $by): void
    {
        if ($this->sentiment === $sentiment) {
            return;
        }

        $from = $this->sentiment;
        $this->update(['sentiment' => $sentiment]);

        $this->recordActivity(FeedbackActivityType::SENTIMENT, $by, ['from' => $from?->value, 'to' => $sentiment?->value]);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function recordActivity(FeedbackActivityType $type, ?User $by, ?array $data = null, ?string $comment = null): FeedbackActivity
    {
        return $this->activities()->create([
            'triggered_by_id' => $by?->id,
            'type' => $type,
            'data' => $data,
            'comment' => $comment,
        ]);
    }

    /**
     * The session the feedback is about, e.g. "EKCH_APP · 8 Nov, 1940–2038z".
     */
    public function sessionLabel(): ?string
    {
        if ($this->session_callsign === null || $this->session_started_at === null) {
            return null;
        }

        $end = $this->session_ended_at ? '–' . $this->session_ended_at->utc()->format('Hi') : '';

        return $this->session_callsign . ' · ' . $this->session_started_at->utc()->format('j M, Hi') . $end . 'z';
    }

    /**
     * The pilot's flight, e.g. "SAS123 · ESSA → EKCH · 08/11/2026", or null.
     */
    public function flightLabel(): ?string
    {
        $route = $this->departure_airport || $this->arrival_airport
            ? ($this->departure_airport ?? '?') . ' → ' . ($this->arrival_airport ?? '?')
            : null;

        $parts = array_filter([$this->pilot_callsign, $route, $this->event_date?->toEuropeanDate()]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function acknowledgementTitle(): string
    {
        return 'You have received feedback';
    }

    public function acknowledgementContext(): ?string
    {
        return $this->sessionLabel()
            ?? $this->referencePosition?->callsign
            ?? $this->created_at->toEuropeanDate();
    }

    public function acknowledgementBody(): string
    {
        return (string) $this->sentText();
    }

    public function acknowledged(Acknowledgement $acknowledgement): void
    {
        $this->recordActivity(FeedbackActivityType::ACKNOWLEDGED, $acknowledgement->user);
    }

    /**
     * @return HasMany<FeedbackActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(FeedbackActivity::class);
    }

    /**
     * @return MorphMany<Acknowledgement, $this>
     */
    public function acknowledgements(): MorphMany
    {
        return $this->morphMany(Acknowledgement::class, 'subject');
    }

    /**
     * The latest request to the controller to acknowledge what was sent.
     *
     * @return MorphOne<Acknowledgement, $this>
     */
    public function latestAcknowledgement(): MorphOne
    {
        return $this->morphOne(Acknowledgement::class, 'subject')->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
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

    public function explicitArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    /**
     * Explicit area wins; otherwise falls back to the position's area. The two
     * are mutually exclusive (enforced in UpdateFeedbackRequest). Eager-load it
     * as `with('explicitArea')`; `with('area')` is not a relation.
     */
    public function getAreaAttribute(): ?Area
    {
        return $this->explicitArea ?? $this->referencePosition?->area;
    }
}
