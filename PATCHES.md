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

Exactly one. If this list ever grows, the divergence is growing with it.

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

### `tools/expand.py`, `tools/contrast-check.py`

The two validators. Both parse the file they check rather than duplicating it.

### `public/images/logos/vatssa*`

Logos. Selected by `APP_LOGO` and `APP_LOGO_MAIL`, upstream's own mechanism
(`docs/setup/logo.md`). No code involved.

### `deploy/**`, `Dockerfile` (`INSTALL_DEV` arg only)

Compose files, env templates, the VATSIM fixture mock, the systemd units, and one
build arg on upstream's Dockerfile.

**`INSTALL_DEV` is a build arg, never an env var**, so it cannot be flipped by
editing a `.env` on the box. Production builds with `false`.

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
