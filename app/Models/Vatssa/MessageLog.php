<?php

namespace App\Models\Vatssa;

use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one email a student received, from whichever system sent it.
 *
 * Subject and kind only. The body never lands here: the log answers "what was
 * this student told, and when", and the email itself is the detail. CPT marks
 * in particular must not appear.
 *
 * The value is the awkward conversation. When a student says they were never
 * told, the answer is a list with dates rather than somebody's memory.
 */
class MessageLog extends Model
{
    protected $table = 'vatssa_message_log';

    protected $fillable = [
        'user_id', 'training_id', 'subject', 'kind', 'source', 'message_id', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function sentByBot(): bool
    {
        return $this->source === 'bot';
    }

    /**
     * A label for the kind, for the panel. Unknown kinds are shown as-is
     * rather than hidden: a row nobody classified still happened.
     */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            'interest' => 'Interest confirmation',
            'closed' => 'Training closed',
            'report' => 'Training report',
            'endorsement' => 'Endorsement',
            'examination' => 'Examination',
            'other' => 'Email',
            default => ucfirst(str_replace('-', ' ', $this->kind)),
        };
    }
}
