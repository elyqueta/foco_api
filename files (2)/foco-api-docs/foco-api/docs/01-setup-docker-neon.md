# Fase 1 — Setup, Docker, Neon, Sanctum, CORS

## Objetivo
API Laravel a arrancar em Docker (local e Render), ligada ao PostgreSQL do Neon, com rotas `api/*`, Sanctum instalado, CORS e `/api/health`.

## Problemas encontrados no repositório atual (corrigir nesta fase)

| # | Problema | Correção |
|---|---|---|
| 1 | `bootstrap/app.php` só tem `web` e `commands` → **não existe `routes/api.php`**, `/api/*` dá 404. | `php artisan install:api` (cria `routes/api.php`, instala Sanctum, migration `personal_access_tokens`) e garantir `api:` em `withRouting`. |
| 2 | `Dockerfile` chama `composer install` mas **não instala o Composer** na imagem. | `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer`. |
| 3 | Só tem `pdo_sqlite`. Neon é Postgres. | Instalar `libpq-dev` + extensões `pdo_pgsql pgsql`. |
| 4 | `AllowOverride None` por defeito no Apache → `.htaccess` ignorado → rotas Laravel falham. | `sed` para `AllowOverride All` (ver Dockerfile abaixo). |
| 5 | `docker-compose.yml` mapeia `8080:8080` mas o Apache só muda de porta se `PORT` existir (não existe) → escuta na 80. | `ENV PORT=8080` no Dockerfile; compose mapeia `8000:8080` (o `proxy.conf.json` do front aponta para `localhost:8000`). |
| 6 | `docker-compose.yml` tem `APP_KEY` **commitada**. | Gerar nova (`php artisan key:generate --show`), mover para `.env` local (não commitado), remover do compose. Considerar a antiga comprometida. |
| 7 | Entrypoint faz `sed` por variável e copia `.env.example`; `sed s/Listen 80/` não é idempotente (`Listen 8080` → `808080`). | Entrypoint reescrito abaixo. |
| 8 | `.env.example` com defaults SQLite/Laravel. | Atualizar (abaixo). |
| 9 | `config/database.php` sem ligação direta para migrations no Neon. | Adicionar `pgsql_direct` (abaixo). |
| 10 | `database/seeders/DatabaseSeeder.php` cria "Test User". | Tratado na Fase 3. |
| 11 | `routes/web.php` serve `welcome` (Tailwind v4 inline). | Substituir por `GET /` → JSON `{name, status}`; remover `resources/views/welcome.blade.php`, `resources/css`, `resources/js`, `vite.config.js`, `package.json` (a API não tem front). `ExampleTest` ajustado. |
| 12 | Sem `TrustProxies` (Render está atrás de proxy HTTPS). | `$middleware->trustProxies(at: '*')`. |

## Tarefas

- [ ] **1.1** Copiar snapshot das regras do front para `docs/referencia/regras-negocio-front.md` (primeira linha: `> Snapshot de <data> — fonte: repo foco/docs/regras-negocio.md`).
- [ ] **1.2** `composer require laravel/sanctum` já vem com `install:api`; executar `php artisan install:api` (aceitar a migration). Confirmar `routes/api.php`.
- [ ] **1.3** `composer require resend/resend-php` (usado na Fase 10).
- [ ] **1.4** Editar `bootstrap/app.php`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Fase 2+: render de App\Exceptions\DomainRuleException → 422/409 com { message, code }
    })->create();
```

- [ ] **1.5** `php artisan config:publish cors` → `config/cors.php`: `paths => ['api/*']`, `allowed_origins => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', '')))`, `allowed_headers => ['*']`, `supports_credentials => false`.
- [ ] **1.6** `config/sanctum.php`: `'expiration' => 10080` (7 dias, igual ao mock do front). `guard => []` (só tokens).
- [ ] **1.7** `config/database.php`: adicionar conexão `pgsql_direct` (cópia de `pgsql` com `'url' => env('DB_DIRECT_URL')`) para migrations (o endpoint "pooler" do Neon não é ideal para DDL).
- [ ] **1.8** `GET /api/health` (sem auth): `{ "status": "ok", "app": config('app.name'), "db": true|false, "time": ISO }` (`db` via `DB::select('select 1')` em try/catch; HTTP 200 sempre, 503 se `db=false`).
- [ ] **1.9** `GET /` devolve `{ "name": "Foco API", "status": "ok" }`.
- [ ] **1.10** Reescrever `Dockerfile`, `docker-entrypoint.sh`, `docker-compose.yml`, `.env.example`, `.dockerignore` (abaixo).
- [ ] **1.11** `config/app.php`: manter `timezone => 'UTC'`. `.env.example`: `APP_LOCALE=pt`, `APP_FALLBACK_LOCALE=pt`. `php artisan lang:publish` e adicionar `lang/pt/{validation,auth,passwords}.php` com traduções PT (usar pacote próprio ou ficheiros manuais; **sem dependências novas** além das listadas).
- [ ] **1.12** Teste `tests/Feature/HealthTest.php` (200, estrutura JSON) e `tests/Feature/CorsTest.php` (preflight para origem permitida/negada).

