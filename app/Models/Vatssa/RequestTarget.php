<?php

namespace App\Models\Vatssa;

use App\Models\Rating;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * VATSSA: who currently sits at each request desk.
 *
 * Four desks. Only the coordinator one is per-rating -- an S2 request goes to
 * the S2 coordinator, and "the S2 coordinator" is a question about rating, not
 * about area, which is why this does not live in `role_user`.
 *
 * Holding the `pipeline-coordinator` role is what grants the permissions. This
 * says who receives the work. They are deliberately separate: an ATC training
 * manager holds every coordinator permission and is nobody's default
 * coordinator.
 */
class RequestTarget extends Model
{
    protected $table = 'vatssa_request_targets';

    protected $fillable = ['tier', 'rating_id', 'user_id'];

    public const COORDINATOR = 'coordinator';

    public const TRAINING_MANAGER = 'training-manager';

    public const VATSSA1 = 'vatssa1';

    public const VATSSA2 = 'vatssa2';

    /**
     * The desks, in the order they should be offered.
     *
     * Coordinator first because it is the right answer for almost everything;
     * the two director desks last because escalating past the training staff
     * should feel like a deliberate choice rather than the top of a list.
     */
    public const TIERS = [
        self::COORDINATOR => [
            'label' => 'Pipeline coordinator',
            'hint' => 'The coordinator for this rating. Start here.',
            'per_rating' => true,
        ],
        self::TRAINING_MANAGER => [
            'label' => 'ATC training manager',
            'hint' => 'Training policy, examiner matters, anything a coordinator cannot decide.',
            'per_rating' => false,
        ],
        self::VATSSA1 => [
            'label' => 'Division Director (VATSSA1)',
            'hint' => 'Division-level decisions. Rarely the right first stop.',
            'per_rating' => false,
        ],
        self::VATSSA2 => [
            'label' => 'Deputy Division Director (VATSSA2)',
            'hint' => 'Division-level decisions. Rarely the right first stop.',
            'per_rating' => false,
        ],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    public static function isTier(?string $tier): bool
    {
        return $tier !== null && array_key_exists($tier, self::TIERS);
    }

    public static function isPerRating(string $tier): bool
    {
        return (bool) (self::TIERS[$tier]['per_rating'] ?? false);
    }

    public static function label(?string $tier): ?string
    {
        return $tier === null ? null : (self::TIERS[$tier]['label'] ?? $tier);
    }

    /**
     * Everybody sitting at one desk.
     *
     * For the coordinator desk, `$ratingId` selects the pipeline. A coordinator
     * row with a null rating is a CATCH-ALL and is included whatever the rating
     * -- which is what makes a single-coordinator division work without filling
     * in four identical rows.
     *
     * @return Collection<int, User>
     */
    public static function peopleAt(string $tier, ?int $ratingId = null): Collection
    {
        $query = static::where('tier', $tier)->with('user');

        if (self::isPerRating($tier) && $ratingId !== null) {
            $query->where(fn ($q) => $q->where('rating_id', $ratingId)->orWhereNull('rating_id'));
        }

        return $query->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Who a new request at this desk should land on.
     *
     * Fewest open tasks first, so one person does not absorb the queue simply
     * by being listed first. Null when the desk has nobody -- the caller
     * decides what to do about that, because silently picking somebody would be
     * worse than the request visibly having nowhere to go.
     */
    public static function nextAt(string $tier, ?int $ratingId = null): ?User
    {
        return self::peopleAt($tier, $ratingId)
            ->sortBy(fn (User $user) => Task::where('assignee_user_id', $user->id)
                ->whereNull('closed_at')
                ->count())
            ->first();
    }
}
