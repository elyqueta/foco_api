#!/bin/sh
set -e

if [ -n "${PORT:-}" ]; then
    sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf || true
    sed -ri "s/:80/:${PORT}/g" /etc/apache2/sites-available/*.conf || true
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ -n "${APP_KEY:-}" ]; then
    sed -ri "s/^APP_KEY=.*/APP_KEY=${APP_KEY}/" .env || true
fi

if [ -n "${APP_ENV:-}" ]; then
    sed -ri "s/^APP_ENV=.*/APP_ENV=${APP_ENV}/" .env || true
fi

if [ -n "${APP_DEBUG:-}" ]; then
    sed -ri "s/^APP_DEBUG=.*/APP_DEBUG=${APP_DEBUG}/" .env || true
fi

if [ -n "${APP_URL:-}" ]; then
    sed -ri "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env || true
fi

if [ -n "${LOG_CHANNEL:-}" ]; then
    sed -ri "s/^LOG_CHANNEL=.*/LOG_CHANNEL=${LOG_CHANNEL}/" .env || true
fi

if [ -n "${LOG_LEVEL:-}" ]; then
    sed -ri "s/^LOG_LEVEL=.*/LOG_LEVEL=${LOG_LEVEL}/" .env || true
fi

if [ -n "${SESSION_DRIVER:-}" ]; then
    sed -ri "s/^SESSION_DRIVER=.*/SESSION_DRIVER=${SESSION_DRIVER}/" .env || true
fi

if [ -n "${CACHE_STORE:-}" ]; then
    sed -ri "s/^CACHE_STORE=.*/CACHE_STORE=${CACHE_STORE}/" .env || true
fi

if [ -n "${QUEUE_CONNECTION:-}" ]; then
    sed -ri "s/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=${QUEUE_CONNECTION}/" .env || true
fi

if [ -n "${MAIL_MAILER:-}" ]; then
    sed -ri "s/^MAIL_MAILER=.*/MAIL_MAILER=${MAIL_MAILER}/" .env || true
fi

if [ -n "${DB_CONNECTION:-}" ]; then
    sed -ri "s/^DB_CONNECTION=.*/DB_CONNECTION=${DB_CONNECTION}/" .env || true
fi

if [ -n "${DB_DATABASE:-}" ]; then
    sed -ri "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" .env || true
fi

if [ -n "${DB_HOST:-}" ]; then
    sed -ri "s/^DB_HOST=.*/DB_HOST=${DB_HOST}/" .env || true
fi

if [ -n "${DB_PORT:-}" ]; then
    sed -ri "s/^DB_PORT=.*/DB_PORT=${DB_PORT}/" .env || true
fi

if [ -n "${DB_USERNAME:-}" ]; then
    sed -ri "s/^DB_USERNAME=.*/DB_USERNAME=${DB_USERNAME}/" .env || true
fi

if [ -n "${DB_PASSWORD:-}" ]; then
    sed -ri "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASSWORD}/" .env || true
fi

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan storage:link || true

exec "$@"
