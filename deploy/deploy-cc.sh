#!/usr/bin/env bash
#
# ControlCentre deploy. Lives on the VPS at /srv/deploy/scripts/deploy-cc.sh.
# This copy is the source of truth; update here, then copy to the box.
#
# Invoked ONLY through an OpenSSH forced command, one key per environment:
#
#   command="/srv/deploy/scripts/deploy-cc.sh dev",...     ssh-ed25519 AAAA... gha-cc-dev
#   command="/srv/deploy/scripts/deploy-cc.sh staging",... ssh-ed25519 AAAA... gha-cc-staging
#   command="/srv/deploy/scripts/deploy-cc.sh prod",...    ssh-ed25519 AAAA... gha-cc-prod
#
# The forced command supplies the argument, so a leaked key can only redeploy
# its own environment. Whatever the client sends is ignored.
#
# Different from deploy-homepage.sh in one respect: ControlCentre is Laravel, so
# this runs migrations. Upstream's entrypoint does not, and its
# container/migrate.sh omits --force (it would prompt and hang) and knows
# nothing about database/migrations-vatssa.

set -euo pipefail

ENVIRONMENT="${1:-}"

case "$ENVIRONMENT" in
    dev|staging|prod) ;;
    *)
        echo "Usage: deploy-cc.sh <dev|staging|prod>" >&2
        exit 64
        ;;
esac

APP_DIR="/srv/apps/cc/${ENVIRONMENT}"
SERVICE="cc-${ENVIRONMENT}"

cd "$APP_DIR"

echo "==> Pulling ghcr.io/vatsim-ssa/ssa-controlcenter:${ENVIRONMENT}"
docker compose pull

echo "==> Recreating ${SERVICE}"
docker compose up -d

echo "==> Waiting for ${SERVICE} to report healthy"
for _ in $(seq 1 60); do
    status="$(docker inspect -f '{{.State.Health.Status}}' "$SERVICE" 2>/dev/null || echo starting)"
    [ "$status" = "healthy" ] && break
    sleep 2
done
if [ "$status" != "healthy" ]; then
    echo "${SERVICE} did not become healthy. Recent logs:" >&2
    docker compose logs --tail 50 "$SERVICE" >&2
    exit 1
fi

run() { docker compose exec -T "$SERVICE" "$@"; }

# VATSSA is a division, not a subdivision. User.php uses this string as a COLUMN
# NAME -- where(config('app.mode'), config('app.owner_code')) -- so 'subdivision'
# silently queries WHERE subdivision = 'SSA', matches nobody, and every
# member check fails division-wide with no error anywhere.
echo "==> Asserting division mode"
# Read the environment variable directly. compose injects the .env through
# env_file, so these are ordinary env vars inside the container. An earlier
# version used `php -r 'env(...)'`, which fails with "Call to undefined
# function env()" -- env() is a Laravel helper and `php -r` boots no framework.
# It then failed CLOSED, reporting APP_MODE as wrong when it had never been read.
run sh -c '[ "$APP_MODE" = "division" ]' \
    || { echo "APP_MODE must be 'division' for VATSSA. Refusing to continue." >&2; exit 1; }

# Production must never run with debug on or a non-production APP_ENV. Assert it
# rather than trusting the .env on the box, because a wrong APP_DEBUG leaks
# stack traces containing database credentials on any 500.
if [ "$ENVIRONMENT" = "prod" ]; then
    echo "==> Asserting production configuration"
    run sh -c '[ "$APP_DEBUG" = "false" ]' \
        || { echo "APP_DEBUG is not false in production. Refusing to continue." >&2; exit 1; }
    run sh -c '[ "$APP_ENV" = "production" ]' \
        || { echo "APP_ENV is not production. Refusing to continue." >&2; exit 1; }
fi

echo "==> Maintenance mode on"
run php artisan down --render="errors.maintenance" || true

# Two paths, upstream's first. The VATSSA reference-data migration assumes the
# tables upstream's chain creates, so the order is not optional.
echo "==> Migrating (upstream)"
run php artisan migrate --force --no-interaction

echo "==> Migrating (VATSSA)"
run php artisan migrate --force --no-interaction --path=database/migrations-vatssa

# Dev and staging seed themselves so a rebuilt environment is usable without a
# manual step. The seeder returns quietly when the database already has users,
# so this is safe on every deploy. It refuses outright on production.
if [ "$ENVIRONMENT" != "prod" ]; then
    echo "==> Seeding fixtures (no-op if the database already has users)"
    run php artisan db:seed --class=VatssaSeeder --force --no-interaction

    # The training pipeline data: a platform row for every user, theory
    # attempts and an email history for every open training, and ten named
    # students one per stage. It exists so the pipeline can be clicked through
    # with NO bot, bridge, Moodle or Discord running -- which is most of the
    # time.
    #
    # Every write is keyed, so unlike VatssaSeeder this is safe to re-run and
    # does so on every deploy.
    #
    # THIS RUNS ON STAGING AS WELL AS DEV, and that is deliberate -- staging is
    # a fixture environment today. The seeder does NOT rely on that: it refuses
    # unless the database actually looks like fixtures, checked by the presence
    # of the VatssaSeeder dev accounts. That is what protects staging once
    # Phase B puts a copy of production data on it, when APP_ENV alone would
    # still say "staging" and let it through.
    echo "==> Seeding the pipeline cohort"
    run php artisan db:seed --class=VatssaPipelineSeeder --force --no-interaction
fi

echo "==> Clearing caches"
run php artisan optimize:clear

echo "==> Checking the task scheduler"
# A dead scheduler is silent by nature, and this one WAS dead: the old unit ran
# `docker exec ... control-center`, and no container has that name. Every
# firing failed for as long as it was installed, so nothing scheduled ever ran
# and nothing said so.
#
# Checked here because a deploy is the one moment somebody is definitely
# watching. Non-fatal on purpose: a broken timer is not a reason to leave the
# application in maintenance mode.
if systemctl is-active --quiet "control-center-tasks@${ENVIRONMENT}.timer"; then
    echo "    timer is active"
else
    echo "    WARNING: control-center-tasks@${ENVIRONMENT}.timer is NOT active." >&2
    echo "    Nothing scheduled will run: no roster warnings, no mentor watch," >&2
    echo "    no member sync, no endorsement cleanup, no task notifications." >&2
    echo "    Install it with:" >&2
    echo "      sudo cp deploy/control-center-tasks@.service /etc/systemd/system/" >&2
    echo "      sudo cp deploy/control-center-tasks@.timer   /etc/systemd/system/" >&2
    echo "      sudo systemctl daemon-reload" >&2
    echo "      sudo systemctl enable --now control-center-tasks@${ENVIRONMENT}.timer" >&2
fi

echo "==> Maintenance mode off"
run php artisan up

echo "Deployed ControlCentre: ${ENVIRONMENT}"
