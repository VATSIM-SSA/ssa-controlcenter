<?php

namespace App\Models\Vatssa;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one person's answer to an availability poll.
 *
 * `slots` is a list of ISO UTC start times. See the migration for why it is a
 * list rather than a row each.
 */
class AvailabilityResponse extends Model
{
    protected $table = 'vatssa_availability_responses';

    protected $fillable = ['poll_id', 'user_id', 'slots', 'role'];

    protected $casts = ['slots' => 'array'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(AvailabilityPoll::class, 'poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
