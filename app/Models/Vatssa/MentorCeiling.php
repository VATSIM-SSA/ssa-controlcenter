<?php

namespace App\Models\Vatssa;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * VATSSA: how far up the ladder a mentor may teach, and how many at once.
 *
 * The ceiling answers the question a plain capacity number never could:
 * somebody cleared to mentor S2 is not thereby cleared to mentor C1. Until the
 * ATC training manager raises it, they should not even appear as an option for
 * a rating above their ceiling.
 *
 * SET BY THE TRAINING MANAGER, ALWAYS. There is no mentor-editable number
 * anywhere: a mentor asks through the request desk and the manager decides.
 * Leaving a self-service field beside that request would have made the request
 * pointless.
 */
class MentorCeiling extends Model
{
    protected $table = 'vatssa_mentor_ceiling';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'total_limit', 'max_rating_id'];

    protected $casts = ['total_limit' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maxRating(): BelongsTo
    {
        return $this->belongsTo(Rating::class, 'max_rating_id');
    }

    /**
     * Whether this mentor may teach a given rating at all.
     *
     * NO CEILING MEANS NO RESTRICTION, not "no ratings". A division that has
     * never used this feature must keep working exactly as it did, and a
     * migration that silently stopped every mentor from teaching would be a
     * spectacular way to find that out.
     */
    public static function mayTeach(int $userId, Rating $rating): bool
    {
        $ceiling = static::with('maxRating')->find($userId);

        if ($ceiling?->maxRating === null) {
            return true;
        }

        // Endorsement ratings carry no vatsim_rating, so there is no ladder to
        // compare them on. They are allowed: the ceiling is about the ATC
        // progression, not about every rating that exists.
        if ($rating->vatsim_rating === null || $ceiling->maxRating->vatsim_rating === null) {
            return true;
        }

        return $rating->vatsim_rating->value <= $ceiling->maxRating->vatsim_rating->value;
    }

    /**
     * The ratings this mentor may teach, for a picker.
     *
     * @return Collection<int, Rating>
     */
    public static function ratingsFor(int $userId)
    {
        return Rating::whereNotNull('vatsim_rating')
            ->orderBy('vatsim_rating')
            ->get()
            ->filter(fn (Rating $rating) => self::mayTeach($userId, $rating))
            ->values();
    }

    /**
     * The label to show when somebody has no ceiling set.
     */
    public static function describeFor(int $userId): string
    {
        $ceiling = static::with('maxRating')->find($userId);

        if ($ceiling?->maxRating) {
            return 'Up to ' . $ceiling->maxRating->name;
        }

        return 'No rating ceiling set';
    }
}
