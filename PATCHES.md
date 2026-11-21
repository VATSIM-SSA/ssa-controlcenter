# Patches

Every way this fork differs from `Vatsim-Scandinavia/controlcenter`, and how you
find out when upstream breaks one.

Current upstream: see `.upstream-version`.

## The rule

**Prefer an added file over a modified one.** Every file modified in place is a
conflict on some future release. Every file added is free forever, because
upstream will never touch a path it does not know about.

New config goes in a VATSSA-owned file. New migrations go in
`database/migrations-vatssa/`. New commands, services and views get
VATSSA-specific names.

The cost of that preference is that git stops telling you when upstream moves
underneath an added file. So each section below names its own detector, and
**every entry must have one**.

---

## Modified upstream files

Nineteen, but they are two different things and should be read as such.

**Nine are code and configuration.** These are the real divergence. Each one is
a conflict on some future release, each needs judgement to resolve, and this
list growing is the thing to resist.

**Ten are replaced brand assets** — the favicon set and the header mark. Binary
files upstream rarely touches, and a conflict on any of them is resolved by
keeping ours without thinking. They are listed for completeness, not because
they carry risk.

| | Count |
|---|---|
| Code and configuration | 9 |
| Brand assets | 10 |
| Added files | 67 |
| Force-added past `.gitignore` | 3 |

### `config/roles.php`

**Also (2026-11-19):** one VATSSA permission in the catalogue, `vatssa.groups.manage`
(groups; admin holds it through `**`). Listed, so `AuthServiceProvider` makes it a gate.

**Why it diverges.** VATSSA runs its own roles (`admin`, `atc-training-manager`,
`pipeline-coordinator`, `mentor`, `nav-editor`, `feedback-team`) in place of
upstream's, with its own permission matrix. One upstream role is kept exactly as
upstream wrote it: `event-staff` (v7.3.0), with upstream's two booking grants and
grantable by admin only. The permission catalogue is
upstream's verbatim except for the `roles.*.manage` entries, which are renamed to
match the VATSSA role keys.

**Detector:** a git merge conflict at absorption, plus
`tests/Feature/VatssaTest.php` and upstream's own `tests/Unit/RolesConfigTest.php`
in CI.

**What to check when it conflicts.**

1. Did upstream add or remove a **permission**? Add or drop it in the catalogue
   to match, then decide which VATSSA roles hold it. Never invent a permission:
   one that is not in the catalogue does not exist.
2. Did upstream add or remove a **role**? VATSSA does not adopt upstream's roles,
   but a new one usually signals a new capability worth having.
3. Did upstream change `roles.<role>.manage`, `scope` or `grant_scope` semantics?
   `UserPolicy::updateRole` builds `"roles.{$requestedRole}.manage"` from the role
   key. A rename on one side and not the other means nobody can grant anything,
   silently.
4. Re-run `python tools/expand.py config/roles.php` and
   `php artisan test --filter=VatssaTest`. Do not resolve the conflict without
   both passing.


### `Dockerfile`

**Why it diverges.** One added build arg, `INSTALL_DEV`, and a conditional
around upstream's `composer install`. `php artisan db:seed` needs
`fakerphp/faker`, which lives in `require-dev`, so a `--no-dev` image cannot
seed. Dev and staging build with `true`; production builds with `false` and must
never ship phpunit, faker, debugbar or boost.

A **build arg, never an env var**, so it cannot be flipped by editing a `.env` on
the box.

**Detector:** a git merge conflict at absorption, plus the guard step in
`.github/workflows/deploy.yml` that fails a production build carrying dev
dependencies.

**What to check when it conflicts.** Upstream restructuring its build stages is
the likely cause. Reapply the arg to whichever stage ends up producing the
shipped `vendor/`, and confirm with:

```
docker run --rm ghcr.io/vatsim-ssa/ssa-controlcenter:prod ls vendor/bin
```

`phpunit` must not be there.

**In flight upstream:** `upstream-contrib/install-dev-arg`. If it lands, delete
this entry and take upstream's version.

### `config/app.php`

**Why it diverges.** Two lines: the import and the `providers` entry for
`App\Providers\VatssaServiceProvider`. That provider carries the fork's
behaviour changes so the upstream files they would otherwise touch stay clean.

**Detector:** a git merge conflict at absorption. If the provider entry is lost,
`VatssaTheoryCompletedTest` fails (route missing, pre-training button back).

**What to check when it conflicts.** Upstream moving to `bootstrap/providers.php`
is the likely cause. Register the provider there instead.

