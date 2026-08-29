# ControlCentre — VATSSA

A fork of [Vatsim-Scandinavia/controlcenter](https://github.com/Vatsim-Scandinavia/controlcenter),
run by VATSSA (VATSIM Sub-Saharan Africa).

> This file is `.github/README.md`, which GitHub renders in preference to the
> root `README.md`. Upstream's own README is left untouched, so it can never
> conflict on a release absorption. Read it for what ControlCentre *is*; read
> this for what VATSSA does differently.

## Start here

| | |
|---|---|
| **What diverges from upstream, and how you find out when it breaks** | [`PATCHES.md`](../PATCHES.md) |
| **Which upstream release this tracks** | [`.upstream-version`](../.upstream-version) |
| Branch model, upstream absorption, contributing back | `CC-FORK-WORKFLOW.md` in the AIOS repo |
| VPS layout, environments, deploy keys | `CC-VPS-SETUP.md` in the AIOS repo |

## The one rule

**Prefer an added file over a modified one.** Every file modified in place is a
conflict on some future upstream release. Every file added is free forever,
because upstream will never touch a path it does not know about.

Today that means **two** modified upstream files:

- `config/roles.php` — six VATSSA roles and their permission matrix
- `Dockerfile` — one `INSTALL_DEV` build arg

Everything else is an addition. If `PATCHES.md` ever lists a third, the
divergence is growing and it should be a deliberate decision, not a habit.

## Branches

```
upstream-mirror   tracks the latest upstream release. Fast-forward only.
      ↓
integration       where absorption conflicts are resolved
      ↓
dev  ← feature/*  free pushes for the tech team. Deploys :dev
      ↓  PR
staging           QA. Deploys :staging
      ↓  PR
main              production. Deploys :prod
```

`upstream-contrib/*` branches off `upstream-mirror`, never off `dev`, so a pull
request to Vatsim-Scandinavia carries the feature and not the VATSSA role model.

## Before you change anything

Run both validators. They parse the files they check rather than duplicating
them, and CI runs them on every push.

```bash
python tools/expand.py config/roles.php
python tools/contrast-check.py resources/sass/themes/_custom.scss
php artisan test --filter=VatssaTest
```

`tests/Feature/VatssaTest.php` is the drift detector for everything this fork
adds. Added files never conflict, so git gives no signal when upstream moves
underneath one — that test file is what replaces the signal. **Do not delete or
skip it to make a release bump go green.**

## Two things that bite

**`APP_MODE` must be `division`.** `User.php` uses the value as a *column name*
(`where(config('app.mode'), config('app.owner_code'))`) and `UserController`
puts it in the VATSIM Core API path. Set to upstream's `subdivision` default it
queries `WHERE subdivision = 'SSA'`, matches nobody, and every member check
fails division-wide with no error anywhere. `deploy/deploy-cc.sh` asserts it.

**No Redis.** Cache, sessions and the queue are file and sync based. The
compose volume on `storage/framework/sessions` exists because sessions are
files and must survive a container recreate.

## Testing the training pipeline without the bot

Everything the pipeline writes normally arrives over the bridge, from a bot that
polls Moodle and Discord. **You do not need any of that to look at the pages.**

`VatssaPipelineSeeder` writes the same rows directly, in two parts.

**It backfills what `VatssaSeeder` already made.** Every one of the 250 users
gets a platform row, and every open standard training gets theory attempts and
an email history consistent with the stage it is in. Without that, every profile
on dev shows an empty Platforms panel and every training an empty email log —
which reads as broken rather than as unseeded.

It also **moves a share of pre-training rows into awaiting-mentor**.
`TrainingFactory` rolls a status between -4 and 3 and `AWAITING_MENTOR` is 4, so
the factory can never produce it — the one stage this fork exists to add would
otherwise be the only empty page on dev.

**Then ten named students** on CIDs `10000301`–`10000310`, one per situation
worth looking at on purpose.

It runs on every dev and staging deploy, so a fresh environment already has all
of this. To run it by hand:

```
php artisan migrate --path=database/migrations-vatssa
php artisan db:seed --class=VatssaPipelineSeeder
```

It refuses on production and is safe to re-run — every write is keyed.

### The named ten, and why each one is there

The backfilled population is the background. These are the ones built by hand.

| CID | Stage | The point |
|---|---|---|
| 301 | In queue | The day they registered. Nothing has happened. |
| 302 | Pre-training | On both platforms, inside the 90-day window, no attempt. |
| 303 | Pre-training | Failed once. Still has time. |
| 304 | **Awaiting mentor** | Passed. The stage upstream cannot express at all. |
| 305 | **Awaiting mentor** | Pass, fail, pass. **Latest counts, not best.** |
| 306 | Active training | Mentored, so a later attempt cannot pull them back out. |
| 307 | Awaiting exam | Ready for a CPT. |
| 308 | Completed | The theory row outlives the training that produced it. |
| 309 | Pre-training | On Moodle, gone from Discord. The chase case. |
| 310 | Pre-training | **Not a VATSIM member** — a bot or test account, not a missing tick. |

**305 is the one worth staring at.** Three attempts: 91% passed, then 39%
failed, then 80% passed. The panel shows all three; the person reads as
currently passed — and would read as failed if the middle one were last. That is
the entire "latest, not best" rule, visible on one page.

**304 and 306 together** show why the theory gate is checked at a moment rather
than enforced continuously. 306 has a mentor, so a failed practice paper cannot
send them back to the queue.

**Nothing in the backfill contradicts the rules**, deliberately. Nobody past the
gate is missing a theory pass, and no visiting, transfer or refresher training
has attempts at all — they already hold the rating. Fixtures that disagree with
the rule they demonstrate are worse than no fixtures: they look like a bug in
the code rather than a gap in the data.

### Where to click

- **A student's profile** — Platforms and Theory panels. Log in as Web Nine
  (`10000009`, ATC training manager) to see marks; as Web Eight
  (`10000008`, pipeline coordinator) to see pass/fail without them; as Web Six
  (`10000006`, mentor) to see neither.
- **A training page** — the Emails sent panel, and the status dropdown, which
  should offer *Awaiting mentor* on 306 and not on 301.
- **Tasks → Everyone** — visible to Web Eight and Web Nine, not to Web Six.
- **Administration → Pipeline templates / Moodle courses** — admin only.

### What is deliberately empty

**The Moodle course map has no ids.** Every rating shows `0`, which means the
map is empty and no rating needs theory. That is the correct starting state:
nobody has read the ids out of Moodle yet, and inventing them would give every
student no attempts — indistinguishable from a room full of failures.

**`vatssa.task_routing` is an empty array**, so tasks route exactly as upstream
until it has been decided which request belongs on which desk.

Neither is a bug to fix in code. Both are decisions waiting to be made.

---

## Deployment

Built by GitHub Actions, pushed to `ghcr.io/vatsim-ssa/ssa-controlcenter`, and
deployed over SSH with a per-environment forced-command key that can only
redeploy its own environment. Same shape as `ssa-homepage` and `ssa-handover`.

Real configuration lives only in `/srv/apps/cc/<env>/.env` on the VPS. The
`deploy/.env.*.template` files here are blank on purpose: this repository is
public.

---

_ControlCentre © Vatsim-Scandinavia. VATSSA fork © 2026 Daniël Schoonraad._
