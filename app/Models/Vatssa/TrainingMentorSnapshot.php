<?php

namespace App\Models\Vatssa;

use App\Models\Training;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * VATSSA: who was mentoring what, as of the last look.
 *
 * A cache of a current fact, used for one thing: telling what CHANGED since
 * yesterday. `training_mentor` is a plain pivot, `detach()` fires no events,
 * and upstream detaches in at least three places -- so a diff is the only way
 * to see a removal that is guaranteed to catch paths nobody has found yet.
 *
 * @see database/migrations-vatssa/2026_08_31_100000_vatssa_training_mentors.php
 */
class TrainingMentorSnapshot extends Model
{
    protected $table = 'vatssa_training_mentors';

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['training_id', 'user_id', 'seen_at'];

    protected $casts = ['seen_at' => 'datetime'];

    /**
     * Has this table ever been written to?
     *
     * Empty means NO PRIOR KNOWLEDGE, not "nobody was mentoring anybody". The
     * first run must seed silently -- the alternative is emailing every mentor
     * in the division that they have lost every student.
     */
    public static function isPrimed(): bool
    {
        return static::query()->exists();
    }

    /**
     * Mentor ids per training, as of the last look.
     *
     * @return Collection<int, array<int, int>>
     */
    public static function previous(): Collection
    {
        return static::query()
            ->get(['training_id', 'user_id'])
            ->groupBy('training_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->all());
    }

    /**
     * Overwrite with what is true now.
     *
     * A full rewrite rather than a merge. The table has no value beyond "the
     * last thing we saw", and a merge would leave rows for trainings that have
     * since closed, which would then look like removals for ever.
     */
    public static function capture(): void
    {
        $rows = DB::table('training_mentor')
            ->select('training_id', 'user_id')
            ->get()
            ->map(fn ($row) => [
                'training_id' => $row->training_id,
                'user_id' => $row->user_id,
                'seen_at' => now(),
            ])
            ->all();

        DB::transaction(function () use ($rows) {
            static::query()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                static::insert($chunk);
            }
        });
    }

    public function training()
    {
        return $this->belongsTo(Training::class);
    }
}
