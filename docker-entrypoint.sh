#!/bin/sh
set -e

# Porta (Render injeta PORT). Regex idempotente.
if [ -n "${PORT:-}" ]; then
    sed -ri "s/^Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf
fi

# Garante que existe um .env (valores de ambiente são lidos pelo Laravel em runtime).
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Limpa caches de configuração e rotas.
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Link do storage público.
php artisan storage:link || true

# Migrações com retry (até 5 vezes) para aguardar o compute do Neon acordar.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    MIG_URL="${DB_DIRECT_URL:-$DB_URL}"
    for i in 1 2 3 4 5; do
        DB_URL="$MIG_URL" php artisan migrate --force && break
        echo "migrate falhou (tentativa $i), a aguardar o compute do Neon..."
        sleep 5
    done
fi

# Seeders (opcional).
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    DB_URL="${DB_DIRECT_URL:-$DB_URL}" php artisan db:seed --force
fi

exec "$@"
