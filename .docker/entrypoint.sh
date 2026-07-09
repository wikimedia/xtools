#!/bin/sh
# Shared entrypoint for the app and api php-fpm services. XTOOLS_ROLE picks the
# behaviour: only the app role owns dependency install and migrations, so the
# two services never race on the shared working tree (dev) or the database.
set -e

role="${XTOOLS_ROLE:-app}"
cd /var/www

# Dev bind-mounts an empty vendor/; the app installs, the api waits for it.
# In the baked prod image vendor/ is already present, so both skip this.
if [ ! -f vendor/autoload.php ]; then
    if [ "$role" = "app" ]; then
        echo "[entrypoint] vendor/ missing; installing composer dependencies..."
        composer install --no-interaction --no-progress
    else
        echo "[entrypoint] waiting for the app service to install dependencies..."
        while [ ! -f vendor/autoload.php ]; do sleep 1; done
    fi
fi

if [ "$role" = "app" ]; then
    echo "[entrypoint] waiting for database ${DATABASE_HOST}:${DATABASE_PORT}..."
    until php -r '$h=getenv("DATABASE_HOST")?:"db"; $p=(int)(getenv("DATABASE_PORT")?:3306); exit(@fsockopen($h,$p,$e,$s,2)?0:1);'; do
        sleep 1
    done
    echo "[entrypoint] applying database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec "$@"
