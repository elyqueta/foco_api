#!/bin/sh
set -e

# Porta (Render injeta PORT). Regex idempotente.
if [ -n "${PORT:-}" ]; then
    sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf
fi

# Em produção o container NÃO usa .env: lê variáveis de ambiente diretamente.
if [ "${APP_ENV:-local}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
    echo "ERRO: APP_KEY em falta." >&2
    exit 1
fi

php artisan config:clear
php artisan route:clear

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    if [ -n "${DB_DIRECT_URL:-}" ]; then
        php artisan migrate --force --database=pgsql_direct
    else
        php artisan migrate --force
    fi
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --class=ProductionSeeder --force   # idempotente (Fase 3)
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

exec "$@"
