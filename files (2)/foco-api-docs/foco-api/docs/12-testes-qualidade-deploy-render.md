# Fase 12 — Testes, qualidade, segurança e deploy no Render

## Objetivo
Fechar o back-end: cobertura de ponta a ponta, CI, hardening e deploy gratuito (Render + Neon + Resend + agendador externo).

## 12.1 Qualidade
- [ ] Teste de fluxo completo `tests/Feature/FullFlowTest.php`: login → criar categoria → projeto → tarefa → `timer/start` → `pause` → `resume` → `complete` → notificação `task_completed` in-app → `tick` sem duplicar → `export` → `import`.
- [ ] Teste de contrato: para cada recurso, comparar as **chaves** da resposta com `docs/contrato-api.md` (`assertJsonStructure`).
- [ ] Teste de isolamento (utilizador A × B) para **todas** as rotas com `{id}`: sempre `404`.
- [ ] Teste de N+1 nas listas de tarefas/projetos/dashboard (`DB::enableQueryLog`, máx. N queries constante).
- [ ] `vendor/bin/pint` (config padrão) e `composer test` sem avisos.
- [ ] Cobertura mínima desejada (se `pcov/xdebug` disponível): ≥ 80 % em `app/Actions`, `app/Services`.

## 12.2 CI (GitHub Actions) — `.github/workflows/ci.yml`
```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env: { POSTGRES_DB: foco_test, POSTGRES_USER: foco, POSTGRES_PASSWORD: foco }
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U foco" --health-interval 5s --health-timeout 3s --health-retries 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.3", extensions: "pdo_pgsql, pdo_sqlite, mbstring, bcmath", coverage: none }
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: vendor/bin/pint --test
      - run: composer test            # SQLite (phpunit.xml)
      - name: Migrations em Postgres
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: foco_test
          DB_USERNAME: foco
          DB_PASSWORD: foco
          DB_SSLMODE: prefer
        run: php artisan migrate:fresh --force && php artisan db:seed --force
```
(O passo Postgres valida que as migrations — incluindo índices parciais — funcionam fora do SQLite.)

## 12.3 Hardening (checklist)
- [ ] `APP_DEBUG=false`, `APP_ENV=production` no Render.
- [ ] Throttle: `login` (Fase 4), API geral `throttle:api` (60/min por utilizador), tick (`6,1`).
- [ ] Cabeçalhos de segurança: middleware simples que adiciona `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`; `Strict-Transport-Security` só em produção.
- [ ] CORS limitado a `CORS_ALLOWED_ORIGINS` (o domínio do front em produção + `http://localhost:4200`).
- [ ] Logs sem segredos (nada de `Authorization`, passwords, `RESEND_API_KEY`). `LOG_LEVEL=info`, `LOG_CHANNEL=stderr` no Render (logs aparecem no painel).
- [ ] Rever respostas de erro: sem stack trace nem SQL em JSON (garantir com `APP_DEBUG=false` e teste).
- [ ] `composer audit` sem vulnerabilidades altas.
- [ ] **Rotacionar** a `APP_KEY` que estava no `docker-compose.yml` e confirmar que nenhum `.env` está no Git (`git ls-files | grep -E '^\.env'`).
- [ ] Paginação/limites: `GET /api/notifications` `limit` ≤ 100; import ≤ 500 itens.

## 12.4 README do back-end
Reescrever `README.md` (substituir o do Laravel) com: o que é, stack, como correr local (Docker com e sem Postgres local, `.env`), variáveis de ambiente (tabela), comandos (`composer test`, `pint`, `foco:create-user`, `foco:tick`), link para `docs/`.

## 12.5 Deploy no Render (gratuito)

### Pré-requisitos
1. **Neon**: criar projeto/BD; copiar duas strings: **pooled** (`…-pooler…`) → `DB_URL`; **direct** → `DB_DIRECT_URL`. Ambas com `?sslmode=require`.
2. **Resend**: criar API key (`RESEND_API_KEY`). Sem domínio verificado, usar `MAIL_FROM_ADDRESS=onboarding@resend.dev` e o email do dono da conta como destinatário (confirmar regras atuais na documentação da Resend). Com domínio próprio, verificar DNS e usar um remetente desse domínio.
3. **Segredos** a gerar localmente: `php artisan key:generate --show` → `APP_KEY`; `openssl rand -hex 32` → `CRON_SECRET`.

### Web Service
- Tipo: **Web Service**, runtime **Docker**, repositório `foco-api`, branch `main`, plano **Free**.
- **Health Check Path:** `/up`.
- **Variáveis de ambiente:**

| Variável | Valor |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | (gerada) |
| `APP_URL` | `https://<servico>.onrender.com` |
| `FRONTEND_URL` | URL pública do front |
| `CORS_ALLOWED_ORIGINS` | URL do front (separar por vírgulas) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | string pooled do Neon |
| `DB_DIRECT_URL` | string direct do Neon |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` | `array` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `info` |
| `MAIL_MAILER` | `resend` |
| `RESEND_API_KEY` | (da Resend) |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | remetente / `Foco` |
| `MAIL_DAILY_LIMIT` | `90` |
| `CRON_SECRET` | (gerado) |
| `RUN_MIGRATIONS` | `true` |
| `RUN_SEEDERS` | `true` (1.º deploy; depois pode ficar — é idempotente) |
| `SEED_ADMIN_NAME` / `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | credenciais do utilizador (password ≥ 8, forte) |
| `SEED_DEMO_DATA` | `false` |
| `PORT` | (injetada pelo Render — não definir) |

> O Render free **não tem Shell**: migrations e seeders correm no arranque (entrypoint). Alternativa: executar `php artisan migrate --force --database=pgsql_direct` e `php artisan db:seed --class=ProductionSeeder --force` **na tua máquina** com `DB_DIRECT_URL`/`SEED_ADMIN_*` apontados ao Neon.

### Agendador externo (tick) — grátis
- **cron-job.org** → novo cron: URL `https://<servico>.onrender.com/api/internal/scheduler/tick`, método **POST**, header `X-Cron-Secret: <CRON_SECRET>`, intervalo **10 min**, janela **06:00–23:00** (fuso `Africa/Luanda`), timeout 30 s, "notify on failure". O primeiro pedido pode demorar (cold start); não é erro.
- Alternativa: workflow GitHub Actions `schedule` (ver Fase 10).
- Smoke test: `curl -i -X POST -H "X-Cron-Secret: $CRON_SECRET" https://<servico>.onrender.com/api/internal/scheduler/tick` → `200` com `"ok": true`.

### Verificação pós-deploy
1. `GET /api/health` → `db: true`.
2. `POST /api/auth/login` com o utilizador semeado → token.
3. Criar tarefa com prazo daqui a ~50 min com hora → ao tick seguinte chega `task_due_imminent` (in-app + email).
4. Criar tarefa, iniciar/pausar timer, concluir → notificação `task_completed`.
5. Chamar o tick duas vezes seguidas → sem duplicados.

## 12.6 Rollback / operação
- Deploy anterior: "Rollback" no painel do Render. Migrations são **aditivas**; nunca editar migrations já aplicadas — criar novas.
- Backup: `GET /api/export` periódico (o front tem botão "Exportar"); Neon tem restauro por ponto no tempo conforme o plano — confirmar limites.
- Monitorização: logs do Render; `cron-job.org` alerta se o tick falhar.

## Aceitação
- CI verde (SQLite + migrations em Postgres).
- Checklist 12.3 completa.
- Deploy no Render com verificação pós-deploy passada e registada em `docs/PROGRESS.md` (data, URL, observações).
