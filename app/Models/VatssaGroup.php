<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * VATSSA: a named list of people, kept by VATSIM ID (members need no CC account yet).
 *
 * Who may do what:
 *  - vatssa.groups.manage: everything, on every group (create, archive, delete, all managers)
 *  - a settings manager: rename and describe the group, appoint and remove member managers, and the members
 *  - a member manager: add and remove the group's members only
 * Managers lose access while the group is archived.
 */
class VatssaGroup extends Model
{
    protected $table = 'vatssa_groups';

    protected $fillable = ['name', 'description', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(VatssaGroupMember::class, 'group_id');
    }

    public function currentMembers(): HasMany
    {
        return $this->members()->whereNull('removed_at');
    }

    public function managers(): HasMany
    {
        return $this->hasMany(VatssaGroupManager::class, 'group_id');
    }

    public function currentManagers(): HasMany
    {
        return $this->managers()->whereNull('removed_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VatssaGroupEvent::class, 'group_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public static function isStaff(User $user): bool
    {
        return $user->can('vatssa.groups.manage');
    }

    public function managerFor(User $user): ?VatssaGroupManager
    {
        return $this->currentManagers()->where('user_id', $user->id)->first();
    }

    public function membersEditableBy(User $user): bool
    {
        return self::isStaff($user) || (! $this->isArchived() && $this->managerFor($user) !== null);
    }

    public function settingsEditableBy(User $user): bool
    {
        return self::isStaff($user) || (! $this->isArchived() && (bool) $this->managerFor($user)?->can_manage_settings);
    }

    /**
     * Every group for staff; otherwise the unarchived groups someone manages.
     */
    public static function visibleTo(User $user): Builder
    {
        return self::isStaff($user)
            ? static::query()
            : static::whereNull('archived_at')->whereIn('id', VatssaGroupManager::where('user_id', $user->id)->whereNull('removed_at')->select('group_id'));
    }

    public static function anyManagedBy(User $user): bool
    {
        return self::visibleTo($user)->whereNull('archived_at')->exists();
    }

    public function record(string $event, ?int $userId = null, ?string $detail = null, ?int $by = null): void
    {
        $this->events()->create(['event' => $event, 'user_id' => $userId, 'detail' => $detail, 'by' => $by]);
    }
}
