<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one member of a group, by VATSIM ID (user_id may have no CC account yet). Never deleted, only marked removed.
 */
class VatssaGroupMember extends Model
{
    protected $table = 'vatssa_group_members';

    protected $fillable = ['group_id', 'user_id', 'added_by', 'removed_at', 'removed_by'];

    protected $casts = ['removed_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VatssaGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
