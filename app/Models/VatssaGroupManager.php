<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * VATSSA: one manager of a group. can_manage_settings: also rename it and appoint member managers. Never deleted, only marked removed.
 */
class VatssaGroupManager extends Model
{
    protected $table = 'vatssa_group_managers';

    protected $fillable = ['group_id', 'user_id', 'can_manage_settings', 'added_by', 'removed_at', 'removed_by'];

    protected $casts = ['removed_at' => 'datetime', 'can_manage_settings' => 'boolean'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(VatssaGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
