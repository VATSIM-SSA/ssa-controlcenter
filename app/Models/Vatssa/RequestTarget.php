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
 * Three desks. Only the coordinator one is per-rating -- an S2 request goes to
 * the S2 coordinator, and "the S2 coordinator" is a question about rating, not
 * about area, which is why this does not live in `role_user`.
 *
 * Holding the `pipeline-coordinator` role is what grants the permissions. This
 * says who receives the work. They are deliberately separate: an ATC training
 * manager holds every coordinator permission and is nobody's default
 * coordinator.
 *
 * ## A REQUEST BELONGS TO A DESK, NOT TO A PERSON
 *
 * `tasks.assignee_user_id` is NOT NULL, so a row still carries somebody -- but
 * that is a database requirement, not a fact about the work, and nothing in the
 * interface shows it. Everybody at a desk sees the same queue and any of them
 * can act. A coordinator going on leave does not take their requests with them,
 * and nobody has to think about who "owns" a request before answering it.
 */
class RequestTarget extends Model
{
    protected $table = 'vatssa_request_targets';

    protected $fillable = ['tier', 'rating_id', 'user_id'];

    public const COORDINATOR = 'coordinator';

    public const TRAINING_MANAGER = 'training-manager';

    public const LEADERSHIP = 'leadership';

    /**
     * The desks, in the order they should be offered.
     *
     * Coordinator first because it is the right answer for almost everything;
     * leadership last, because escalating past the training staff should feel
     * like a deliberate choice rather than the top of a list.
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
        // One desk, not VATSSA1 and VATSSA2 separately. Somebody escalating a
        // training matter does not know which director owns it, and making them
        // guess is how a request ends up on the wrong one. Both sit here.
        self::LEADERSHIP => [
            'label' => 'Division leadership',
            'hint' => 'VATSSA1 and VATSSA2. Division-level decisions, and rarely the right first stop.',
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
     * Which row a new request should be stamped with.
     *
     * NOT "who is responsible" -- the desk is. This only satisfies the NOT NULL
     * on `assignee_user_id`, and nothing in the interface reads it back. The
     * first person at the desk is as good an answer as any; picking by workload
     * would imply an ownership the model does not have.
     *
     * Null when the desk is empty. The caller decides what to do about that,
     * because silently picking somebody would be worse than the request
     * visibly having nowhere to go.
     */
    public static function nextAt(string $tier, ?int $ratingId = null): ?User
    {
        return self::peopleAt($tier, $ratingId)->first();
    }

    /**
     * The desks one person sits at.
     *
     * This is what "your tasks" means now. Admins are on the leadership desk
     * whether or not somebody remembered to add them: they can already do
     * everything, and a leadership request nobody sees is worse than one seen
     * by an extra person.
     *
     * @return Collection<int, array{tier: string, rating_id: int|null}>
     */
    public static function desksFor(User $user): Collection
    {
        $desks = static::where('user_id', $user->id)
            ->get(['tier', 'rating_id'])
            ->map(fn (self $row) => ['tier' => $row->tier, 'rating_id' => $row->rating_id]);

        if ($user->hasPermission('system.settings.manage')
            && ! $desks->contains(fn ($desk) => $desk['tier'] === self::LEADERSHIP)) {
            $desks->push(['tier' => self::LEADERSHIP, 'rating_id' => null]);
        }

        return $desks->values();
    }

    /**
     * Constrain a task query to the desks somebody sits at.
     *
     * A coordinator desk row with a null rating is a catch-all and matches
     * every rating, which is what lets a one-coordinator division work without
     * four identical rows.
     */
    public static function scopeToDesks($query, Collection $desks)
    {
        return $query->where(function ($outer) use ($desks) {
            // Matches nothing when the list is empty, rather than everything.
            // The failure mode of the alternative is showing one person the
            // whole division's requests.
            $outer->whereRaw('1 = 0');

            foreach ($desks as $desk) {
                $outer->orWhere(function ($q) use ($desk) {
                    $q->where('vatssa_tier', $desk['tier']);

                    if ($desk['rating_id'] !== null) {
                        $q->where(fn ($r) => $r->where('vatssa_rating_id', $desk['rating_id'])
                            ->orWhereNull('vatssa_rating_id'));
                    }
                });
            }
        });
    }
}
