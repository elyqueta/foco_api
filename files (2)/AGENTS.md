# AGENTS.md — Foco API (back-end)

> Lê este ficheiro INTEIRO e depois `docs/00-visao-geral-e-decisoes.md` antes de escrever código.
> Executa as fases por ordem (`docs/01…` a `docs/13…`). Não improvises o contrato: ele está em `docs/contrato-api.md`.
> **Não corras `php artisan boost:install` nem `composer require laravel/boost`** — o Boost reescreve este ficheiro.
> O `CLAUDE.md` apenas aponta para aqui.

---

## 0. REGRAS ABSOLUTAS

1. **Fonte de verdade das regras de negócio:** `docs/regras-negocio.md` do repositório front-end (`foco`). Em conflito, ele prevalece sobre o código. Na Fase 1 copia um snapshot para `docs/referencia/regras-negocio-front.md` (com data no topo). Regras novas do back-end estão em `docs/00-visao-geral-e-decisoes.md` (secção "Regras adicionais").
2. **Nunca pares a pedir confirmação durante a execução noturna.** Se algo não estiver especificado, escolhe a opção mais simples e segura, implementa e regista em `docs/PROGRESS.md` → "Decisões tomadas" com o prefixo `CONFIRMAR:`.
3. **Cada fase só termina com:** `composer test` verde + `vendor/bin/pint --test` limpo + checklist da fase marcada em `docs/PROGRESS.md`. Não avances com testes vermelhos.
4. **Ficheiros completos.** Nunca entregues "// resto igual".
5. **Zero segredos no repositório.** Chaves, passwords e tokens só em variáveis de ambiente. `.env` nunca é commitado.
6. Todo o dado pertence a um utilizador (`user_id`). Nenhuma query sem scoping por utilizador autenticado; usar Policies.
7. Idioma: mensagens de erro/validação/emails em **português (Angola/Portugal)**; código, nomes de classes, colunas e rotas em **inglês**.

---

## 1. CONTEXTO

Dois repositórios formam o projeto full stack:

| Repo | Papel | Stack |
|---|---|---|
| `foco` | Front-end | Angular 22 standalone, signals, Tailwind 3.4, hoje com `localStorage` + auth mock |
| `foco-api` (este) | Back-end | Laravel 13, PHP 8.3, PostgreSQL (Neon), Sanctum, Docker, deploy no Render |

O front espera (ver `src/app/core/auth/*` e `environment.apiBaseUrl = '/api'`): `POST /api/auth/login` → `{ token, user, expires_at }`, `POST /api/auth/logout`, `GET /api/auth/me`, token `Bearer`, erros `422 { errors: { email: [...], password: [...] } }`, `401`, `429`.

---

## 2. STACK FECHADA (não trocar)

- Laravel 13 / PHP 8.3 (`composer.json` já define). Testes com **PHPUnit** (o projeto não usa Pest). Formatação com **Pint**.
- **PostgreSQL no Neon** em produção. Testes em SQLite `:memory:` (`phpunit.xml` já define) → migrations devem funcionar em ambos (sem SQL específico de Postgres, exceto índices parciais que ambos suportam).
- **Laravel Sanctum** (tokens pessoais, expiração 7 dias = 10080 min).
- **Email: Resend** (mailer `resend`, pacote `resend/resend-php`). Plano gratuito.
- **Scheduler gratuito:** endpoint protegido `POST /api/internal/scheduler/tick` chamado por um serviço externo gratuito (cron-job.org; alternativa GitHub Actions `schedule`). Sem worker nem Cron Job pago do Render. `QUEUE_CONNECTION=sync`.
- **Docker** `php:8.3-apache`, porta via `PORT` (Render injeta), health check `/up`.
- Fuso horário de negócio por utilizador (`users.timezone`, default `Africa/Luanda`). `config/app.php` fica em UTC; "hoje" calcula-se sempre no fuso do utilizador.

---

## 3. CONVENÇÕES DE CÓDIGO

- `declare(strict_types=1);` em todos os ficheiros novos.
- Enums PHP (backed) em `app/Enums`: `Urgency`, `TaskStatus`, `ProjectStatus`, `ActivityType`, `NotificationType`, `TimerState`.
- Controllers finos → **Form Requests** (validação) → **Actions/Services** (regras) → **API Resources** (saída).
- `app/Actions/*` para casos de uso (`CompleteTask`, `PostponeTask`, `StartTimer`…). Um método público `handle()`.
- Resources devolvem **camelCase** (contrato do front). `JsonResource::withoutWrapping()`. Listas = arrays simples (sem paginação na v1).
- IDs: `users.id` bigint (o front tipa `id: number`); `projects`, `tasks`, `activity_entries`, `task_time_entries` = **UUID** (`HasUuids`).
- Datas: ver "Prazos" em `docs/00-…`. Timestamps em UTC, serializados ISO 8601.
- Transações (`DB::transaction`) em qualquer operação que escreva mais de uma tabela (ex.: mudar estado + atividade + timer).
- Erros de domínio: exceção `DomainException` própria (`App\Exceptions\DomainRuleException`) com `code` + `message` PT → renderizada como `422` (ou `409` para conflitos de timer). Ver códigos em `docs/contrato-api.md`.
- Sem `any`-equivalentes: tipar parâmetros/retornos. Sem lógica de negócio em Models além de casts/relations/scopes.
- Commits pequenos por fase: `feat(fase-04): auth sanctum`.

