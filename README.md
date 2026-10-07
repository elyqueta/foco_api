# Foco API

Back-end Laravel 13 / PHP 8.3 para o projeto Foco.

## Estado atual

- Implementação da Fase 1: setup Docker, Neon-ready, Sanctum, CORS, `/api/health` e documentação OpenAPI.
- Testes: `composer test` verde; formatação `vendor/bin/pint --test` limpa.
- Docker Engine verificado: a API e PostgreSQL local estão a correr via Compose em `http://localhost:8000`.
- Health check: `/api/health` devolve `db: true`; documentação OpenAPI em `/api/docs` e UI em `/docs`.
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

- OpenAPI JSON: `GET http://localhost:8000/api/docs` (alias: `/api/docs.json`)
- UI: abrir `http://localhost:8000/docs`

A especificação OpenAPI descreve os endpoints actualmente disponíveis e é expandida
à medida que as fases seguintes implementam novas rotas.

## Testes

```sh
composer test
vendor/bin/pint --test
```

## Docker

```sh
docker compose --profile local-db up --build
```

Acessível em `http://localhost:8000`.
O PostgreSQL local é publicado em `127.0.0.1:5437` para não conflituar com instalações existentes na porta `5432`.

## Stack

- Laravel 13, Sanctum, PostgreSQL (Neon)
- Resend (email), sync queue
- Docker + Apache (Render ready)
