# PROGRESS — Foco API

> O agente atualiza este ficheiro no fim de cada fase. Marcar `[x]` só com testes verdes e Pint limpo.
> Decisões que precisam de confirmação humana: prefixo `CONFIRMAR:` na secção "Decisões tomadas".

## Fases

- [ ] **Fase 1** — Setup, Docker, Neon, Sanctum, CORS (`docs/01-setup-docker-neon.md`)
  - [ ] 1.1 snapshot das regras do front em `docs/referencia/`
  - [ ] 1.2 `install:api` + `routes/api.php` + `bootstrap/app.php`
  - [ ] 1.3 CORS, Sanctum (expiração 10080), `pgsql_direct`
  - [ ] 1.4 `/api/health` e `GET /`
  - [ ] 1.5 Dockerfile, entrypoint, compose, `.env.example`, `.dockerignore`
  - [ ] 1.6 limpeza (welcome, vite, package.json), lang PT, testes
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
<!-- Formato: - [Fase N] CONFIRMAR: <decisão> — <motivo> -->

## Notas de execução
<!-- Problemas encontrados, desvios, TODOs. -->

## Registo de deploy
<!-- Data, URL do Render, resultado da verificação pós-deploy (Fase 12). -->
