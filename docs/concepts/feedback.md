---
icon: material/comment-text-outline
---

# Feedback

Control Center includes an internal feedback channel for members to report on ATC sessions, plus a staff report where moderators and above can review — and, when permitted, correct — how feedback is correlated to controllers and positions.

This is separate from the **feedback URL** on an [area](../setup/division.md) row, which is an external link (for example a Google Form) included in training completion emails. The features on this page all live inside Control Center.

## Enabling feedback

Feedback is controlled under **Administration → Settings → Feedback**:

| Setting | Purpose |
| --- | --- |
| **Enable feedback functionality** | When off, members cannot open the submit form and are redirected away. |
| **Forward feedback to e-mail** | Optional. Sends a copy of each new submission to the configured address. |
| **Discord webhook for staff** | Optional. Posts a message for every new submission to a staff channel and keeps it updated as staff review it. The feedback text itself is not sent; the message links to it. |
| **Discord webhook for shared feedback** | Optional. When set, staff can post feedback they send to a controller in a (public) channel, without the submitter's name. |

The settings are stored in the application settings store (`feedbackEnabled`, `feedbackForwardEmail`, `feedbackStaffDiscordWebhook`, `feedbackDiscordWebhook`). Treat each webhook URL as a secret: anyone who has it can post to the channel. Use a staff-only channel for the staff webhook.

## Submitting feedback

Any signed-in member can submit feedback when the feature is enabled. With more than one area, the member first picks the area the feedback is about. The form then collects:

- **Controller** (required). A search over everyone on any area's roster: active controllers and holders of a visiting endorsement. Controllers online right now in the chosen area are listed first.
- **Their session** (optional). The controller's sessions from the last 14 days, from the statistics API. Picking one fills in the position.
- **Their position** (optional). Callsign of the position controlled.
- **Pilot callsign**, **Date of event**, **Departure airport** and **Arrival airport** (optional). Members with recent flights on the network can pick one from a list, which fills these in. The list comes from the public VATSIM API; when it is unavailable, the fields are filled in by hand.
- **Feedback text** (required).

Members cannot give feedback about themselves. After submitting, the member does not see the entry again: it goes to staff.

## Viewing feedback

Staff with the right permissions can open **Reports → Feedback** (`/reports/feedback`). The table shows open entries by default; a status filter shows closed ones or all. Each row links to the entry's own page (**Review** while open, **View** once closed). Long feedback is cut short in the table; the entry's page shows it in full.

Entries fall into two categories:

| Type | Meaning |
| --- | --- |
| **Correlated** | Linked to a position or an area. The area is the explicit one, or else the position's. |
| **Uncorrelated** | Neither linked. Shown with area **N/A**. |

Which rows a user sees depends on **view** permissions and area scope (see below).

A member's profile lists their feedback. Staff see, within their feedback scope, every entry the member **received** and every entry they **submitted**, with its status (text cut short, full text on the entry's page). On your own profile, **Feedback about you** lists everything staff sent you, from any area, in full and without the submitter's name. Staff also see **received** and **submitted** on their own profile, limited to their feedback scope like on any other profile. Members without feedback permissions see only what was sent to them and never see their own submissions again.

### The staff Discord channel

With the staff webhook set, every new submission posts one message to the staff channel: the review state as headings at the top (for example **🟡 Open** and **🏷️ Not tagged · 📭 Nothing sent**), then a card with the controller, position, area, submitter, flight, the latest action, and a link to the entry. The feedback text itself is never sent to Discord; staff read it in Control Center. The message is edited, not reposted, whenever the review changes: closing, reopening, tagging, editing, sending, withdrawing, acknowledging or commenting. Several changes in one request make one edit. If the message was deleted in Discord, the next change posts a new one.

## Reviewing feedback

Each entry has a page at `/feedback/{id}` with the submitter's original text, its details, and two separate controls: the **review** and **what the controller sees**.

### Review

- **Close review** marks the entry as dealt with. **Reopen review** puts it back in the queue, for example to discuss it again. Neither sends nor withdraws anything.
- **Tone** tags the entry **Positive** or **Negative** at any time.
- **Internal comments** can be added by any staff member who may review it, whether it is open or closed. They are never shown to the controller or the submitter.
- **Edit details** corrects the controller, position, area, session and flight details. The submitter and their text cannot be edited. The controller cannot be changed while they hold a sent version.

