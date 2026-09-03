<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: what KIND of feedback this is.
 *
 * Upstream's feedback is one undifferentiated stream: every row is a paragraph
 * of text about a controller, and the feedback team reads all of them the same
 * way. A compliment, a complaint and a bug report are three different jobs with
 * three different urgencies, and a team that cannot sort them reads the whole
 * queue to find the one that mattered.
 *
 * ## Editable, not a constant
 *
 * Same reasoning as training types and request desks: this describes how VATSSA
 * organises itself this year, which changes more often than the code. A new
 * kind of feedback should be a row, not a deploy.
 *
 * ## Retired, not deleted
 *
 * `active = false` stops a type being OFFERED on the form. Existing feedback
 * keeps rendering it -- a complaint from last year whose type vanished would
 * show a blank where its kind should be, and the history is what the record is
 * for.
 */
class FeedbackType extends Model
{
    protected $table = 'vatssa_feedback_types';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'label', 'hint', 'sort_order', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * What an unmigrated database shows.
     *
     * A feedback form with an empty "what kind" dropdown is a form nobody can
     * submit, which is worse than an out-of-date list. Same guard as
     * TrainingType and RequestDesk.
     */
    public const FALLBACK = [
        'compliment' => ['label' => 'Compliment', 'hint' => 'Somebody did something well.'],
        'complaint' => ['label' => 'Complaint', 'hint' => 'Something went wrong and somebody should know.'],
        'bug-report' => ['label' => 'Bug report', 'hint' => 'Control Center itself is broken.'],
    ];

    /**
     * Every type, as `[key => ['label' => …, 'hint' => …]]`.
     *
     * @param  bool  $onlyActive  true when offering a choice, false when labelling history
     * @return array<string, array{label: string, hint: ?string}>
     */
    public static function map(bool $onlyActive = false): array
    {
        if (! Schema::hasTable('vatssa_feedback_types')) {
            return self::FALLBACK;
        }

        $query = static::orderBy('sort_order')->orderBy('key');

        if ($onlyActive) {
            $query->where('active', true);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return self::FALLBACK;
        }

        return $rows->mapWithKeys(fn (self $type) => [
            $type->key => ['label' => $type->label, 'hint' => $type->hint],
        ])->all();
    }

    /** The label for one key, falling back to the key itself. */
    public static function label(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::map()[$key]['label'] ?? $key;
    }

    /** Whether a key names a type that exists. */
    public static function isType(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::map());
    }
}
