<?php

namespace App\Models\Vatssa;

use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: a staff note about a person or a training.
 *
 * For the things that have to be written down and must not be visible to the
 * person they are about: disciplinary history, why somebody was removed or
 * refused, a complaint, the awkward context behind a decision.
 *
 * Two scopes with two audiences, and the difference matters:
 *
 *   TRAINING  about this training. ATC training manager and admin.
 *   USER      about the person, across every training. Admin only.
 *
 * A user note outlives the training that prompted it. That is the point --
 * closing a training must not erase the reason it was closed.
 *
 * ## Every note states its own audience
 *
 * `audience()` says who can read one, and the panel header carries it. Somebody
 * writing something sensitive has to know exactly who will see it: a note
 * written in the belief it was admin-only, readable by a manager, is worse than
 * no note at all.
 */
class InternalNote extends Model
{
    protected $table = 'vatssa_internal_notes';

    protected $fillable = ['scope', 'user_id', 'training_id', 'body', 'author_id'];

    public const SCOPE_TRAINING = 'training';

    public const SCOPE_USER = 'user';

    /**
     * Who may read each scope, as a permission and as a short label.
     *
     * The label sits in the panel header. There is no long form any more: the
     * full sentence was repeated above every note and under every form, and
     * said nothing the header does not.
     */
    public const SCOPES = [
        self::SCOPE_TRAINING => [
            'permission' => 'training.notes.view',
            'label' => 'Training note',
            'audience_short' => 'ATC training manager, admins',
        ],
        self::SCOPE_USER => [
            'permission' => 'users.notes.view',
            'label' => 'Member note',
            'audience_short' => 'Admins only',
        ],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public static function permissionFor(string $scope): string
    {
        // Default to the narrower of the two. An unknown scope reaching this
        // should be unreadable rather than readable by the wider audience.
        return self::SCOPES[$scope]['permission'] ?? self::SCOPES[self::SCOPE_USER]['permission'];
    }

    public static function audienceFor(string $scope): string
    {
        return self::SCOPES[$scope]['audience_short'] ?? 'Admins only';
    }

    public function audience(): string
    {
        return self::audienceFor($this->scope);
    }
}
