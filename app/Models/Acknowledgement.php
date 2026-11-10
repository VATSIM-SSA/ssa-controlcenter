<?php

namespace App\Models;

use App\Contracts\RequiresAcknowledgement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A notice a member must confirm they have read. It stays on their dashboard
 * until they do. The subject is any model implementing RequiresAcknowledgement.
 */
class Acknowledgement extends Model
{
    protected $fillable = ['user_id', 'subject_type', 'subject_id', 'acknowledged_at'];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    /**
     * Ask the user to acknowledge the subject. An earlier pending request for
     * the same subject is replaced, so the dashboard shows the latest only.
     */
    public static function request(User $user, RequiresAcknowledgement&Model $subject): self
    {
        static::query()
            ->where('user_id', $user->id)
            ->whereMorphedTo('subject', $subject)
            ->whereNull('acknowledged_at')
            ->delete();

        return static::create([
            'user_id' => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('acknowledged_at');
    }

    public function isPending(): bool
    {
        return $this->acknowledged_at === null;
    }

    public function acknowledge(): void
    {
        if (! $this->isPending()) {
            return;
        }

        $this->update(['acknowledged_at' => now()]);

        if ($this->subject instanceof RequiresAcknowledgement) {
            $this->subject->acknowledged($this);
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