### Theory mark: `training/show.blade.php`, `training/index.blade.php`, `reports/activities.blade.php`

**Why they diverge.** Staff mark a training's theory as passed (2026-11-11).
The logic lives in added files; the views carry only what a view has to:

- `show.blade.php`: `@include('vatssa.training.theory-button')` beside the
  title buttons, and `@include('vatssa.training.theory-badge')` in place of the
  pre-training tick next to the state.
- `index.blade.php` (Open requests): the badge include, in place of the tick.
  And (2026-11-12) the State column's hover note lists staff comments only when
  the viewer passes `TrainingActivityPolicy::view` for COMMENT. Upstream showed
  every comment to anyone the row is listed for, which includes a staff member
  looking at their own training as a student. An upstream bug; candidate for an
  upstream contribution.
- `show.blade.php` and `reports/activities.blade.php`: the activity feed's
  `PRETRAINING` branches also render `THEORY`, icon and text. Two lines each.

**Detector:** `tests/Feature/VatssaTheoryCompletedTest.php` asserts the button,
the badge on Open requests and the missing pre-training button;
`VatssaTrainingAttentionTest::the_open_requests_note_hides_staff_comments_from_the_student`
asserts the hover-note guard.

**What to check when it conflicts.** Re-add the includes wherever upstream now
renders the state and the title buttons. If upstream reworks the activity feed,
keep `THEORY` rendering like `PRETRAINING`.

### Discord sync button: `user/show.blade.php`

**Why it diverges.** The profile page has no slot to plug into. One line,
`@include('vatssa.user.discord-sync')`, at the bottom of the profile card
(2026-11-18).

**Detector:** `tests/Feature/VatssaDiscordSyncTest.php` asserts the button shows
to staff and not to a plain member.

**What to check when it conflicts.** Re-add the include inside whichever card
now shows the VATSIM ID and name.

---

### Brand assets (10 files)

```
public/images/control-tower.svg          the header mark, inlined by front.blade.php
public/favicon.ico
public/images/favicon/favicon.ico
public/images/favicon/favicon-16x16.png
public/images/favicon/favicon-32x32.png
public/images/favicon/apple-touch-icon.png
public/images/favicon/android-chrome-192x192.png
public/images/favicon/android-chrome-512x512.png
public/images/favicon/mstile-150x150.png
public/images/favicon/safari-pinned-tab.svg
```

All generated from `logo_icon.png` in the `ssa-palette` repo. Regenerate with
the script in that repo rather than by hand.

**On conflict: keep ours.** There is no merging a binary brand asset.

Two choices worth not undoing:

- `apple-touch-icon.png` and `mstile-150x150.png` are **flattened onto
  `#07262C`**. iOS ignores alpha on the touch icon and fills transparent pixels
  with black, which would put a black square behind the mark.
- `favicon.ico` carries **seven sizes**, 16 through 256, so each surface picks a
  properly-scaled version instead of resampling one.

**`control-tower.svg` has a footgun.** `front.blade.php` inlines it with
`file_get_contents`, so its `width`/`height` attributes go straight into the
flex layout. The 1024x1024 mark broke the login page on 2026-08-26 until
`_custom.scss` constrained it. Any replacement needs the same treatment, or the
attributes stripped.

---

## Added files

These never conflict. That is the point, and it is also the risk, so each one
names what it leans on and what would tell you it broke.

### `database/migrations-vatssa/2026_08_26_100000_vatssa_reference_data.php`

Areas and the 401 VATSSA positions. Reference data, so it is a migration rather
than a seeder: it must run in every environment, production included.
Idempotent, never destructive, and never overwrites `area_id` on an existing row.

**Leans on:** the `areas` and `positions` table shapes.
**Detector:** `VatssaTest::the_reference_migration_loads_areas_and_positions` and
`::the_reference_migration_is_idempotent`.

### `database/migrations-vatssa/2026_07_17_120000_remap_moderator_buddy_roles.php`

Remaps legacy `moderator` and `buddy` rows onto the VATSSA roles at cutover.

**Leans on:** the `role_user` table shape.
**Detector:** `VatssaTest::the_seeder_runs_and_assigns_the_expected_roles`
asserts no `role_user` row references an unresolvable role.

### `database/seeders/VatssaSeeder.php`

Dev and staging fixtures. Guarded against `APP_ENV=production`.

