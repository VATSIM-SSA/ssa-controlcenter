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
}
