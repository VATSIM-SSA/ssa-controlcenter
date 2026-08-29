<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Model;

/**
 * VATSSA: which rating needs which Moodle course, and what counts as a pass.
 *
 * ONE QUIZ PER COURSE COUNTS -- the final one, which is that rating's theory
 * exam. Earlier quizzes in a course are practice and are not tracked at all.
 *
 * A rating absent from this table needs no theory, which is how the visiting,
 * transfer and refresher tracks fall out without a special case.
 */
class MoodleCourse extends Model
{
    protected $table = 'vatssa_moodle_courses';

    protected $primaryKey = 'rating';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['rating', 'course_id', 'exam_quiz_id', 'pass_mark', 'active'];

    protected $casts = [
        'pass_mark' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * The map the bot reads, in the shape it expects.
     *
     * A row whose ids are still placeholders is left out deliberately.
     * Dropping it means the rating visibly needs no theory, which gets noticed
     * and fixed. Including it would mean every student silently has no
     * attempts, which looks exactly like a room full of failures.
     */
    public static function map(): array
    {
        return static::where('active', true)
            ->where('course_id', '>', 0)
            ->where('exam_quiz_id', '>', 0)
            ->get()
            ->mapWithKeys(fn (self $course) => [strtoupper($course->rating) => [
                'course_id' => (int) $course->course_id,
                'exam_quiz_id' => (int) $course->exam_quiz_id,
                'pass_mark' => (float) $course->pass_mark,
            ]])
            ->all();
    }
}