## Dockerfile (completo)

```dockerfile
FROM php:8.3-apache

LABEL maintainer="foco_api"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PORT=8080

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
# mbstring, xml, tokenizer, ctype, fileinfo já vêm na imagem base — não reinstalar.

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
```

> Se `composer.lock` não existir no repo, gerá-lo (`composer update`) e commitar — o build depende dele.
> Se o build falhar por `AllowOverride`/regex, validar com `docker run --rm foco-api apache2ctl -S`.

## docker-entrypoint.sh (completo)

```sh
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
```

## docker-compose.yml (desenvolvimento)

```yaml
services:
  app:
    build: .
    ports:
      - "8000:8080"          # o proxy.conf.json do front usa localhost:8000
    env_file: .env           # NÃO commitar .env
    environment:
      - RUN_MIGRATIONS=true
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  db:                         # Postgres local para dev (produção usa Neon)
    image: postgres:16-alpine
    profiles: ["local-db"]    # docker compose --profile local-db up
    environment:
      POSTGRES_DB: foco
      POSTGRES_USER: foco
      POSTGRES_PASSWORD: foco
    ports: ["5432:5432"]
    volumes: [pgdata:/var/lib/postgresql/data]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U foco -d foco"]
      interval: 5s
      timeout: 3s
      retries: 10

volumes:
  pgdata:
```

> `depends_on` com perfil: se usares Neon em dev, remove o `depends_on` ou usa `docker compose up app`. Documentar no README das duas formas.

## .env.example (substituir)

```dotenv
APP_NAME="Foco API"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_LOCALE=pt
APP_FALLBACK_LOCALE=pt
FRONTEND_URL=http://localhost:4200
CORS_ALLOWED_ORIGINS=http://localhost:4200

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

# --- Neon (produção) ou Postgres local (dev) ---
DB_CONNECTION=pgsql
DB_URL=            # postgresql://user:pass@ep-xxx-pooler.region.aws.neon.tech/db?sslmode=require
DB_DIRECT_URL=     # mesma BD, host SEM "-pooler" (migrations)
DB_SSLMODE=require # em dev local sem SSL: prefer
# Dev local com compose: DB_HOST=db DB_PORT=5432 DB_DATABASE=foco DB_USERNAME=foco DB_PASSWORD=foco (e DB_URL vazio)

SESSION_DRIVER=array
CACHE_STORE=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

SANCTUM_TOKEN_EXPIRATION=10080

# --- Email (Resend) ---
MAIL_MAILER=log            # produção: resend
RESEND_API_KEY=
MAIL_FROM_ADDRESS="onboarding@resend.dev"
MAIL_FROM_NAME="Foco"
MAIL_DAILY_LIMIT=90

# --- Tick externo ---
CRON_SECRET=               # string longa aleatória

# --- Arranque do container ---
RUN_MIGRATIONS=false
RUN_SEEDERS=false
SEED_ADMIN_NAME=Zua
SEED_ADMIN_EMAIL=
SEED_ADMIN_PASSWORD=
SEED_DEMO_DATA=false
```

## .dockerignore
Manter o atual e **acrescentar** `database/*.sqlite`, `storage/logs/*`, `docs`, `.github`.

## Critérios de aceitação
- `docker compose up --build` → `curl localhost:8000/api/health` devolve `status ok`.
- `docker run -e APP_NAME=Foco -e PORT=9000 …` → container escuta em 9000 e `/api/health` mostra `"app":"Foco"` (prova que lê variáveis de ambiente sem `.env`).
- Com `DB_URL` do Neon: `db: true`; `php artisan migrate --database=pgsql_direct` corre.
- Preflight CORS responde só para `CORS_ALLOWED_ORIGINS`.
- `composer test` verde.
