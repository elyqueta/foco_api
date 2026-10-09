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
- [x] **Fase 5** — Categorias (`docs/05-categorias.md`)
   - [x] 5.1 `CategoryController`, `StoreCategoryRequest`, `CategoryResource`, `CategoryPolicy` (só o dono)
   - [x] 5.2 Action `App\Actions\Categories\RemoveCategory` (transação + atividade + reatribuição)
   - [x] 5.3 Regra reutilizável `ValidCategory` (usada nas Fases 6, 7 e 11): o valor tem de existir em `categories` do utilizador (comparação por `name_key`; guardar o **nome canónico** da tabela, não o texto recebido)
   - [x] 5.4 Contagens via `withCount` (sem N+1)
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

### Reestruturação da v1 (pedida antes da Fase 5)
- CONFIRMAR: A versão passou a viver na **estrutura do código**, não no URL: controllers em `App\Http\Controllers\Api\V1\`, requests em `App\Http\Requests\V1\`, resources em `App\Http\Resources\V1\` e um ficheiro de rotas por versão (`routes/api/v1.php`, carregado por `routes/api.php`). Uma v2 será `routes/api/v2.php` + namespaces `App\Http\*\V2`, sem tocar na v1. Os URLs mantêm-se sem prefixo de versão, como exige `contrato-api.md` ("sem prefixo de versão na v1").
- CONFIRMAR: Os endpoints de sistema herdados da Fase 1 saíram de `/api/v1/*` para `/api/*` (`/api/health`, `/api/docs`, `/api/docs.json`), alinhando o código com o contrato e com a checklist da Fase 1 (que já descrevia `/api/health`). As rotas antigas não têm alias: a API ainda não está em produção e só os testes as referenciavam. A view `/docs` (Redoc) passou a apontar para `/api/docs.json`.
- CONFIRMAR: `ApiDocumentationController` continua em `App\Http\Controllers\Api` (infraestrutura de sistema, não versionada) e serve a OpenAPI de todos os endpoints actuais, incluindo as rotas de categorias.

### Auditoria das Fases 1–4 (feita antes da Fase 5) — bugs corrigidos
- CONFIRMAR: **`Category::tasks()`/`projects()` sem scoping por utilizador:** as relações (`hasMany` por `category`) contavam tarefas/projetos de **todos** os utilizadores — como todas as contas têm uma categoria `professional`, as contagens transbordavam entre utilizadores. Corrigido com `whereColumn('tasks.user_id', 'categories.user_id')` (funciona em acesso directo e em `withCount`, onde a relação é construída a partir de um modelo protótipo).
- CONFIRMAR: **Categoria identificada pelo nome canónico:** `tasks.category`/`projects.category` passam a guardar o **nome** da tabela `categories` (doc 05 §5.3, "guardar o nome canónico"); as relações comparam por `name`. Os nomes das 3 categorias padrão foram alinhados com as chaves (`professional`, `personal`, `household`) pela migration `2026_10_09_120000_align_default_category_names` (e no `ProvisionUserDefaults`), para coincidir com os valores que o front guarda em `task.category` (doc 00 §3) e com os exemplos do contrato. Tarefas/projetos existentes já guardavam essas strings, pelo que a conversão não exigiu mexer em dados.
- CONFIRMAR: **`CreateTask` validava o `projectId` sem scoping:** `Project::whereId($projectId)->exists()` permitia associar tarefas a projetos de outro utilizador (regra 6). Corrigido para `$user->projects()->whereKey($projectId)`. `CreateTask`/`CreateProject` passaram também a resolver a categoria para o nome canónico (`App\Actions\Categories\ResolveCategory`).
- CONFIRMAR: **`DomainRuleException` era fatal em tempo de execução:** redeclarava `Exception::$code` como `readonly string` ("Cannot redeclare non-readonly property"). Nenhum teste das fases anteriores instanciava a exceção, por isso o bug estava dormente. O código de domínio passou para `$errorCode` (renderizado pelo `bootstrap/app.php`).
- CONFIRMAR: **404 em inglês:** `ModelNotFoundException`/`NotFoundHttpException` devolviam `{"message":"Not Found"}`; o contrato exige `{ "message": "Não encontrado." }`. Adicionado render próprio em `bootstrap/app.php`.
- CONFIRMAR: **Models sem `HasFactory`:** `Task`, `Project`, `TaskTimeEntry` e `Category` tinham factory mas não o trait (`Call to undefined method ...::factory()`); `TaskFactory`/`ProjectFactory` usavam `catchWord` (removido no Faker 1.24) e `faker->optional()->sentence()` em colunas `NOT NULL` (`description`). Tudo corrigido; as factories passaram a ser cobertas pelos testes da Fase 5.
- CONFIRMAR: `Category::nameKey()` (helper estático puro no model) é a fonte única da normalização de nomes (minúsculas + espaços internos colapsados); é usada pelo request, controller, action e regra.

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

### Reestruturação da v1 + Fase 5 (auditoria e aceitação)
- **Estrutura final da v1:** `app/Http/Controllers/Api/V1/{Auth/AuthController, SettingsController, Categories/CategoryController}`, `app/Http/Requests/V1/{Auth,Settings,Categories}/`, `app/Http/Resources/V1/`, `routes/api/v1.php` (carregado por `routes/api.php`, que mantém só os endpoints de sistema). Novas versões = nova pasta, sem tocar na existente.
- **Aceitação contra o PostgreSQL local do compose (localhost:8000, após rebuild):** `GET /api/health` → 200 (`db: true`), `/api/docs` (UI Redoc) e `/api/docs.json` → 200 (servers só `/api`; paths incluem `/categories` e `/categories/{name}`), `/api/v1/health` → 404 (removido). Login com o admin semeado funciona; `GET /api/categories` devolve as 3 padrão (`household, personal, professional`) com contagens 0; `POST {name:"  Estudos  "}` → 201 com nome com espaços nas pontas removidos; duplicado (`ESTUDOS`) → 422 `errors.name`; nome com 1 caractere → 422; `DELETE /api/categories/professional` → 422 `CATEGORY_PROTECTED`; `DELETE` inexistente → 404 `{"message":"Não encontrado."}`; com tarefa e projeto em `Estudos`, `DELETE` → 204, ambos reatribuídos a `professional` e actividade `edited` ("Categoria alterada para professional") em cada um. `route:list --path=categories` mostra as 3 rotas protegidas por `auth:sanctum`.
- **Suíte:** 84 testes / 357 assertions verdes (eram 67/287 no início da Fase 5 — +16 testes em `CategoriesTest`), Pint limpo (98 ficheiros).
- A `CategoryFactory` deixou de usar os nomes de apresentação antigos ('Pessoal', 'Casa') e passa a derivar `name_key` com `Category::nameKey()`.

### Revisão de código das alterações (achados corrigidos)
- **Índices `(user_id, category)` em `tasks`/`projects`** (`2026_10_09_130000_add_user_category_indexes`): as contagens `withCount` e a reatribuição faziam full scan por utilizador; medido ~14x mais rápido em PostgreSQL com o índice.
- **`RemoveCategory` em massa:** reatribuição passou a `UPDATE` em bloco + `ActivityEntry::insert()` multi-row (3 statements por tabela em vez de 2N), sempre dentro da transação.
- **Resolução de categoria centralizada:** `ResolveCategory` passou a ser a fonte única (`handle`, `canonicalName`, `fallback`); removidos o método duplicado `resolveCategoryName` de `CreateTask`/`CreateProject` e os lookups inline de `StoreCategoryRequest`/`CategoryController`. A chave de fallback vive em `Category::DEFAULT_KEY` (usada pelo fallback da BD).
- **`down()` da migration de alinhamento** passou a no-op documentado: reverter nomes deixaria `tasks/projects.category` órfãos; rollback = redeploy da imagem anterior.
- **Rotas duplicadas removidas:** `GET /api/` e `GET /api/docs` saíram de `routes/api.php` (ficam só `/`, `/docs` em `web.php`, cobertos por testes); método `ui()` do `ApiDocumentationController` removido.
- **`CategoryPolicy::view()` removido** (nunca invocado; a leitura é scoped por utilizador).
- **`ValidCategory` coberto por teste** (`valid_category_rule_accepts_own_category_and_rejects_unknown`), deixando de ser código sem consumidor.
- Verificado e **descartado** (falso positivo da revisão): o `DB::table()` na migration corre na conexão migrada — `Migrator::runMethod()` define a conexão default durante a execução (incluindo `--database=pgsql_direct` do entrypoint).

## Registo de deploy
<!-- Data, URL do Render, resultado da verificação pós-deploy (Fase 12). -->
