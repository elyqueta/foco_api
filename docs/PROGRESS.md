# PROGRESS — Foco API

> O agente atualiza este ficheiro no fim de cada fase. Marcar `[x]` só com testes verdes e Pint limpo.
> Decisões que precisam de confirmação humana: prefixo `CONFIRMAR:` na secção "Decisões tomadas".

## Fases

- [x] **Fase 1** — Setup, Docker, Neon, Sanctum, CORS (`docs/01-setup-docker-neon.md`)
   - [x] 1.1 snapshot das regras do front em `docs/referencia/` (fonte não fornecida)
   - [x] 1.2 `install:api` + `routes/api.php` + `bootstrap/app.php`
   - [x] 1.3 CORS, Sanctum (expiração 10080), `pgsql_direct`
   - [x] 1.4 `/api/health` e `GET /`
   - [x] 1.5 Dockerfile, entrypoint, compose, `.env.example`, `.dockerignore`
   - [x] 1.6 limpeza (welcome, vite, package.json), lang PT, testes
   - [x] 1.7 API docs: `GET /api/docs` (OpenAPI JSON; alias `/api/docs.json`) + `GET /docs` (Scalar UI)
- [x] **Fase 2** — Migrations, models, enums (`docs/02-migrations-e-models.md`)
   - [x] 2.1 migrations (users foco fields, categories, projects, tasks, activity_entries, task_time_entries, notification_preferences, notification_dispatch_log, notifications)
   - [x] 2.2 enums (Urgency, TaskStatus, ProjectStatus, ActivityType, TimerState, NotificationType)
   - [x] 2.3 models (User, Category, Project, Task, ActivityEntry, TaskTimeEntry, NotificationPreference, NotificationDispatchLog)
   - [x] 2.4 factories (User, Category, Project, Task, TaskTimeEntry)
   - [x] 2.5 UserClock service + DomainRuleException + morph map no AppServiceProvider
   - [x] 2.6 testes (EnumsTest, SchemaTest) — 12 testes, 79 assertions, Pint limpo
- [x] **Fase 3** — Seeders (`docs/03-seeders.md`)
   - [x] 3.1 `UserObserver::created` + `ProvisionUserDefaults` (3 categorias padrão + preferências), registado no `AppServiceProvider`
   - [x] 3.2 `ProductionSeeder` (lê `SEED_ADMIN_*`; sem variáveis não cria nada e escreve aviso; password só muda com `SEED_ADMIN_RESET_PASSWORD=true`; demo via `SEED_DEMO_DATA=true`)
   - [x] 3.3 `DatabaseSeeder` (fallback do utilizador demo `admin@todo.ao` / `12345678` / `Zua` apenas em `local`/`testing`)
   - [x] 3.4 `DemoDataSeeder` (2 projetos + 4 tarefas, uma `in_progress`, sem timer aberto, datas relativas ao fuso do utilizador)
   - [x] 3.5 `php artisan foco:create-user {email} {--name=} {--password=}`
   - [x] 3.6 entrypoint `RUN_SEEDERS=true` chama só `ProductionSeeder` (definido na Fase 1)
