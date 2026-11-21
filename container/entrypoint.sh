#!/bin/bash
set -e

CONTROL_CENTER_ROOT=/app
SELF_SIGNED_KEY=/etc/ssl/private/apache-selfsigned.key
SELF_SIGNED_CERT=/etc/ssl/certs/apache-selfsigned.crt

if [ ! -f "$SELF_SIGNED_KEY" ] || [ ! -f "$SELF_SIGNED_CERT" ]; then
    # Generate a self-signed cert to support SSL connections
    openssl req -x509 -nodes -days 358000 -newkey rsa:2048 -keyout "$SELF_SIGNED_KEY" -out "$SELF_SIGNED_CERT" -subj "/O=Your vACC/CN=Control Center"
fi

if [ -z "$APP_KEY" ] && [ ! -f "$CONTROL_CENTER_ROOT/.env" ]; then
    echo "################################################################################"
    echo "WARNING: You need to follow the configuration guide for Control Center"
    echo "################################################################################"
    echo "WARNING: Copying over example .env file"
    cp container/example-prod.env .env
    echo "WARNING: Temporarily updating the application key"
    php artisan key:generate
fi

# VATSSA: a storage volume keeps the ownership of the image that first created it, so
# the Dockerfile's chown never reaches an existing one. Apache runs as www-data.
FILES_DIR="$CONTROL_CENTER_ROOT/storage/app/public/files"
if [ -d "$FILES_DIR" ] && [ "$(stat -c %U "$FILES_DIR")" != "www-data" ]; then
    chown -R www-data:www-data "$CONTROL_CENTER_ROOT/storage" || echo "WARNING: could not hand storage to www-data; attachment uploads will fail"
fi

exec docker-php-entrypoint "$@"
