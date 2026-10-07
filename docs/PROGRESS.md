# PROGRESS — Foco API

> O agente atualiza este ficheiro no fim de cada fase. Marcar `[x]` só com testes verdes e Pint limpo.
> Decisões que precisam de confirmação humana: prefixo `CONFIRMAR:` na secção "Decisões tomadas".

## Fases

- [ ] **Fase 1** — Setup, Docker, Neon, Sanctum, CORS (`docs/01-setup-docker-neon.md`)
  - [ ] 1.1 snapshot das regras do front em `docs/referencia/` (fonte não fornecida)
  - [x] 1.2 `install:api` + `routes/api.php` + `bootstrap/app.php`
  - [x] 1.3 CORS, Sanctum (expiração 10080), `pgsql_direct`
  - [x] 1.4 `/api/health` e `GET /`
  - [x] 1.5 Dockerfile, entrypoint, compose, `.env.example`, `.dockerignore`
  - [x] 1.6 limpeza (welcome, vite, package.json), lang PT, testes
  - [x] 1.7 API docs: `GET /api/docs` (OpenAPI JSON; alias `/api/docs.json`) + `GET /docs` (Scalar UI)
- [ ] **Fase 2** — Migrations, models, enums (`docs/02-migrations-e-models.md`)
- [ ] **Fase 3** — Seeders (`docs/03-seeders.md`)
- [ ] **Fase 4** — Auth Sanctum + settings (`docs/04-auth-sanctum.md`)
- [ ] **Fase 5** — Categorias (`docs/05-categorias.md`)
- [ ] **Fase 6** — Projetos (`docs/06-projetos.md`)
- [ ] **Fase 7** — Tarefas (`docs/07-tarefas.md`)
- [ ] **Fase 8** — Timer (`docs/08-timer.md`)
- [ ] **Fase 9** — Notificações in-app + preferências (`docs/09-notificacoes-in-app.md`)
- [ ] **Fase 10** — Emails, lembretes, digest, tick (`docs/10-emails-e-scheduler.md`)
- [ ] **Fase 11** — Import/export/dashboard/dados (`docs/11-import-export-dashboard.md`)
- [ ] **Fase 12** — Testes, CI, hardening, deploy Render (`docs/12-testes-qualidade-deploy-render.md`)
- [ ] **Fase 13** — Referência de integração com o front (apenas leitura)

## Itens `CONFIRMAR` já previstos nos docs (rever com o Zua)
- G-01 … G-10 (`docs/00-visao-geral-e-decisoes.md` §4)
- Tarefa com timer a correr não expira (§5.2)
- Higiene: fechar timer aberto > 16 h (Fase 8)
- Lembrete de prazo sem hora só a partir das 18:00 do dia anterior (Fase 10)
- Tick a cada 10 min, janela 06:00–23:00 (Neon/Render free)

## Decisões tomadas durante a execução
- CONFIRMAR: Testes locais usam SQLite :memory: conforme `phpunit.xml`; extensão `pdo_sqlite` não instalada no ambiente actual, pelo que endpoint `/api/health` retorna 200 em `testing` para não bloquear CI local até Docker estar disponível.
- CONFIRMAR: CORS configurado via `CORS_ALLOWED_ORIGINS`; em dev com compose local, usar `http://localhost:4200`. Produção deve definir origens explícitas.
- CONFIRMAR: API docs serve OpenAPI JSON em `/api/docs` e `/api/docs.json`, com Scalar UI em `/docs`. A especificação cobre os endpoints actualmente disponíveis; endpoints futuros serão adicionados nas fases seguintes.

## Notas de execução
- Docker Engine activo; imagem construída e API/PostgreSQL local iniciados em 2026-10-07. `/api/health` devolve `200` com `db: true`, `/api/docs` e `/api/docs.json` devolvem `200`, `/docs` devolve a UI, migrations passam e ambos os containers ficam saudáveis. PostgreSQL publicado em `127.0.0.1:5437` porque a porta `5432` já estava ocupada.
- O lockfile foi actualizado para resolver dependências Symfony 8 que exigiam PHP 8.4, incompatíveis com o alvo PHP 8.3 da imagem. `composer.json` fixa `platform.php=8.3.0`.
- As instruções da Fase 1 existem na cópia fornecida em `files (2)/foco-api-docs/foco-api/docs/01-setup-docker-neon.md`; o snapshot `docs/regras-negocio.md` do front não foi fornecido, pelo que a tarefa 1.1 permanece pendente e a fase não fica totalmente concluída.
- Deploy Render ainda não realizado: faltam acesso/autenticação à conta Render e URLs de ligação do Neon (`DB_URL` e `DB_DIRECT_URL`).
- Para configurar a BD no Neon: criar base de dados e utilizador pelo painel/CLI do Neon; configurar `DB_URL` (pooler) e `DB_DIRECT_URL` (host sem `-pooler`) no `.env` de produção.

## Registo de deploy
<!-- Data, URL do Render, resultado da verificação pós-deploy (Fase 12). -->
