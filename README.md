# Foco API

Back-end Laravel 13 / PHP 8.3 para o projeto Foco.

## Estado atual

- Fases 1–5 concluídas: setup Docker/Neon/Sanctum/CORS, auth (login, registo, perfil, password), definições, categorias e import/export-ready.
- API versionada: rotas de domínio em `/api/v1/*` (estrutura `routes/api/v1.php` + namespaces `App\Http\*\V1`); sistema (health, docs) sem versão em `/api/*`.
- Testes: 119 verdes; formatação `vendor/bin/pint --test` limpa.
- Docker Engine verificado: a API e PostgreSQL local estão a correr via Compose em `http://localhost:8000`.
- Health check: `/api/health` devolve `db: true`; documentação OpenAPI em `/api/docs.json` e UI em `/docs`.
- O snapshot das regras do front continua pendente: o ficheiro de origem não foi fornecido em `files (2)`.

## Requisitos

- PHP 8.3
- Composer
- PostgreSQL (Neon em produção; local ou Docker em dev)

## Execução local

```sh
# 1) Copiar variáveis
cp .env.example .env

# 2) Ajustar .env para BD local ou Neon
#   - Neon: preencher DB_URL e DB_DIRECT_URL
#   - Local: usar docker compose --profile local-db up -d

# 3) Migrations
php artisan migrate --force

# 4) Servidor
php artisan serve
```

Health check: `GET http://localhost:8000/api/health`

## API docs

- OpenAPI JSON: `GET http://localhost:8000/api/docs.json`
- UI (Redoc): abrir `http://localhost:8000/docs`

A especificação descreve a versão v1 (rotas em `/api/v1/*`) e o catálogo de erros da API; é
expandida à medida que as fases seguintes implementam novas rotas.

## Testes

No host pode não existir a extensão `pdo_sqlite` — a suíte corre dentro do container
(ver a secção Docker). Se o host tiver as extensões:

```sh
composer test
vendor/bin/pint --test
```

## Docker

> **Importante:** o serviço `db` só existe no profile `local-db`, por isso **todos** os comandos
> `docker compose` precisam de `--profile local-db`. Sem isso o comando falha com
> `service "db" is not enabled` / `depends_on` não resolvido.

Serviços: `app` (API em `http://localhost:8000`) e `db` (PostgreSQL em `127.0.0.1:5437`, utilizador/password/base `foco`).

### Subir / parar

```sh
# Subir tudo (primeira vez, ou após alterar Dockerfile/dependências)
docker compose --profile local-db up -d --build

# Subir sem rebuild (mais rápido, no dia-a-dia)
docker compose --profile local-db up -d

# Ver estado e saúde dos contentores
docker compose --profile local-db ps

# Parar (mantém dados do volume pgdata)
docker compose --profile local-db stop

# Reiniciar só a app (útil após restaurar a DB)
docker compose --profile local-db restart app

# Parar e remover contentores/redes (mantém dados do volume)
docker compose --profile local-db down

# APAGAR TAMBÉM OS DADOS da base local (irreversível)
docker compose --profile local-db down -v
```

O entrypoint corre `migrate --force` a cada arranque da app (`RUN_MIGRATIONS=true`), por isso
subir o container aplica migrations pendentes automaticamente.

### Logs

```sh
# Logs da app em tempo real (erros PHP + acessos Apache)
docker compose --profile local-db logs -f app

# Só as últimas 100 linhas da app
docker compose --profile local-db logs --tail=100 app

# Logs da base de dados
docker compose --profile local-db logs -f db

# Erros da aplicação Laravel (dentro do contentor)
docker compose --profile local-db exec app sh -c 'tail -n 50 storage/logs/laravel.log'
```

### Comandos artisan dentro do contentor

```sh
docker compose --profile local-db exec app php artisan <comando>

# Exemplos do dia-a-dia
docker compose --profile local-db exec app php artisan route:list              # rotas registadas
docker compose --profile local-db exec app php artisan route:list --path=v1    # filtrar por path
docker compose --profile local-db exec app php artisan migrate --force         # migrations
docker compose --profile local-db exec app php artisan migrate:fresh --seed    # recriar BD local com dados demo
docker compose --profile local-db exec app php artisan tinker                  # REPL do Laravel
docker compose --profile local-db exec app php artisan foco:create-user email@exemplo.ao --name=Nome
```

### Base de dados

```sh
# psql diretamente no contentor
docker compose --profile local-db exec db psql -U foco -d foco

# Parar só a DB (útil para testar o erro 503 DATABASE_ERROR)
docker compose --profile local-db stop db
docker compose --profile local-db start db
```

> Se a DB ficar parada, a app pode entrar em *restart loop* (`restart: unless-stopped` +
> `depends_on`) e o `/api/health` deixa de responder. Depois de voltar a ligar a DB, recupera com
> `docker compose --profile local-db restart app`.

### Testes e formatação

O host pode não ter a extensão `pdo_sqlite`; a suíte corre dentro do contentor com SQLite em memória.
Os `-e` são necessários porque o serviço `app` carrega `.env` via `env_file`, o que faz o PHPUnit
saltar os `<env>` do `phpunit.xml`.

```sh
docker compose --profile local-db run --rm --no-deps \
  -v "$(pwd):/var/www/html" \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=":memory:" -e DB_URL= \
  app bash -c "php artisan test"

# Só um ficheiro/método de testes
docker compose --profile local-db run --rm --no-deps \
  -v "$(pwd):/var/www/html" \
  -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=":memory:" -e DB_URL= \
  app bash -c "php artisan test --filter CategoriesTest"

# Pint (formatação/estilo)
docker compose --profile local-db run --rm --no-deps \
  -v "$(pwd):/var/www/html" \
  app bash -c "vendor/bin/pint"
```

> Após **alterar código**, é preciso `up -d --build` para que o container use a versão nova
> (a imagem copia o código no build). Os comandos `run` acima montam a directoria do projecto,
> por isso correm sempre o código actual.

## Stack

- Laravel 13, Sanctum, PostgreSQL (Neon)
- Resend (email), sync queue
- Docker + Apache (Render ready)