**Leans on:** `User`, `Training`, `TrainingReport`, `TrainingExamination`,
`Feedback`, `Endorsement`, `Rating`, `Position`, their factories,
`FactoryHelper`, `TrainingStatus`, `VatsimRating`, and the `role_user` shape.
This is the widest dependency surface in the fork, which is why it is the file
most worth testing.

**Detector:** `VatssaTest::the_seeder_runs_and_assigns_the_expected_roles`, which
runs it end to end.

**On absorption:** diff those upstream paths between the old and the new tag. If
any moved, read the seeder before trusting the test.

### `app/Providers/VatssaServiceProvider.php`, `routes/vatssa.php`

Fork behaviour without editing upstream files:

- registers the `THEORY` activity type (`TrainingActivityController::$activityTypes`)
- turns off upstream's student "Mark pre-training as completed" with a
  `Gate::before` on `togglePreTrainingCompleted`, admins included
- defines `toggleTheoryCompleted`: `training.update` in the training's area, open
  trainings only
- loads `routes/vatssa.php` with the authenticated web middleware
  (`web`, `auth`, `activity`, `suspended`)
- hides the **Theoretical Exam Access** request type from the training page's
  Request menu (view composer on `training.show`) and refuses to create one
  (`Task::creating`). The class stays so older tasks of that type still render.
  VATSSA's theory exam runs in Moodle.

**Leans on:** the public static `$activityTypes`, the ability name
`togglePreTrainingCompleted`, the `requestTypes` variable passed to
`training.show`, the `App\Tasks\Types\TheoreticalExam` class name, the middleware aliases in `routes/web.php`, and
`TrainingStatus::isOpen()`.
**Detector:** `VatssaTheoryCompletedTest`, `VatssaNoTheoryExamTaskTest`.

### `app/Http/Controllers/VatssaTheoryController.php`, `resources/views/vatssa/training/*`

The theory mark itself. The form posts the state it wants (`completed` 0/1),
so a double click or a stale page cannot flip it back.
**Detector:** `VatssaTheoryCompletedTest`.

### Training flags (Requires attention, Stalled): `app/Services/VatssaTrainingFlags.php`, `app/Http/Controllers/VatssaTrainingFlagController.php`, `resources/views/vatssa/training/flag-*`, `database/migrations/2026_11_12_120000_vatssa_add_stalled_at_to_trainings.php`, `database/migrations/2026_11_12_130000_vatssa_add_attention_at_to_trainings.php`

Staff set or clear two flags on a training (2026-11-12), beside the theory mark:
**Requires attention** (amber) and **Stalled** (red). Same people as the theory
mark (`toggleTrainingFlags`: `training.update` in the training's area, open
trainings only). `trainings.attention_at` and `trainings.stalled_at` hold when
each was set (null = not set); migrations on the default path for the same reason
as the theory one. Add a flag by adding a column and an entry to
`VatssaTrainingFlags::FLAGS`.

