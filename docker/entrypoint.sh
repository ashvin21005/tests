#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY in the Railway app service variables.}"
: "${DB_HOST:?Set DB_HOST to the Railway MySQL host.}"
: "${DB_DATABASE:?Set DB_DATABASE to the Railway MySQL database.}"
: "${DB_USERNAME:?Set DB_USERNAME to the Railway MySQL user.}"
: "${DB_PASSWORD:?Set DB_PASSWORD to the Railway MySQL password.}"

DB_CONNECTION="${DB_CONNECTION:-mysql}"
if [ "$DB_CONNECTION" != "mysql" ]; then
    echo "DB_CONNECTION must be mysql for this Railway image." >&2
    exit 1
fi

DB_PORT="${DB_PORT:-3306}"
PORT="${PORT:-8080}"
case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be a numeric TCP port." >&2
        exit 1
        ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

export DB_CONNECTION DB_PORT PORT

envsubst '${PORT}' < /etc/apache2/ports.conf.template > /etc/apache2/ports.conf
envsubst '${PORT}' < /etc/apache2/sites-available/000-default.conf.template \
    > /etc/apache2/sites-available/000-default.conf

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
php artisan optimize

chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
