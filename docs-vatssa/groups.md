# Groups

A group is a name and a list of people: the hit squad, an event team, streamers. Groups will be linked to Discord roles on the Discord rules page (Discord sync v2).

## For staff

**Where:** **Groups** in the sidebar. You see it if you have group rights, or if you manage at least one group.

**Who can do what**

| You are | You can |
|---|---|
| Staff with group rights (`vatssa.groups.manage`, admin by default) | everything on every group: create, rename, archive, delete, appoint any manager |
| A group's **settings manager** | rename it, change its description, appoint and remove member managers, add and remove members |
| A group's **member manager** | add and remove members, nothing else |
| Anyone else | nothing |

Managers do not need a staff role. They lose access while their group is archived.

**Adding members.** Paste VATSIM IDs into the box on the group page in any format: one per line, or separated by commas or spaces. Press **Add**. The summary says how many were added, how many were already in the group, and which IDs have never signed in to Control Center. Those people are in the group straight away; they do not need an account first.

**Removing members.** Tick members in the list and press **Remove ticked**, or paste VATSIM IDs into the box and press **Remove these**. Removed members stay in the list, greyed out, with the date.

**Managers.** Enter the person's VATSIM ID and press **Add manager**. They must have signed in to Control Center at least once. Staff can tick **Also manages settings** to make a settings manager.

**Archive and delete.** Staff can archive a group: it leaves the list and its managers' view, and gives no Discord roles. Everything is kept, and **Restore group** brings it back. Only an archived group can be deleted, and you must type its exact name to confirm. Deleting cannot be undone.

**History.** Every change (created, renamed, member added or removed, manager added or removed, archived, restored) is listed under **History** on the group page, with who did it and when.

## How it works

- **Tables:** `vatssa_groups`, `vatssa_group_members`, `vatssa_group_managers`, `vatssa_group_events`. Members and managers are kept by VATSIM ID (`user_id`) with no foreign key to `users`, so people without an account can be members. Rows are never deleted, only given `removed_at`. Deleting a group removes its rows with it.
- **Code:** `App\Models\VatssaGroup` holds the rights (`membersEditableBy`, `settingsEditableBy`, `visibleTo`); `App\Http\Controllers\VatssaGroupController`; views in `resources/views/vatssa/groups/`; routes in `routes/vatssa.php` under `/groups`.
- **Permission:** `vatssa.groups.manage`, in the catalogue in `config/roles.php`. Admin has it through `**`. Grant it to another role in the matrix to share it.
- **Discord:** each member added or removed, and each archive, restore or delete, queues those members for the Discord role sync (`VatssaDiscordSyncController::queueAuto`).
- **Privacy:** a permanently deleted user (`UserDelete`) leaves every group and stops managing any.
- **API**, for any Control Center API key (read-only is enough). Archived groups never appear.
  - `GET /api/vatssa/groups`: active groups, each with `id`, `name`, `description` and `members` (VATSIM IDs).
  - `GET /api/vatssa/members/{cid}`: one VATSIM ID's active groups. Works for an ID with no account.
- **Origin:** ported from the EuroScope plugin package (`vatssa-plugin/controlcenter`), group part only. That package's own group migrations must not be installed beside these.
- **Tests:** `tests/Feature/VatssaGroupsTest.php`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
