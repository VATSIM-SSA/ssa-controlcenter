<?php

namespace App\Models;

use App\Services\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * VATSSA: a named list of people, kept by VATSIM ID (members need no CC account yet).
 *
 * Who may do what:
 *  - vatssa.groups.manage: everything, on every group (create, archive, delete, all managers)
 *  - a settings manager: rename and describe the group, appoint and remove member managers, and the members
 *  - a member manager: add and remove the group's members only
 * An archived group is read-only for everyone, staff included, until it is restored;
 * staff can still see it, its managers cannot.
 */
class VatssaGroup extends Model
{
    /**
     * A VATSIM CID: VATSIM's first was 800000; the highest issued is about 2 770 000
     * (Nov 2026). 3 000 000 leaves room to grow and still catches typos. Raise it when
     * VATSIM gets close.
     */
    public const CID_MIN = 800000;

    public const CID_MAX = 3000000;

    /**
     * VATSIM's development sandbox accounts (auth-dev.vatsim.net), as CC's own seeder uses.
     */
    public const SANDBOX_CIDS = [10000001, 10000011];

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

    public static function isValidCid(string|int $cid): bool
    {
        if (! preg_match('/^[1-9]\d{5,7}$/', (string) $cid)) {
            return false;
        }

        return ((int) $cid >= self::CID_MIN && (int) $cid <= self::CID_MAX)
            || ((int) $cid >= self::SANDBOX_CIDS[0] && (int) $cid <= self::SANDBOX_CIDS[1]);
    }

    public static function isStaff(User $user): bool
    {
        return $user->can('vatssa.groups.manage');
    }

    public function managerFor(User $user): ?VatssaGroupManager
    {
        return $this->currentManagers()->where('user_id', $user->id)->first();
    }

    public function viewableBy(User $user): bool
    {
        return self::isStaff($user) || (! $this->isArchived() && $this->managerFor($user) !== null);
    }

    public function membersEditableBy(User $user): bool
    {
        return ! $this->isArchived() && (self::isStaff($user) || $this->managerFor($user) !== null);
    }

    public function settingsEditableBy(User $user): bool
    {
        return ! $this->isArchived() && (self::isStaff($user) || (bool) $this->managerFor($user)?->can_manage_settings);
    }

    /**
     * Everyone whose roles grant vatssa.groups.manage through the matrix, with those
     * roles' names. They manage every group, so the page lists them as managers.
     *
     * @return Collection<int, array{user: User, roles: string}>
     */
    public static function staffManagers(): Collection
    {
        $roles = app(PermissionMatrix::class)->rolesFor('vatssa.groups.manage');

        return User::whereHas('roleAssignments', fn ($query) => $query->whereIn('role', $roles))
            ->with('roleAssignments')->orderBy('first_name')->get()
            ->map(fn (User $user) => [
                'user' => $user,
                'roles' => $user->roleAssignments->whereIn('role', $roles)->pluck('role')->unique()
                    ->map(fn (string $role) => config("roles.roles.{$role}.name", $role))->implode(', '),
            ]);
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

    /**
     * Erasure: a permanently deleted user leaves every group, stops managing any, and
     * their VATSIM CID is removed from every group's history and "by" fields.
     */
    public static function forgetUser(int $cid): void
    {
        VatssaGroupMember::where('user_id', $cid)->delete();
        VatssaGroupManager::where('user_id', $cid)->delete();
        VatssaGroupEvent::where('user_id', $cid)->update(['user_id' => null]);
        VatssaGroupEvent::where('by', $cid)->update(['by' => null]);
        foreach ([VatssaGroupMember::class, VatssaGroupManager::class] as $model) {
            $model::where('added_by', $cid)->update(['added_by' => null]);
            $model::where('removed_by', $cid)->update(['removed_by' => null]);
        }
        static::where('created_by', $cid)->update(['created_by' => null]);
        static::where('archived_by', $cid)->update(['archived_by' => null]);
    }

    public function record(string $event, ?int $userId = null, ?string $detail = null, ?int $by = null): void
    {
        $this->events()->create(['event' => $event, 'user_id' => $userId, 'detail' => $detail, 'by' => $by]);
    }
}
