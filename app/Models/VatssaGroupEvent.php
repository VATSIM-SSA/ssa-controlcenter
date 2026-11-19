<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one line of a group's history (created, renamed, member added, manager removed, archived, ...).
 */
class VatssaGroupEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'vatssa_group_events';

    protected $fillable = ['group_id', 'event', 'user_id', 'detail', 'by'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VatssaGroup::class, 'group_id');
    }
}
