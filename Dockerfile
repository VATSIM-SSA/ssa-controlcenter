# Intermediate build container for composer dependencies
FROM docker.io/library/composer:2 AS composer

WORKDIR /app
COPY ./ /app/

RUN composer install --no-dev --no-interaction --prefer-dist

# Intermediate build container for front-end resources
FROM docker.io/library/node:24.19.0-alpine AS frontend
# Easy to prune intermediary containers
LABEL stage=build

WORKDIR /app
COPY ./ /app/
COPY --from=composer /app/vendor/ /app/vendor/

RUN npm ci --omit dev && \
    npm run build

####################################################################################################
# Primary container
FROM docker.io/library/php:8.5.9-apache-bookworm

# Default container port for the apache configuration
EXPOSE 80 443

# Install various dependencies
# - git and unzip for composer
# - vim and nano for our egos
# - ca-certificates for OAuth2
RUN apt-get update && \
    apt-get install -y git unzip vim nano ca-certificates libpq-dev && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* && \
    a2enmod rewrite ssl


# Custom Apache2 configuration based on defaults; fairly straightforward
COPY ./container/configs/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY ./container/configs/apache.conf /etc/apache2/apache2.conf
# Custom PHP configuration based on $PHP_INI_DIR/php.ini-production
COPY ./container/configs/php.ini /usr/local/etc/php/php.ini


# Install PHP extension(s)
COPY --from=mlocati/php-extension-installer:2.11.12 /usr/bin/install-php-extensions /usr/local/bin/
# These are the extensions we depend on:
# $ composer check -f json 2>/dev/null | jq '.[] | select(.name | startswith("ext-")) | .name | sub("ext-"; "")' -r
# Currently, this seems to only be pdo_mysql.
RUN install-php-extensions pdo_mysql pdo_pgsql opcache

# Install composer
COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer
# Copy over the application, static files, plus the ones built/transpiled by Mix in the frontend stage further up
COPY --chown=www-data:www-data ./ /app/
COPY --from=frontend --chown=www-data:www-data /app/public/ /app/public/

WORKDIR /app

# VATSSA: the only change to upstream's Dockerfile.
#
# `php artisan db:seed` needs fakerphp/faker, which lives in require-dev, so a
# --no-dev image cannot seed. Dev and staging build with INSTALL_DEV=true;
# production builds with false and must never ship phpunit, faker, debugbar or
# boost.
#
# This is a BUILD ARG, not an env var, so it cannot be flipped by editing a
# .env on the box. .github/workflows/deploy.yml sets it per environment and
# fails the run outright if a production build ever carries dev dependencies.
#
# Candidate for upstream: every division running CC has the same seeding
# problem. See PATCHES.md, upstream-contrib/install-dev-arg.
ARG INSTALL_DEV=false

RUN chmod -R 755 storage bootstrap/cache && \
        if [ "$INSTALL_DEV" = "true" ]; then \
            composer install --no-interaction --prefer-dist; \
        else \
            composer install --no-dev --no-interaction --prefer-dist; \
        fi && \
        mkdir -p /app/storage/app/public/files

# Wrap around the default PHP entrypoint with a custom entrypoint
COPY ./container/entrypoint.sh /usr/local/bin/controlcenter-entrypoint
ENTRYPOINT [ "controlcenter-entrypoint" ]
CMD ["apache2-foreground"]
