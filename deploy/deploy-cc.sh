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
run php -r 'exit(env("APP_MODE") === "division" ? 0 : 1);'     || { echo "APP_MODE must be 'division' for VATSSA. Refusing to continue." >&2; exit 1; }

# Production must never run with debug on or a non-production APP_ENV. Assert it
# rather than trusting the .env on the box, because a wrong APP_DEBUG leaks
# stack traces containing database credentials on any 500.
if [ "$ENVIRONMENT" = "prod" ]; then
    echo "==> Asserting production configuration"
    run php -r 'exit(env("APP_DEBUG") === false || env("APP_DEBUG") === "false" ? 0 : 1);' \
        || { echo "APP_DEBUG is not false in production. Refusing to continue." >&2; exit 1; }
    run php -r 'exit(env("APP_ENV") === "production" ? 0 : 1);' \
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
fi

echo "==> Clearing caches"
run php artisan optimize:clear

echo "==> Maintenance mode off"
run php artisan up

echo "Deployed ControlCentre: ${ENVIRONMENT}"
