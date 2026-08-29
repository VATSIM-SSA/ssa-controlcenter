<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one sitting of one rating's theory exam.
 *
 * Keyed on person plus rating, never on training. A result owned by a training
 * dies with it: close the training, open a new one, and the pass is gone even
 * though the person still knows the material.
 *
 * The pass is DERIVED from the latest attempt, never stored. `latestFor()` is
 * the whole rule: the most recent result is what reflects current knowledge, so
 * somebody who passed two years ago and failed a retake last week has not
 * currently passed.
 */
class TheoryAttempt extends Model
{
    protected $table = 'vatssa_theory_attempts';

    protected $fillable = [
        'user_id', 'rating', 'moodle_course_id', 'moodle_quiz_id',
        'moodle_attempt_id', 'grade', 'passed', 'taken_at',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'grade' => 'decimal:2',
        'taken_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The most recent attempt at one rating, or null.
     */
    public static function latestFor(int $userId, string $rating): ?self
    {
        return static::where('user_id', $userId)
            ->where('rating', strtoupper($rating))
            ->orderByDesc('taken_at')
            ->orderByDesc('id')          // same-day retakes: the later row wins
            ->first();
    }

    /**
     * Whether this person currently holds a theory pass for this rating.
     *
     * Latest, not best. No attempts is not a pass; a rating with no course
     * never reaches this at all.
     */
    public static function passedRating(int $userId, string $rating): bool
    {
        return (bool) static::latestFor($userId, $rating)?->passed;
    }
}