---

## 4. ESTRUTURA ALVO

```
app/
  Actions/{Tasks,Projects,Categories,Timer,Notifications,Import}/
  Console/Commands/            # foco:create-user, foco:expire-overdue, foco:send-reminders, foco:send-digests, foco:check-timers
  Enums/
  Exceptions/DomainRuleException.php
  Http/Controllers/Api/
  Http/Requests/
  Http/Resources/
  Mail/                        # mailables (markdown, PT)
  Models/                      # User, Category, Project, Task, ActivityEntry, TaskTimeEntry, NotificationPreference, NotificationDispatchLog
  Notifications/               # classes por tipo (canais separados: database / mail)
  Policies/
  Services/                    # UserClock, TaskStateMachine, NotificationDispatcher, TickService
database/{migrations,factories,seeders}/
routes/{api.php,console.php}
docs/ …
```

---

## 5. FASES

| # | Ficheiro | Resultado |
|---|---|---|
| 1 | `docs/01-setup-docker-neon.md` | API a arrancar em Docker, ligada ao Neon, `/api/health`, CORS, Sanctum instalado |
| 2 | `docs/02-migrations-e-models.md` | Schema completo + models + enums |
| 3 | `docs/03-seeders.md` | Seeders idempotentes (admin, categorias, demo) |
| 4 | `docs/04-auth-sanctum.md` | login/logout/me, throttle, settings do utilizador |
| 5 | `docs/05-categorias.md` | CRUD de categorias com regras |
| 6 | `docs/06-projetos.md` | CRUD de projetos, notas, atividade |
| 7 | `docs/07-tarefas.md` | CRUD de tarefas, estados, adiar, concluir, expirar, filtros |
| 8 | `docs/08-timer.md` | Início, pausa, retoma, totais de tempo |
| 9 | `docs/09-notificacoes-in-app.md` | Notificações in-app, preferências |
| 10 | `docs/10-emails-e-scheduler.md` | Emails, lembretes, digest, tick gratuito |
| 11 | `docs/11-import-export-dashboard.md` | Import/export, dashboard, reset/seed demo |
| 12 | `docs/12-testes-qualidade-deploy-render.md` | Hardening, CI, deploy no Render |
| 13 | `docs/13-integracao-frontend.md` | Alterações necessárias no front (referência; não implementar aqui) |

Contrato completo: `docs/contrato-api.md`. Progresso: `docs/PROGRESS.md`.

---

## 6. FLUXO DE TRABALHO POR FASE

1. Ler o doc da fase + `docs/contrato-api.md`.
2. Implementar na ordem das tarefas. Criar testes (Feature) **junto** com o código.
3. `composer test` e `vendor/bin/pint` (corrigir).
4. Marcar a checklist em `docs/PROGRESS.md`; anotar decisões `CONFIRMAR:`.
5. Commit. Só então a fase seguinte.

Comandos úteis: `composer test`, `vendor/bin/pint`, `php artisan migrate:fresh --seed`, `php artisan route:list --path=api`.

---

## 7. SEGURANÇA (resumo; detalhe na Fase 12)

- Login com throttle (5 tentativas/min por email+IP → 429). Sem rota de registo (app pessoal): utilizadores criados por `foco:create-user`/seeder.
- Tokens Sanctum com expiração; logout revoga o token atual.
- `APP_DEBUG=false` em produção; `trustProxies` ativo (Render está atrás de proxy HTTPS).
- CORS restrito a `CORS_ALLOWED_ORIGINS`.
- Endpoint interno de tick: header `X-Cron-Secret` comparado com `hash_equals`, throttle próprio, sem dados sensíveis na resposta.
- A `APP_KEY` que está commitada em `docker-compose.yml` **deve ser rotacionada** e removida do repositório (ver Fase 1).

---

## 8. DEFINITION OF DONE (projeto)

- [ ] `docker compose up` local sobe a API e responde a `/api/health` com ligação à BD.
- [ ] Todos os endpoints de `docs/contrato-api.md` existem e têm testes.
- [ ] Fluxo completo: login → criar categoria → projeto → tarefa → iniciar/pausar/retomar timer → concluir → notificação in-app criada → email enviado (log/Resend).
- [ ] `POST /api/internal/scheduler/tick` é idempotente (chamar 2× seguidas não duplica notificações/emails).
- [ ] `composer test` verde, Pint limpo.
- [ ] Deploy no Render a funcionar com Neon + Resend + cron externo (Fase 12).
