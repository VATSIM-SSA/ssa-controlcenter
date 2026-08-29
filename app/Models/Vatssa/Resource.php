<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * VATSSA: a link mentors need and can never find.
 *
 * The syllabus, the sweatbox files, the exam template, the mentor drive. Sounds
 * too trivial to build. It is exactly the thing that lives in a pinned Discord
 * message nobody scrolls to, acquires a dead URL after somebody moves a folder,
 * and produces "where is the syllabus again" once a fortnight forever.
 *
 * Editable in Control Center so a moved folder is a one-minute fix by whoever
 * moved it, rather than a deploy.
 */
class Resource extends Model
{
    protected $table = 'vatssa_resources';

    protected $fillable = ['label', 'url', 'icon', 'description', 'audience', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public const AUDIENCE_MENTOR = 'mentor';

    /** @return Collection<int, self> */
    public static function forAudience(string $audience = self::AUDIENCE_MENTOR): Collection
    {
        return static::where('audience', $audience)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }
}