### What the controller sees

Nothing reaches the controller unless staff send it. **Send to [controller]** works whether the review is open or closed, and offers:

- **The feedback as written**, without the submitter's name, or
- **A rewritten version**, for example a respectful wording of a rude comment. It stays linked to the original.

Sending e-mails the controller and puts a notice on their dashboard until they **acknowledge** it. Staff see whether and when they did. When a Discord webhook is set, staff can also post the sent version to Discord; the submitter is never named.

A controller holds at most one version at a time. **Withdraw** takes it off their profile and dashboard and deletes the Discord post. The e-mail they already received cannot be recalled. To send a different version, withdraw and send again.

### Timeline and activity log

The entry's timeline records every comment, tag, edit, closing, reopening, sending, withdrawal and acknowledgement, with who did it and when. Timeline entries are never edited or deleted.

Changes to the controller, position or area are also written to the activity log with category `feedback`, for example:

> Updated feedback 42 ― Controller: Jane Doe (123456) → N/A, Position: ESGG_APP → N/A

## Permissions

Feedback uses the [roles and permissions](permissions.md) system. Three permissions apply:

| Permission | Purpose |
| --- | --- |
| `feedback.correlated.view` | View feedback linked to a position. Scoped to the position's area unless the assignment is global. |
| `feedback.uncorrelated.view` | View feedback with no position linked. |
| `feedback.update` | Edit the controller and position fields on an entry. |

These permissions are defined in the catalogue in `config/roles.php`. They are granted to roles through the **matrix**, not assigned to users directly.

### Default role access

| Role | View correlated | View uncorrelated | Update |
| --- | --- | --- | --- |
| `admin` | Yes (all areas) | Yes | Yes (all entries) |
| `director` | Yes (all areas) | Yes | Yes (all entries) |
| `moderator` | Yes, in assigned area(s) | Yes | Yes, in assigned area(s) + uncorrelated |
| `mentor` | No | No | No |
| `buddy` | No | No | No |
| `nav-editor` | No | No | No |

Moderators receive all three permissions through the `feedback.**` pattern in the default matrix. Directors and administrators receive them through `**` (with the usual system-level exclusions for directors).

### How update checks resolve

Authorisation is enforced in `FeedbackPolicy`, the `UpdateFeedbackRequest` and `SendFeedbackRequest` form requests, and the feedback page. The same rule governs editing and reviewing (tone, comments, closing, sending, withdrawing).

1. **Correlated feedback** (position is set)  
   The user must hold `feedback.update` for the **area of the linked position**. An area moderator in Area A can edit feedback tied to positions in Area A, but not Area B.

2. **Uncorrelated feedback** (no position)  
   The user must hold `feedback.update` **and** have access to `feedback.uncorrelated.view` (any area assignment that grants it is enough).

3. **Global assignments**  
   A global `admin`, `director`, or `moderator` assignment can update correlated feedback in any area and uncorrelated feedback.

Mentors, buddies, and members without a staff role cannot update feedback even if they can submit it.

### Customising access

To change who may view or edit feedback, edit `config/roles.php`:

1. Ensure the permission exists in the `permissions` catalogue.
2. Grant it to roles via patterns in the `matrix` block (for example add `feedback.update` to a custom role, or remove `feedback.**` from `moderator`).
3. Run `php artisan optimize:clear` so the new mapping is loaded.

See the [Roles and Permissions Reference](../reference/permissions.md) for the full matrix and customisation steps.

## Development and testing

After `migrate:fresh --seed` in a [development environment](../contribute.md), these seeded users can update feedback:

| User | CID | Role | Can update |
| --- | --- | --- | --- |
| Team Web | `10000010` | Admin (global) | All entries |
| Web Nine | `10000009` | Director (area 1) | All entries |
| Web Eight | `10000008` | Moderator (area 1) | Area 1 + uncorrelated |
| Web Six / Seven | `10000006` / `10000007` | Mentor | No |

Log in as one of the staff users above and open **Reports → Feedback** to exercise the review flow.
