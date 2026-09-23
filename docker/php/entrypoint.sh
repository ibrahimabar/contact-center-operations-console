#!/bin/bash
set -e

cd /var/www/html

if [ ! -f composer.json ]; then
    echo "[entrypoint] No composer.json found in /var/www/html — is the backend/ volume mounted?"
    exec "$@"
fi

if [ ! -d vendor ]; then
    echo "[entrypoint] vendor/ missing, running composer install..."
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    echo "[entrypoint] .env missing, copying .env.example..."
    cp .env.example .env
fi

# Laravel reads .env itself via phpdotenv, but this script needs the same
# values (DB_HOST etc.) at the shell level for the wait-for-mysql loop below.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    source <(grep -v '^\s*#' .env | grep -v '^\s*$')
    set +a
fi

if [ -f artisan ]; then
    if ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
        echo "[entrypoint] Generating APP_KEY..."
        php artisan key:generate --force
    fi

    echo "[entrypoint] Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    for i in $(seq 1 60); do
        if php -r "new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306}', '${DB_USERNAME:-root}', '${DB_PASSWORD:-}');" 2>/dev/null; then
            echo "[entrypoint] MySQL is up."
            break
        fi
        sleep 2
    done

    echo "[entrypoint] Running migrations..."
    php artisan migrate --force

    if [ "${SEED_ON_START:-false}" = "true" ]; then
        AGENT_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1 || echo 0)
        if [ "$AGENT_COUNT" = "0" ] || [ -z "$AGENT_COUNT" ]; then
            echo "[entrypoint] Database empty, seeding..."
            php artisan db:seed --force
        else
            echo "[entrypoint] Database already has data, skipping seed."
        fi
    fi

    [ -L public/storage ] || php artisan storage:link
fi

exec "$@"