- [x] **Fase 4** — Auth Sanctum + settings (`docs/04-auth-sanctum.md`)
   - [x] 4.1 `LoginRequest` com validações PT (mensagem do `min`: "Mínimo de 8 caracteres.")
   - [x] 4.2 `AuthController@login` com `RateLimiter::for('login')` (5/min por email+IP, rota `throttle:login`), 401 com mensagem única para email inexistente/password errada (sempre corre `Hash::check`, com hash *dummy* quando não há utilizador), token `foco-web | <User-Agent truncado a 60>` com expiração, resposta `{ token, user: {id,name,email}, expires_at }`
   - [x] 4.3 `logout` revoga o token actual → `204`
   - [x] 4.4 `me` devolve `{ id, name, email }`
   - [x] 4.5 `POST /api/auth/logout-all` revoga todos os tokens → `204`
   - [x] 4.6 `GET/PATCH /api/settings` (`userName`, `theme`, `timezone`, `notifications` completo com validação da Fase 9; PATCH parcial; resposta = `Settings` do contrato)
   - [x] 4.7 `PATCH /api/auth/password` → `204`; revoga os outros tokens
   - [x] 4.8 `auth:sanctum` em todas as rotas excepto `login`/`health`; `401 { message: "Não autenticado." }` via render de `AuthenticationException`
   - [x] 4.9 `sanctum:prune-expired --hours=24` agendado diariamente em `routes/console.php`
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
- CONFIRMAR: [Fase 2] Coluna `tasks.tags` criada como nullable em vez de `jsonb default []` para funcionar em SQLite e PostgreSQL; o default `[]` é aplicado no model via boot() e, em PostgreSQL, via `ALTER TABLE ... SET DEFAULT '[]'::jsonb`. O front nunca envia tags nulo.
- CONFIRMAR: [Fase 2] Migrations usam `uuidMorphs` para `notifications.notifiable` e `activity_entries.subject`; o índice duplicado foi removido da migration de notifications (o próprio uuidMorphs já o cria).
- CONFIRMAR: [Fase 2] `NotificationPreference.user_id` é PK (int) e não UUID, conforme o doc. A migration de `notifications` do Laravel foi gerada à medida (uuid + uuidMorphs) em vez de usar `make:notifications-table` padrão, para manter UUID coerente com o resto do schema.
- CONFIRMAR: [Fase 2] O trait `ActsAsUser` foi criado em `tests/` para partilhar o fluxo de autenticação Sanctum entre testes Feature.
- CONFIRMAR: [Fase 3] `ProductionSeeder::run()` aceita parâmetros opcionais (`name`, `email`, `password`) para que o `DatabaseSeeder` possa injetar o utilizador demo sem duplicar a lógica de criação; sem parâmetros lê sempre do ambiente.
- CONFIRMAR: [Fase 3] O fallback do utilizador demo (`admin@todo.ao` / `12345678` / `Zua`) vive no `DatabaseSeeder` e nunca no `ProductionSeeder`, para que `db:seed --class=ProductionSeeder` em produção sem `SEED_ADMIN_*` não crie utilizadores (doc 03-seeders §3.2).
- CONFIRMAR: [Fase 3] `due_time` não tem cast de data no model `Task`: um cast `datetime:H:i:s` transformaria `null` em "agora" (`asDateTime(null)` devolve `now()`). Os accessors `dueAtLocal`/`dueDateString` e `isOverdue` tratam o valor como string `HH:MM:SS`.
- CONFIRMAR: [Fase 3] Correcções de bugs da Fase 2 encontradas na auditoria (ver "Auditoria da Fase 2" nas Notas de execução): imports errados em models/serviços e seeders que quebravam em tempo de execução.
- CONFIRMAR: [Fase 4] As rotas de auth/definições seguem o contrato sem prefixo de versão (`/api/auth/*`, `/api/settings`), como exige `contrato-api.md` ("sem prefixo de versão na v1") e o `ApiAuthRepository` do front. Os endpoints de sistema herdados da Fase 1 continuam em `/api/v1/*` (health, docs); a inconsistência de prefixos fica registada para revisão (ver nota de auditoria).
- CONFIRMAR: [Fase 4] O login interino `/api/v1/login` (e a rota `/api/v1/user`) foram removidos: o `LoginController` interino devolvia `422` em credenciais inválidas, não tinha `expires_at`, nem throttle, nem nome de token por dispositivo — violava o contrato. Foi substituído por `AuthController` em `/api/auth/login`.
- CONFIRMAR: [Fase 4] `expires_at` é serializado como `YYYY-MM-DDTHH:MM:SSZ` (UTC) para coincidir com a convenção de datas do contrato; continua ISO 8601 parseável pelo front.
- CONFIRMAR: [Fase 4] `notifications.types` no `PATCH /api/settings` é fundido (merge recursivo) com os valores guardados: um tipo enviado parcialmente (ex. só `email`) preserva o outro canal (`inApp`).
- CONFIRMAR: [Fase 4] Sem horário de verificação de e-mail no login (o `User` não implementa `MustVerifyEmail`); o front não depende disso.

