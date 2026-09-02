<?php

namespace App\Models\Vatssa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * VATSSA: a desk a request can be addressed to, editable rather than compiled in.
 *
 * ## What this replaced
 *
 * `RequestTarget::TIERS`, a class constant. The desks describe how the division
 * is organised THIS YEAR -- who fields what, and in what order somebody
 * escalates. That changes more often than the code does, and it changing should
 * not require somebody who can write PHP.
 *
 * ## `per_rating`
 *
 * The coordinator desk is staffed per rating: an S2 request goes to the S2
 * coordinator. Membership, the training manager and leadership are one desk
 * each. That distinction is the only behaviour in this table -- everything else
 * is a label and an order.
 *
 * ## Retired, not deleted
 *
 * `active = false` stops a desk being OFFERED on the form. Requests already
 * sitting on it keep their label, keep routing to whoever staffs it, and are
 * still findable. Deleting a desk would orphan every open request on it, which
 * is the one thing a request queue must never do.
 */
class RequestDesk extends Model
{
    protected $table = 'vatssa_request_desks';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'label', 'hint', 'per_rating', 'sort_order', 'active'];

    protected $casts = [
        'per_rating' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * What the constant held, and what this falls back to.
     *
     * An unmigrated database must not produce a request form with no desks on
     * it -- that is a member unable to ask for anything at all, which is worse
     * than an out-of-date list.
     */
    public const FALLBACK = [
        'coordinator' => [
            'label' => 'Pipeline coordinator',
            'hint' => 'The coordinator for this rating. Start here.',
            'per_rating' => true,
        ],
        'membership' => [
            'label' => 'Membership',
            'hint' => 'Rating updates, transfers, visiting controllers, anything about somebody\'s standing.',
            'per_rating' => false,
        ],
        'training-manager' => [
            'label' => 'ATC training manager',
            'hint' => 'Training policy, examiner matters, anything a coordinator cannot decide.',
            'per_rating' => false,
        ],
        'leadership' => [
            'label' => 'Division leadership',
            'hint' => 'VATSSA1 and VATSSA2. Division-level decisions, and rarely the right first stop.',
            'per_rating' => false,
        ],
    ];

    /**
     * Every desk, in the shape `RequestTarget::TIERS` had.
     *
     * `[key => ['label' => …, 'hint' => …, 'per_rating' => bool]]`, because
     * that is what the routing code, the admin page and the request form all
     * already read.
     *
     * @param  bool  $onlyActive  true when offering a choice, false when labelling history
     * @return array<string, array{label: string, hint: ?string, per_rating: bool}>
     */
    public static function map(bool $onlyActive = false): array
    {
        if (! Schema::hasTable('vatssa_request_desks')) {
            return self::FALLBACK;
        }

        $rows = static::query()
            ->when($onlyActive, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        if ($rows->isEmpty()) {
            return self::FALLBACK;
        }

        return $rows
            ->mapWithKeys(fn (self $d) => [$d->key => [
                'label' => $d->label,
                'hint' => $d->hint,
                'per_rating' => $d->per_rating,
            ]])
            ->all();
    }
}
