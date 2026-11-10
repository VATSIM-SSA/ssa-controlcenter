<?php

namespace App\Models;

use App\Helpers\FeedbackActivityType;
use App\Helpers\FeedbackDelivery;
use App\Helpers\FeedbackSentiment;
use App\Services\FeedbackStaffDiscord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a feedback's internal timeline. Entries are never edited or
 * deleted, so the timeline is a faithful record of who did what and when.
 *
 * `data` by type:
 * - sentiment: {from, to} (either may be null)
 * - edited:    {changes: [{label, from, to}]}
 * - sent:      {delivery, discord} and, for a rewritten version, its text in `comment`
 * - withdrawn: {delivery, discord_removed}
 * - comment, closed, reopened, acknowledged: none
 */
class FeedbackActivity extends Model
{
    protected $fillable = ['feedback_id', 'triggered_by_id', 'type', 'data', 'comment'];

    protected $casts = [
        'type' => FeedbackActivityType::class,
        'data' => 'array',
    ];

    /**
     * Every review action writes an entry, so this is the one place that
     * keeps the staff Discord message in step.
     */
    protected static function booted(): void
    {
        static::created(fn (self $activity) => app(FeedbackStaffDiscord::class)->refreshLater($activity->feedback_id));
    }

    /**
     * @return BelongsTo<Feedback, $this>
     */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(Feedback::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_id');
    }

    public function delivery(): ?FeedbackDelivery
    {
        return FeedbackDelivery::tryFrom($this->data['delivery'] ?? '');
    }

    public function postedToDiscord(): bool
    {
        return (bool) ($this->data['discord'] ?? false);
    }

    public function removedFromDiscord(): bool
    {
        return (bool) ($this->data['discord_removed'] ?? false);
    }

    public function sentimentFrom(): ?FeedbackSentiment
    {
        return FeedbackSentiment::tryFrom($this->data['from'] ?? '');
    }

    public function sentimentTo(): ?FeedbackSentiment
    {
        return FeedbackSentiment::tryFrom($this->data['to'] ?? '');
    }

    /**
     * @return array<int, array{label: string, from: string, to: string}>
     */
    public function changes(): array
    {
        return $this->data['changes'] ?? [];
    }

    /**
     * One plain-text line describing the entry, e.g. "Closed the review".
     * The page renders richer markup; this is for places like Discord.
     */
    public function summary(): string
    {
        return match ($this->type) {
            FeedbackActivityType::COMMENT => 'Commented',
            FeedbackActivityType::SENTIMENT => $this->sentimentTo() ? 'Tagged ' . $this->sentimentTo()->label() : 'Removed the tag',
            FeedbackActivityType::EDITED => 'Edited ' . implode(', ', array_map(fn (array $change): string => strtolower($change['label']), $this->changes())),
            FeedbackActivityType::CLOSED => 'Closed the review',
            FeedbackActivityType::REOPENED => 'Reopened the review',
            FeedbackActivityType::SENT => 'Sent it to the controller (' . strtolower((string) $this->delivery()?->label()) . ')',
            FeedbackActivityType::WITHDRAWN => 'Withdrew it from the controller',
            FeedbackActivityType::ACKNOWLEDGED => 'Acknowledged it',
        };
    }
}