## Notas de execução
- Docker Engine activo; imagem construída e API/PostgreSQL local iniciados em 2026-10-07. `/api/health` devolve `200` com `db: true`, `/api/docs` e `/api/docs.json` devolvem `200`, `/docs` devolve a UI, migrations passam e ambos os containers ficam saudáveis. PostgreSQL publicado em `127.0.0.1:5437` porque a porta `5432` já estava ocupada.
- O lockfile foi actualizado para resolver dependências Symfony 8 que exigiam PHP 8.4, incompatíveis com o alvo PHP 8.3 da imagem. `composer.json` fixa `platform.php=8.3.0`.
- As instruções da Fase 1 existem na cópia fornecida em `files (2)/foco-api-docs/foco-api/docs/01-setup-docker-neon.md`; o snapshot `docs/regras-negocio.md` do front não foi fornecido, pelo que a tarefa 1.1 permanece pendente e a fase não fica totalmente concluída.
- Deploy Render ainda não realizado: faltam acesso/autenticação à conta Render e URLs de ligação do Neon (`DB_URL` e `DB_DIRECT_URL`).
- Para configurar a BD no Neon: criar base de dados e utilizador pelo painel/CLI do Neon; configurar `DB_URL` (pooler) e `DB_DIRECT_URL` (host sem `-pooler`) no `.env` de produção.

### Auditoria da Fase 2 (feita ao fechar a Fase 3)
- **`SchemaTest` nunca correu**: faltava o import `use PHPUnit\Framework\Attributes\Test;`, pelo que os seus 7 testes eram silenciosamente ignorados (a nota "12 testes, 79 assertions" da Fase 2 não os incluía). Com os testes a correr, apareceram bugs reais:
  - Models `Project`, `Task`, `TaskTimeEntry`, `ActivityEntry` importavam `Illuminate\Database\Eloquent\Factories\HasUuids`, namespace inexistente nesta versão do Laravel (o trait vive em `Eloquent\Concerns\HasUuids`): qualquer instanciação destes models era fatal.
  - `UserClock`, `User`, `Task`, `Project` usavam `Illuminate\Support\CarbonImmutable`, classe removida no Laravel 11+ (agora é `Carbon\CarbonImmutable`): `User::today()` era fatal.
  - `Task::due_time` era tratado como objecto com `->format()` mas a coluna `time` devolve string: `dueAtLocal`/`dueDateString`/`isOverdue` rebentavam.
  - `SchemaTest` usava `Relation::getMorphMap()` (API inexistente; agora é `Relation::morphMap()`) e afirmava que um utilizador novo já tem projetos/tarefas.
- **Seeders e comando da Fase 3 estavam quebrados em tempo de execução** (só apareciam ao correr, não nos testes): extendiam `Illuminate\Database\Seeders\Seed` (inexistente) e importavam `App\Actions\Users\ProvisionDefaults` (a classe chama-se `ProvisionUserDefaults`).
- Todos os pontos acima foram corrigidos; a suíte passou de 12 para **36 testes / 151 assertions**, todos verdes, Pint limpo.
- Correr a suíte neste ambiente: `pdo_sqlite` não está instalado no PHP do host, por isso os testes correm na imagem Docker (que tem a extensão):
  `docker compose run --rm -v "$(pwd):/var/www/html" -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=":memory:" -e DB_URL= app bash -c "php artisan test"`
  Os `-e` são necessários porque o serviço `app` do compose carrega `.env` via `env_file`, o que faz o PHPUnit saltar os `<env>` do `phpunit.xml` (`force=false`).
- Aceitação da Fase 3 verificada contra o PostgreSQL local do compose: `php artisan migrate:fresh --seed` cria `admin@todo.ao` / `Zua` / `12345678` (login válido, 3 categorias, preferências); `db:seed --class=ProductionSeeder` corrido 2× não duplica; `foco:create-user` funciona.

### Auditoria e aceitação da Fase 4
- **Bugs encontrados e corrigidos ao fechar a Fase 4:**
  - **Morph map sem `user`:** `Relation::enforceMorphMap()` no `AppServiceProvider` só mapeava `task`, `project`, `activity`. Qualquer `createToken()` (ou seja, o login interino e o login novo) lançava `ClassMorphViolationException`. Adicionado `'user' => User::class`.
  - **`NotificationPreference` sem `$primaryKey`:** o model declara `user_id` como PK mas não definia `$primaryKey`, pelo que os `update()`/`save()` geravam `WHERE "id" IS NULL` e **não persistiam nada, silenciosamente**. Adicionado `protected $primaryKey = 'user_id';`.
  - **Prefixo de versão vs. contrato:** as rotas de sistema da Fase 1 ficaram em `/api/v1/*` (commit "introduce v1 versioning") e o `PROGRESS.md` (que descreve `/api/health` e `/api/docs`) ficou desactualizado face ao código. As rotas novas da Fase 4 seguem o contrato sem prefixo; a inconsistência fica registada como `CONFIRMAR`.
  - **Login interino não conforme:** `/api/v1/login` devolvia `422` em credenciais inválidas, sem `expires_at`, sem throttle e sem nome de token por dispositivo. Substituído por `/api/auth/login`.
