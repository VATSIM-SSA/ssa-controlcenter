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

## Deployment

Built by GitHub Actions, pushed to `ghcr.io/vatsim-ssa/ssa-controlcenter`, and
deployed over SSH with a per-environment forced-command key that can only
redeploy its own environment. Same shape as `ssa-homepage` and `ssa-handover`.

Real configuration lives only in `/srv/apps/cc/<env>/.env` on the VPS. The
`deploy/.env.*.template` files here are blank on purpose: this repository is
public.

---

_ControlCentre © Vatsim-Scandinavia. VATSSA fork © 2026 Daniël Schoonraad._
