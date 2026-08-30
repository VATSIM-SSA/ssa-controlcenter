<?php

namespace App\Models\Vatssa;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: how many students a mentor is willing to run.
 *
 * A LIMIT, NOT A RULE. Nothing enforces it, and that is deliberate: enforcing
 * it would mean blocking an assignment two people have already agreed to in
 * person, which is not software's business. It exists so a coordinator can see
 * who is full before asking, and so a mentor can say "I could take one more"
 * without it being a Discord message that scrolls away.
 *
 * Per rating, because somebody willing to run three S2s is not necessarily
 * willing to run three C1s. A row with a null rating covers everything, which
 * is the case most divisions actually want.
 */
class MentorCapacity extends Model
{
    protected $table = 'vatssa_mentor_capacity';

    protected $fillable = ['user_id', 'rating_id', 'student_limit', 'note'];

    protected $casts = ['student_limit' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    /**
     * This mentor's limit for a rating.
     *
     * A rating-specific row wins; otherwise the mentor's own catch-all row;
     * otherwise the division default. Null means nobody has expressed a limit,
     * which is different from a limit of zero and must not be shown as one.
     */
    public static function limitFor(int $userId, ?int $ratingId = null): ?int
    {
        $rows = static::where('user_id', $userId)->get();

        $specific = $ratingId
            ? $rows->firstWhere('rating_id', $ratingId)
            : null;

        $general = $rows->firstWhere('rating_id', null);

        return $specific?->student_limit
            ?? $general?->student_limit
            ?? config('vatssa.default_mentor_capacity');
    }

    /**
     * How many open trainings this mentor is currently running.
     *
     * Open, not total. A mentor who has finished eleven students is not full.
     */
    public static function loadFor(User $user, ?int $ratingId = null): int
    {
        // mentoringTrainings() already filters to status >= PRE_TRAINING and
        // returns a Collection, so this is the open load and nothing else. A
        // mentor who has finished eleven students is not full.
        $open = $user->mentoringTrainings();

        if ($ratingId !== null) {
            $open = $open->filter(
                fn ($training) => $training->ratings->contains('id', $ratingId)
            );
        }

        return $open->count();
    }

    /**
     * How many more students this mentor could take, for one rating.
     *
     * TWO LIMITS APPLY AND THE SMALLER ONE WINS. A mentor with a total of five
     * and an S2 limit of four can run four S2s and one of something else -- not
     * four and four. Every caller needs that arithmetic and none of them should
     * be writing it themselves, so it lives here and only here.
     *
     * Null means unlimited, which is different from zero and must never be
     * rendered as it.
     */
    public static function roomFor(User $user, ?int $ratingId = null): ?int
    {
        $ceiling = MentorCeiling::find($user->id);

        $totalRoom = $ceiling?->total_limit !== null
            ? max(0, $ceiling->total_limit - self::loadFor($user))
            : null;

        $ratingLimit = $ratingId !== null
            ? static::where('user_id', $user->id)->where('rating_id', $ratingId)->value('student_limit')
            : null;

        $ratingRoom = $ratingLimit !== null
            ? max(0, $ratingLimit - self::loadFor($user, $ratingId))
            : null;

        return match (true) {
            $totalRoom === null && $ratingRoom === null => null,
            $totalRoom === null => $ratingRoom,
            $ratingRoom === null => $totalRoom,
            default => min($totalRoom, $ratingRoom),
        };
    }

    /**
     * Whether this mentor is at a limit somewhere.
     *
     * Used only to colour a badge. It answers "should a coordinator look
     * closer", not "is this assignment allowed" -- nothing is disallowed.
     */
    public static function isFull(User $user, ?int $ratingId = null): bool
    {
        return self::roomFor($user, $ratingId) === 0;
    }
}
