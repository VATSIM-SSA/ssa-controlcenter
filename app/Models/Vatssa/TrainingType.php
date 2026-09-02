<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a kind of training, editable rather than compiled in.
 *
 * ## What this replaced
 *
 * `TrainingController::$types` -- a static array of five entries in an UPSTREAM
 * file. Adding "Endorsement training" meant editing upstream's controller,
 * which is a merge conflict on every future release, and a deploy for a label
 * and an icon.
 *
 * ## The id is the value, not a surrogate
 *
 * `trainings.type` holds 1 to 5 today and those rows exist. So the primary key
 * here is the same number, set by hand rather than auto-incremented, and a new
 * type picks the next free one. That is slightly awkward to write and means the
 * existing data needs no migration at all, which is the better trade.
 *
 * ## Retired, not deleted
 *
 * `active = false` stops a type being OFFERED. It still renders on every
 * training that already used it -- a closed training from 2024 whose type
 * vanished would show a blank where its kind should be, and the history is the
 * thing a training record is for.
 */
class TrainingType extends Model
{
    protected $table = 'vatssa_training_types';

    public $incrementing = false;

    protected $fillable = ['id', 'name', 'icon', 'description', 'sort_order', 'active'];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * What the code used to hold, and what it falls back to.
     *
     * A database that has not run the migration yet -- a fresh checkout, a
     * developer machine, the moment between deploying code and running
     * migrations -- must not show a member an empty "what kind of training"
     * dropdown. It shows what it showed yesterday instead.
     */
    public const FALLBACK = [
        1 => ['text' => 'Standard', 'icon' => 'fas fa-circle'],
        2 => ['text' => 'Refresh', 'icon' => 'fas fa-sync'],
        3 => ['text' => 'Transfer', 'icon' => 'fas fa-exchange-alt'],
        4 => ['text' => 'Fast-track', 'icon' => 'fas fa-fast-forward'],
        5 => ['text' => 'Familiarisation', 'icon' => 'fas fa-compress-arrows-alt'],
    ];

    /**
     * Every type, in the shape the existing views already expect.
     *
     * `[id => ['text' => …, 'icon' => …]]` is what `TrainingController::$types`
     * was, and a dozen blades index straight into it. Keeping the shape means
     * this change is invisible to all of them.
     *
     * @param  bool  $onlyActive  false when rendering history, true when offering a choice
     * @return array<int, array{text: string, icon: string}>
     */
    public static function map(bool $onlyActive = false): array
    {
        if (! Schema::hasTable('vatssa_training_types')) {
            return self::FALLBACK;
        }

        $rows = static::query()
            ->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return self::FALLBACK;
        }

        return $rows
            ->mapWithKeys(fn (self $t) => [$t->id => ['text' => $t->name, 'icon' => $t->icon]])
            ->all();
    }

    /**
     * Ids that may be OFFERED, for the two views that let somebody pick.
     *
     * Separate from map(), which returns everything so history renders. A
     * retired type must vanish from the chooser and stay on the trainings that
     * already used it.
     *
     * @return array<int, int>
     */
    public static function activeIds(): array
    {
        if (! Schema::hasTable('vatssa_training_types')) {
            return array_keys(self::FALLBACK);
        }

        $ids = static::query()->where('active', true)->pluck('id')->all();

        return $ids ?: array_keys(self::FALLBACK);
    }

    /** The next free id, for the admin page's "add" form. */
    public static function nextId(): int
    {
        if (! Schema::hasTable('vatssa_training_types')) {
            return max(array_keys(self::FALLBACK)) + 1;
        }

        return ((int) static::query()->max('id')) + 1;
    }
}