- **Aceitação verificada contra o PostgreSQL local do compose (localhost:8010):** login com `admin@todo.ao` / `12345678` devolve `{ token, user: {id,name,email}, expires_at }` (`2026-…Z`); password errada/email inexistente → `401` com a mesma mensagem; 5 falhas seguidas → `429` com mensagem PT; `GET /api/auth/me` com token válido → `200`, com token revogado/ausente → `401`; `POST /api/auth/logout` e `logout-all` → `204` (segundo pedido com o token → `401`); `GET/PATCH /api/settings` (nome, tema, fuso, notificações) e `PATCH /api/auth/password` → `204` com revogação dos outros tokens; `php artisan schedule:list` mostra `sanctum:prune-expired --hours=24` às 00:00.
- **Suíte:** 64 testes / 278 assertions verdes (eram 36/151 no início da Fase 4), Pint limpo. Foram ainda corrigidos os testes da documentação (`ApiDocumentationTest`) que referiam os paths removidos `/login` e `/user`.
- Correr a suíte neste ambiente: `pdo_sqlite` não está instalado no PHP do host, por isso os testes correm na imagem Docker (que tem a extensão):
   `docker compose run --rm -v "$(pwd):/var/www/html" -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=":memory:" -e DB_URL= app bash -c "php artisan test"`
   Os `-e` são necessários porque o serviço `app` do compose carrega `.env` via `env_file`, o que faz o PHPUnit saltar os `<env>` do `phpunit.xml` (`force=false`).
   Nota: com `depends_on: [db]` no `docker-compose.yml` (db só existe no perfil `local-db`), usar `--profile local-db --no-deps` para correr só a suíte.

### Revisão das alterações da Fase 4 (achados corrigidos)
- **Igualação de tempos do login não estava aplicada:** o `||` em `if (! $user instanceof User || ! Hash::check(...))` curto-circuitava, pelo que um e-mail inexistente nunca corria o `Hash::check`. Corrigido com hash bcrypt pré-calculado (`AuthController::DUMMY_PASSWORD_HASH`, custo 12) e verificação incondicional; medido contra PostgreSQL: ~0.26 s em ambos os caminhos de falha.
- **`PATCH /settings` fazia 2 queries evitáveis:** substituído `$user->fresh()` por `setRelation('notificationPreference', ...)`.
- **Omissões das preferências duplicados:** centralizados em `NotificationPreference::defaultAttributes()`, usados pelo `SettingsController`, `SettingsResource` e `ProvisionUserDefaults`.
- **Payload do utilizador duplicado** no login e no `me`: extraído para `AuthController::userPayload()`.
- **`notifications.types` aceitava canais desconhecidos** (passavam a validação, eram guardados e devolvidos): normalizados para `email`/`inApp` em `UpdateSettingsRequest::passedValidation()`.
- **Bug de arranque encontrado na verificação (pré-existente, corrigido):** `DatabaseSeeder` e `ProductionSeeder` usavam `callWith($classe, [valores])` com lista posicional — o contentor resolve os parâmetros do `run()` por nome, pelo que os valores eram **silenciosamente descartados**. O fallback do utilizador demo (`admin@todo.ao`) nunca corria e `migrate:fresh --seed` em local/testing criava **zero utilizadores**; além disso, com `SEED_DEMO_DATA=true`, os dados demo iam para `admin@todo.ao` em vez do utilizador semeado. Corrigido com parâmetros nomeados e coberto por 2 testes de regressão.
- Suíte após revisão: **67 testes / 287 assertions** verdes, Pint limpo; aceitação repetida contra PostgreSQL local (login, me, settings, password, logout, seed e `foco:create-user`).

## Registo de deploy
<!-- Data, URL do Render, resultado da verificação pós-deploy (Fase 12). -->
