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

Twenty-two, and they are three different things which should be read as such.

**Seven are code and configuration.** These are the real divergence. Each one is
a conflict on some future release, each needs judgement to resolve, and this
list growing is the thing to resist.

**Five are Blades.** Cheaper than they look. A Blade conflict is visual and
obvious — you open the page and see it — where a controller conflict carries
logic and fails silently. The fork deliberately accepts modified views to avoid
modified controllers.

**Ten are replaced brand assets** — the favicon set and the header mark. Binary
files upstream rarely touches, and a conflict on any of them is resolved by
keeping ours without thinking. They are listed for completeness, not because
they carry risk.

**One is a deletion.**

| | Count |
|---|---|
| Code and configuration | 7 |
| Blades | 5 |
| Brand assets | 10 |
| Deleted | 1 |
| Added files | 46 |
| Force-added past `.gitignore` | 3 |

### The training pipeline, in one place

Most of what follows exists for one reason, so it is worth stating once. VATSSA
runs an external training pipeline bot. **The bot computes; Control Center
stores and shows.** No pipeline logic lives in PHP — logic in two places drifts,
and the bot already carries the tests for these rules.

Control Center therefore gains tables, panels and write endpoints, and never
gains a decision. Where a change below looks like it is missing its logic, that
is why.

---

### `app/Helpers/TrainingStatus.php`

**Why it diverges.** Adds `AWAITING_MENTOR`: theory passed, no mentor yet.
Upstream cannot express it — both the theory window and the mentor wait are
"pre-training" — and it is the stage the whole pipeline turns on. Also closes
the three system-owned stages to manual assignment.

**The decision worth knowing.** It is appended as `4`, not inserted as `2`,
even though the lifecycle position is between 1 and 2. Inserting means
renumbering under a live database, and `training_activities` stores past
transitions as bare integers — renumbering silently rewrites history, and
nothing in the application would ever tell you. Appending is free because no
ordered comparison in the codebase uses a bound above `PRE_TRAINING`.

**Detector.** `VatssaTest::awaiting_mentor_does_not_disturb_the_status_order`
pins all five values. If upstream adds a case at 4, that fails loudly rather
than two stages quietly becoming one.

**On conflict.** Keep both sets of cases. Never renumber upstream's.

### `app/Console/Commands/SendTrainingInterestNotifications.php`

**Why it diverges.** One line: the 30-day interest chase bounded on
`status <= PRE_TRAINING`, which with `AWAITING_MENTOR` at 4 would skip exactly
the people who have been waiting longest. It is a `whereIn` now.

**Detector.**
`VatssaTest::the_interest_chase_still_reaches_people_waiting_for_a_mentor`
greps the source, so a revert to a range fails.

**On conflict.** Re-apply the `whereIn`, adding whatever statuses upstream added.

### `app/Http/Controllers/TrainingController.php`

**Why it diverges.** One rule added to the `status` validation, so the pipeline
stages cannot be set by hand. The Blade dropdown hides them; this refuses them,
because a hidden `<option>` is two seconds of DevTools away.

**Detector.** None automatic — a merge that drops the rule leaves the dropdown
looking correct. Check this file by hand on every absorption.

**On conflict.** Keep `new AssignableTrainingStatus` in the `status` rule array.

### `app/Http/Controllers/TaskController.php`

**Why it diverges.** An `all` filter on the task list for holders of
`tasks.overview`. Every request in the division lives on a task, and without
this nobody can see what is outstanding across it.

**On conflict.** Re-add the `elseif` branch and the `$canSeeAll` variable.

### `app/Policies/TrainingPolicy.php`

**Why it diverges.** `create()` checks `training.create.manual` instead of
`fir.management.reports.view`. The upstream permission ALSO opens the training
request queue, the mentor index and the access report, so narrowing manual
creation there would take the queue away from the coordinators who work out of
it every day.

**Detector.** `VatssaTest::manual_training_creation_has_its_own_permission`.

### `config/app.php`

**Why it diverges.** One provider registered: `VatssaServiceProvider`. It exists
precisely so that `app/Http/Kernel.php`, `routes/api.php`, `routes/web.php` and
`AppServiceProvider` stay verbatim upstream — the middleware alias, both route
files and the task observer all register from there.

**On conflict.** Keep the line. Losing it silently disables every VATSSA route.

### Blades (5 files)

`layouts/sidebar.blade.php` · `training/show.blade.php` ·
`user/show.blade.php` · `tasks/index.blade.php` · `tasks/parts/row.blade.php`

Each carries one small VATSSA change, marked with a `{{-- VATSSA: --}}` comment:
the roster removed from the nav and the two pipeline admin pages added; the
message log included on a training; the platforms panel included on a profile;
the task overview tab and its assignee column.

**On conflict, take upstream's version and re-apply the marked block.** These
are the cheapest conflicts in the fork — open the page and you can see whether
you got it right.

### Deleted: `app/Tasks/Types/TheoreticalExam.php`

Task types are directory-scanned, so deleting the file removes the option.
VATSSA's theory exam lives inside the Moodle course; there is no access to
grant, and an option nobody should ever pick is worse than no option.

**Detector.** `VatssaTest::the_theoretical_exam_task_type_is_gone`.

**On conflict.** Upstream restoring the file shows up as an add, not a conflict.
Delete it again.

### `config/roles.php`

**Why it diverges.** VATSSA runs six roles (`admin`, `atc-training-manager`,
`pipeline-coordinator`, `mentor`, `nav-editor`, `feedback-team`) in place of
upstream's eight, with its own permission matrix. The permission catalogue is
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

### The pipeline additions

`config/vatssa.php` · `routes/vatssa.php` · `routes/vatssa-web.php` ·
`app/Providers/VatssaServiceProvider.php` ·
`app/Http/Middleware/VatssaBridgeToken.php` ·
`app/Http/Controllers/Vatssa/*` · `app/Models/Vatssa/*` ·
`app/Observers/VatssaTaskObserver.php` ·
`app/Rules/AssignableTrainingStatus.php` · `resources/views/vatssa/**` ·
`database/migrations-vatssa/2026_08_29_*`

**Five tables**, each for a fact Control Center v7.0.0 has nowhere to put:
platforms (it has no concept of Discord), theory attempts (no theory field at
all), the message log (it cannot see what its own mailer sent), message
templates (its editor is append-only, on three emails, per area) and the Moodle
course map.

**Two are worth understanding rather than just knowing about:**

*Theory attempts are keyed to person plus rating, never to a training.* A result
owned by a training dies with it — close it, open a new one, and the pass is
gone even though the person still knows the material. And the pass is derived
from the **latest** attempt, not the best: somebody who passed two years ago and
failed a retake last week does not currently know it.

*The bridge refuses everything when `VATSSA_BRIDGE_TOKEN` is unset.* An
unconfigured token must never mean "let everyone in". That property is what
makes shipping these routes before the bot exists safe.

**Two things ship deliberately inert**, and both are Daniël's to fill in:
`vatssa.task_routing` is empty, so tasks route exactly as upstream until it has
been *decided* where each request goes; and the Moodle course map drops any
rating whose ids are still `0`, so an unconfigured rating visibly needs no
theory rather than silently giving every student no attempts.

**Detectors.** Fourteen tests in `VatssaTest`, and `tools/expand.py` for the
permission matrix. The one thing nothing detects: whether the deployment sets
`VATSSA_BRIDGE_TOKEN` and whether Caddy 403s `/api/vatssa/bridge/*`. Both are
outside the repository. **Neither is optional.**

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