All the work is in `VatssaTrainingFlags` (review fixes, #28 and after):
- a change takes a row lock and re-reads the training, so two clicks give one
  change, and a training closed meanwhile is refused;
- each flag form carries the training's newest timeline entry id
  (`version()`); a change from an older page is refused ("reload and try
  again") instead of undoing someone else's later change;
- the change, its activity-log entry and its timeline comment are one
  transaction, so a flag never changes without its history;
- when a training's status crosses between open and closed
  (`Training::saved` hook in `VatssaServiceProvider`), flags made stale are
  cleared from the database row with a timeline entry each: on close every
  flag, on reopen only flags set before the reopen (a close whose own clearing
  failed leaves them). It re-checks the status under the lock and does nothing
  if the status has moved on since. A failure is reported, never thrown. The
  theory mark is kept.

**Upstream files:** none for the flags themselves. The buttons and badges are
included from the VATSSA theory partials, which upstream views already include;
history is a staff COMMENT, which upstream renders and hides from students; the
badge words are drawn by CSS from `data-label` (`.vatssa-flag` in
`_custom.scss`) so the Open requests State filter lists only the status. The Open
requests column rules (State width, ATC Hours, one-line rows) are scoped to
`table[data-cookie-id-table="trainings"]`, because My students, Closed requests
and Member overview reuse the same `data-field` names. The
hover-note guard in `training/index.blade.php` is listed under *Theory mark*.

**Leans on:** the theory include points in `training/show.blade.php` and
`training/index.blade.php`, `TrainingActivityController::create` with type
`COMMENT`, `TrainingActivityPolicy::view` hiding COMMENT from students, the
`status` enum cast on `Training`, upstream closing trainings through Eloquent
(`updateStatus` / `update`), not a query-builder update.
**Detector:** `tests/Feature/VatssaTrainingStalledTest.php`,
`tests/Feature/VatssaTrainingAttentionTest.php` (including a guard that the
`.vatssa-flag` CSS rule exists).

### Groups: `app/Models/VatssaGroup*.php` (4), `app/Http/Controllers/VatssaGroupController.php`, `resources/views/vatssa/groups/*` (2), `resources/views/vatssa/sidebar/groups.blade.php`, `database/migrations/2026_11_19_100000_vatssa_create_groups.php`, `tests/Feature/VatssaGroupsTest.php`

Groups at `/groups` (2026-11-19): named lists of people kept by VATSIM ID, so
members need no CC account yet. Ported from the EuroScope plugin package
(`vatssa-plugin`, not in git), group part only; the plugin's own group migrations
must not be installed beside these. Rights: `vatssa.groups.manage` for everything;
a group's settings managers rename it and appoint member managers; member managers
add and remove members. Bulk add and remove (paste VATSIM IDs, or tick), archive,
then delete with the name typed. Members and managers are only marked removed; every
change is a `vatssa_group_events` line. Each membership change queues that member for
the Discord role sync (model listeners). A deleted user leaves every group and is
removed from every history and "by" field. Names are unique; member changes run under
a per-group cache lock; CIDs must be 800000 to 3000000 or a VATSIM sandbox account. API for any CC key:
`GET /api/vatssa/groups`, `GET /api/vatssa/members/{cid}`.
**Leans on:** the permission catalogue becoming gates, `x-sidebar.item`,
`ActivityLogService`, `User::name`.
**Detector:** `tests/Feature/VatssaGroupsTest.php`.

### Discord sync button: `app/Http/Controllers/VatssaDiscordSyncController.php`, `app/Http/Middleware/VatssaDiscordBotToken.php`, `config/vatssa.php`, `routes/vatssa-api.php`, `resources/views/vatssa/user/discord-sync.blade.php`, `database/migrations/2026_11_18_120000_vatssa_create_discord_sync_requests.php`, `database/migrations/2026_11_18_130000_vatssa_create_discord_presence.php`

"Sync Discord roles" on a user's profile (2026-11-18) asks the role sync bot
(`ssa-discord-bot`) to re-sync that member now instead of at its next 15-minute
sweep. Gate `vatssaDiscordSync` in `VatssaServiceProvider`: `users.manage`
(admin, ATM, Pipeline Coordinator) for anyone; a mentor only for members they teach.

**CC never calls the bot.** The button writes a row to
`vatssa_discord_sync_requests` (CID, who, when). A press is skipped only while an unfinished button
request for that member from the last minute exists, under a per-member lock. The bot polls `GET /api/vatssa/discord-sync-requests?after=<last id>`
with its own bot token (a CC API key is refused); the answer carries `max_id` so the bot notices a
restore. Rows older than a day are not served. The bot reports each outcome to
`POST /api/vatssa/discord-sync-requests/{id}/result` with `VATSSA_DISCORD_BOT_TOKEN`
(`config/vatssa.php`), checked by `VatssaDiscordBotToken`. That token is not a CC
API key and opens nothing else; unset, the route refuses everyone. The profile
shows the last request's status under the button. Each press deletes requests
older than 30 days.

**Instant sync.** `VatssaServiceProvider` also listens to `Endorsement`, `RoleAssignment`,
`Training` (create, delete, `status` change), `AtcActivity` (create, delete, `atc_active`
change) and `User` (`rating` or `division` change) and calls
`VatssaDiscordSyncController::queueAuto()`, which writes a `source = auto` row and
never throws. Bulk query updates fire no events and are left to the bot's sweep.
**Leans on:** those models' Eloquent events and `user_id` columns, `User::isTeaching`,
`UserDelete` setting `void@void.void` when pseudonymising. Automatic rows wait
5 s before the feed serves them (ratings and areas are attached after the save).

**Limits.** The API routes file runs under `throttle:600,1,vatssa-discord-bot` and
`bindings`, not the `api` group, whose 60/min counter is shared with `/api/users`.
The button: `throttle:30,1,vatssa-discord-button`. The bot token must be 32+
characters or the routes refuse everyone. Pruning never deletes the newest row.
**Leans on:** the `bindings` middleware alias and `ThrottleRequests`' prefix argument.

**Discord presence.** After each sweep the bot posts every server member it can
link to a CID to `POST /api/vatssa/discord-presence` (bot token). A complete list
replaces `vatssa_discord_presence` and sets the setting `vatssaDiscordPresenceAt`;
an incomplete one (failed lookups, a join, a button press) only adds. Lists carry
`at`; an older complete list gets 409. Deleted or pseudonymised members are never
stored and are removed on delete or pseudonymise. The profile shows "On
the VATSSA Discord as @name", "Not on the VATSSA Discord", or "Unknown" when no
complete list arrived in the last two hours. Staff only (same gate). This stores
Discord ID against CID in CC: privacy amendment flagged for review in January 2027.

The routes file is loaded by `VatssaServiceProvider` under
`/api` with `throttle:600,1,vatssa-discord-bot` and `bindings`, not the `api`
group; every route needs the bot token.

**Leans on:** the `user.show` route name, `User::hasPermission`, `UserPolicy::view`
letting a mentor open only members they teach.
**Detector:** `tests/Feature/VatssaDiscordSyncTest.php`.

### `database/migrations/2026_11_11_120000_vatssa_add_theory_completed_to_trainings.php`

Adds `trainings.theory_completed`. **Deliberately on the default path, not
`database/migrations-vatssa/`**: `RefreshDatabase` runs only the default path, so
a column added from `migrations-vatssa/` does not exist in tests (the same reason
the reference-data tests fail). The `vatssa_` prefix and the date keep it clear
of upstream's names.
**Detector:** `VatssaTheoryCompletedTest`.

### `.github/workflows/upstream-check.yml`

Daily at 06:00 UTC, compares upstream's latest release with `.upstream-version`
and posts to Discord when they differ. Needs the `DISCORD_WEBHOOK` repo secret.
Runs only from `main`. **Bump `.upstream-version` in every absorption**, or it
pings daily.

### `tests/Feature/VatssaTest.php`

The detector for everything above. Do not delete it to make a bump go green.

**The one to watch:** `atc_training_manager_may_grant_mentor_and_nothing_else`.
v7.0.0 made grant authority pure config, which is what let the `UserPolicy`
override be deleted. If a future release moves it back into code, this test fails
and the override has to come back.

### `resources/sass/themes/_custom.scss`

VATSSA colours. Upstream imports this path unconditionally from `app.scss`,
gitignores it, and `vite.config.js` writes an empty stub only when it is missing,
never over an existing one. Force-added (`git add -f`) so it reaches the Docker
build. **Do not edit `.gitignore` to avoid the force-add** — an unmodified
upstream file cannot conflict, and a gitignored path is one upstream will not
touch either way.

**Leans on:** the CSS custom property names in `resources/sass/themes/_light.scss`
and `_dark.scss`. Upstream renaming a token would leave that token at its default
with no error anywhere.

**Detector:** `python tools/contrast-check.py resources/sass/themes/_custom.scss`,
which also carries upstream's defaults and so fails when a name it expects has
gone. Run it on every absorption that touches `resources/sass/`.

### `tools/expand.py`, `tools/contrast-check.py`

The two validators. Both parse the file they check rather than duplicating it.

### `public/images/logos/vatssa*`

Logos. Selected by `APP_LOGO` and `APP_LOGO_MAIL`, upstream's own mechanism
(`docs/setup/logo.md`). No code involved.

**Force-added.** `.gitignore` line 23 is `public/images/logos/*`, so the whole
directory is ignored and upstream force-adds its own `vatsca.*` the same way.
Second force-add in the fork, after `_custom.scss`. As there, do not edit
`.gitignore` to avoid it.

### `deploy/**` and `.github/workflows/deploy.yml`

Three compose files, three env templates, the Caddy snippet, the VATSIM fixture
mock, the two systemd units, `deploy-cc.sh`, and the build-and-deploy workflow.

How the VPS is reached is identical to `ssa-homepage` and `ssa-handover`: build
in Actions, push to ghcr, `appleboy/ssh-action` to a **per-environment**
forced-command key, `docker compose pull && up -d`. The ghcr package must be
public; the VPS has no registry login.

Three things are specific to ControlCentre being Laravel: three environments
rather than two, the `INSTALL_DEV` build arg, and `deploy-cc.sh` running
maintenance mode plus **both** migration paths. Upstream's entrypoint does not
migrate, and its `container/migrate.sh` omits `--force` (it would prompt and
hang) and knows nothing about `database/migrations-vatssa`.

**The env templates are blank on purpose.** The repository is public. Real values
live only in `/srv/apps/cc/<env>/.env` on the VPS.

**Gotcha carried forward from 15 Jul:** each environment's `VPS_SSH_KEY` must be
this repo's own forced-command key. `ssa-handover`'s staging environment once
held the homepage key, so every run silently redeployed the wrong app while the
workflow went green.

---

### `resources/views/layouts/sidebar.blade.php`

**Also (2026-11-19):** one line after the Users section,
`@include('vatssa.sidebar.groups')`, the Groups link for group staff and managers.
**Detector:** `tests/Feature/VatssaGroupsTest.php` (sidebar test).

Two items in the Reports section render with no `@can` guard while every other
item in that section has one: **Mentors** and **Feedback**. Their controllers
authorise `viewMentors` and `viewFeedback` on `ManagementReport`, so anyone who
can see the Reports section at all sees two links that answer 403. A Pipeline
Coordinator hit this on the first day in production.

Wrapped both in the same `@can` their controller checks, matching the Access
item directly above them.

**This should not stay a fork patch.** It is an upstream bug with no VATSSA
semantics attached, and the fix is four lines. See
`upstream-contrib/sidebar-report-guards` below. Drop this entry the moment that
lands.

## Upstream contributions in flight

Branches cut from `upstream-mirror` with a PR open at Vatsim-Scandinavia. When
one is merged upstream it comes back through `upstream-mirror` with a different
SHA (they squash), so the next absorption conflicts on those files. **Take
upstream's side wholesale, then delete the local branch and this entry.**

| Branch | What | Status |
|---|---|---|
| `upstream-contrib/mail-scheme` | `config/mail.php` feeds `MAIL_MAILER` into Symfony's transport `scheme`; should be `MAIL_SCHEME`. Same family as the `MAIL_ENCRYPTION` trap. | not opened yet |
| `upstream-contrib/install-dev-arg` | `INSTALL_DEV` build arg. Every division has the same seeding problem. | not opened yet |
| `upstream-contrib/sh-eol` | `.gitattributes` has `* text=auto` and no rule for `*.sh`. On a Windows checkout the six shell scripts, `container/entrypoint.sh` included, become CRLF, and the Dockerfile copies them straight into a Linux image. One line: `*.sh text eol=lf`. | not opened yet |
| `upstream-contrib/flex-logo-width` | `.front-cover .content-title img, svg` is sized by `height` alone. `front.blade.php` inlines the SVG, so its intrinsic width drives the flex layout; any mark with a large `width` attribute blows the login page apart. `.content` is `width: fit-content` so it inherits, and the Login button (`width: 100%`) spans the viewport. Needs an explicit width. | not opened yet |
| `upstream-contrib/logo-centring` | `_global.scss` centres the login wordmark with `left: calc(50vw - (14.5rem / 2))`, hardcoding VATSCA's logo width. Every other division's mark sits off-centre. `left: 50%` plus `translateX(-50%)` is width-independent. | not opened yet |
| `upstream-contrib/sidebar-report-guards` | `layouts/sidebar.blade.php` renders the Mentors and Feedback report links with no `@can`, while their controllers authorise `viewMentors` / `viewFeedback` on `ManagementReport`. Any role that can see the Reports section sees two links that 403. | not opened yet |
| `upstream-contrib/button-padding` | `_global.scss` `.content a { padding-top: 0.375; }` — **no unit**. Invalid CSS, dropped by every browser, so the Login button loses its top padding. | not opened yet |

---

## Deliberately not carried across

From the retired `ssa-controlcenter-custom` overlay, so nobody re-adds them.

| Overlay file | Why it is gone |
|---|---|
| `overrides/app/Policies/UserPolicy.php` | v7.0.0 made `updateRole` fully config-driven |
| `overrides/app/Policies/ManagementReportPolicy.php` | existed only for the deleted Membership Manager role |
| `overrides/config/mail.php` | one-line difference; contributed upstream instead |
| `overrides/database/migrations/2022_02_27_…alter_endorsement_pivot.php` | DigitalOcean's `sql_require_primary_key` only |
| `overrides/database/migrations/2022_05_14_…create_api_tokens_table.php` | same |
| `overrides/database/migrations/2024_07_10_…change_objects_to_uuid.php` | same |
| `overrides/database/seeders/DatabaseSeeder.php` | became the added `VatssaSeeder` |
| `custom/migrations/2025_09_17_100000_update_positions_table.php` | collided with upstream's own positions migration; regenerated as reference data |

---

_[ControlCentre patch register] © 2026 Daniël Schoonraad_
