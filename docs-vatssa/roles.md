# Roles and permissions

VATSSA replaces upstream's roles with its own. Roles are granted per area or globally. What each role may do is the matrix in `config/roles.php`.

| Role | In short |
|---|---|
| Administrator | everything; assigned only with the `user:makeadmin` command |
| ATC Training Manager | division training authority; the only role besides admin that grants examiner endorsements and appoints mentors |
| Pipeline Coordinator | day-to-day running of the training pipeline |
| Mentor | training mentor; sees and teaches their own students |
| Navigational Editor | navigational and operationally relevant sector data |
| Feedback Team | reviews controller feedback |
| Event Staff | division-wide booking management and bulk bookings (upstream's role, kept as written) |

**VATSSA permissions**, added to upstream's catalogue:

| Permission | Gives | Held by |
|---|---|---|
| `vatssa.groups.manage` | everything on [groups](groups.md) | admin |

To share a VATSSA permission with another role, add it to that role in the matrix in `config/roles.php` in a pull request, and note it in `PATCHES.md`.

---
*VATSSA Control Center documentation © 2026 Daniël Schoonraad*
