# Groups

A group is a name and a list of people: the hit squad, an event team, streamers. Groups will be linked to Discord roles on the Discord rules page (Discord sync v2).

## For staff

**Where:** **Groups** in the sidebar. You see it if you have group rights, or if you manage at least one group.

**Who can do what**

| You are | You can |
|---|---|
| Staff with the **Manage groups** permission (`vatssa.groups.manage`, Administrator by default) | everything on every group: create, rename, archive, delete, appoint either kind of manager |
| A manager with **Members and settings** | rename the group, change its description, appoint and remove "Members only" managers, add and remove members |
| A manager with **Members only** | add and remove members, nothing else |
| Anyone else | nothing |

Managers do not need a staff role. The managers list on each group shows everyone who can manage it: the group's own managers, and the staff who manage every group through the Manage groups permission (those cannot be removed there; their access comes from their role).

**Adding members.** Type or paste VATSIM CIDs into **Add members**, separated by commas, and press **Add to group**. The summary says how many were added, how many were already in the group, and which IDs have never signed in to Control Center. Those people are in the group straight away; they do not need an account first.

**Removing members.** Tick members in the list (or the box at the top to tick all); a **Remove N selected** button appears. Or paste CIDs into **Add members** and press **Remove these CIDs**. The list shows current members only; who was removed, and when, is in **History**.

**Managers.** Under **Add a manager**, enter their VATSIM CID and choose **Members only** or **Members and settings** (a "Members and settings" manager can only appoint "Members only" managers). They must have signed in to Control Center at least once. Staff can change a manager's level with the drop-down next to their name.

**Archive and delete.** Staff press **Archive group** at the top of the page. An archived group gives no Discord roles, disappears from its managers' view, and is **read-only for everyone**, staff included: the forms are greyed out until someone presses **Restore group**. Only an archived group can be deleted: **Delete group…** opens a pop-up where you type the group's name to confirm. Deleting cannot be undone.

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
