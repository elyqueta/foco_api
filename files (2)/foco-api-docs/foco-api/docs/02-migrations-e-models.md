# Fase 2 — Migrations, Models e Enums

## Objetivo
Schema completo, models com relações/casts, enums, factories. Compatível com PostgreSQL (Neon) **e** SQLite (testes).

## Regras gerais
- Nome dos ficheiros: `2026_10_05_0000NN_create_<tabela>_table.php` (ordem abaixo).
- UUID: `$table->uuid('id')->primary()` + trait `HasUuids` no model.
- `user_id`: `foreignId('user_id')->constrained()->cascadeOnDelete()`.
- Strings que representam enums: `string(20)` + cast para enum PHP (sem `CHECK`, para simplificar SQLite).
- Timestamps com fuso: `timestampTz`. `created_at/updated_at` via `timestampsTz()`.
- Não alterar as migrations default do Laravel (`users`, `cache`, `jobs`) — acrescentar migrations novas.

## Ordem e tabelas

### 1. `add_foco_fields_to_users_table`
| coluna | tipo | nota |
|---|---|---|
| `timezone` | string(64) default `Africa/Luanda` | validar contra `DateTimeZone::listIdentifiers()` |
| `theme` | string(10) default `light` | `light|dark` |

User model: acrescentar a `#[Fillable([...])]` os campos `timezone`, `theme`; `use HasApiTokens` (Sanctum); relações `categories()`, `projects()`, `tasks()`, `notificationPreference()`; método `today(): CarbonImmutable` e `nowLocal()` (via `UserClock`).

### 2. `create_categories_table`
| coluna | tipo |
|---|---|
| `id` | bigIncrements |
| `user_id` | FK |
| `name` | string(60) |
| `name_key` | string(60) (minúsculas, sem espaços extra) |
| `is_default` | boolean default false |
| timestamps | |
Índice único `(user_id, name_key)`.

### 3. `create_projects_table`
| coluna | tipo |
|---|---|
| `id` | uuid PK |
| `user_id` | FK |
| `name` | string(255) |
| `description` | text default '' |
| `category` | string(60) |
| `urgency` | string(10) default `medium` |
| `status` | string(10) default `active` |
| `can_postpone` | boolean default true |
| `due_date` | date nullable |
| `next_step` | string(255) default '' |
| `color` | string(7) default `#6C5CE7` |
| `overdue_notified_at` | timestampTz nullable |
| timestampsTz | |
Índices: `(user_id, status)`, `(user_id, due_date)`.

### 4. `create_tasks_table`
| coluna | tipo |
|---|---|
| `id` | uuid PK |
| `user_id` | FK |
| `project_id` | `foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete()` (apagar projeto apaga tarefas) |
| `title` | string(255) |
| `description` | text default '' |
| `category` | string(60) |
| `urgency` | string(10) default `medium` |
| `status` | string(15) default `todo` |
| `can_postpone` | boolean default true |
| `due_date` | date nullable |
| `due_time` | time nullable (só com `due_date`) |
| `next_step` | string(255) default '' |
| `estimate_minutes` | unsignedInteger nullable |
| `tags` | jsonb default `[]` (cast `array`) |
| `tracked_seconds` | unsignedBigInteger default 0 (soma das entradas **fechadas**) |
| `first_started_at` | timestampTz nullable |
| `completed_at` | timestampTz nullable |
| `expired_at` | timestampTz nullable |
| `postponed_count` | unsignedSmallInteger default 0 |
| timestampsTz | |
Índices: `(user_id, status)`, `(user_id, due_date)`, `(project_id)`.

### 5. `create_activity_entries_table`
| coluna | tipo |
|---|---|
| `id` | uuid PK |
| `user_id` | FK |
| `subject_type`, `subject_id` | `uuidMorphs('subject')` (morph map: `task`, `project`) |
| `type` | string(30) |
| `message` | text |
| `at` | timestampTz |
| `created_at` | timestampTz |
Índice `(subject_type, subject_id, at)`. Registar `Relation::enforceMorphMap(['task' => Task::class, 'project' => Project::class])` no `AppServiceProvider`.

### 6. `create_task_time_entries_table`
| coluna | tipo |
|---|---|
| `id` | uuid PK |
| `user_id` | FK |
| `task_id` | `foreignUuid` cascadeOnDelete |
| `started_at` | timestampTz |
| `ended_at` | timestampTz nullable |
| `ended_reason` | string(15) nullable (`pause|auto_pause|complete|postpone|reset`) |
| `created_at` | timestampTz |
Índices: `(task_id, started_at)`.
**Índices parciais** (funcionam em Postgres e SQLite) — garantem 1 timer aberto por tarefa e por utilizador:

```php
DB::statement('CREATE UNIQUE INDEX task_time_entries_one_open_per_task ON task_time_entries (task_id) WHERE ended_at IS NULL');
DB::statement('CREATE UNIQUE INDEX task_time_entries_one_open_per_user ON task_time_entries (user_id) WHERE ended_at IS NULL');
```
`down()` faz `DROP INDEX` correspondente.

### 7. `create_notification_preferences_table`
| coluna | tipo |
|---|---|
| `user_id` | PK + FK |
| `email_enabled` | boolean default true |
| `in_app_enabled` | boolean default true |
| `digest_hour` | unsignedTinyInteger default 8 (0–23, hora local) |
| `due_soon_hours` | unsignedSmallInteger default 24 |
| `due_imminent_minutes` | unsignedSmallInteger default 60 |
| `timer_long_hours` | unsignedTinyInteger default 4 |
| `types` | jsonb default `{}` (overrides `{ "task_completed": {"email": false, "inApp": true} }`) |
| timestampsTz | |

### 8. `create_notification_dispatch_log_table`
| coluna | tipo |
|---|---|
| `id` | bigIncrements |
| `user_id` | FK |
| `type` | string(40) |
| `channel` | string(10) (`in_app|email`) |
| `subject_type` | string(10) nullable |
| `subject_id` | uuid nullable |
| `dedupe_key` | string(120) |
| `status` | string(10) (`pending|sent|failed|skipped`) |
| `attempts` | unsignedTinyInteger default 0 |
| `error` | text nullable |
| `sent_at` | timestampTz nullable |
| timestampsTz | |
Único `(user_id, type, channel, subject_id, dedupe_key)` (usar `coalesce` não é necessário: guardar `subject_id` sempre — para digest usar o UUID nulo `00000000-0000-0000-0000-000000000000`).
Índice `(status, created_at)` e `(channel, sent_at)` (contagem diária de emails).

### 9. Tabela `notifications` do Laravel
`php artisan make:notifications-table` (gera a migration padrão: uuid, type, morphs notifiable, data, read_at). Mantê-la tal como está.

## Enums (`app/Enums`)

```php
enum Urgency: string { case Critical='critical'; case High='high'; case Medium='medium'; case Low='low';
    public function order(): int { return match($this){ self::Critical=>0, self::High=>1, self::Medium=>2, self::Low=>3 }; }
    public function label(): string { /* Crítica, Alta, Média, Baixa */ } }
enum TaskStatus: string { case Todo='todo'; case InProgress='in_progress'; case Done='done'; case Postponed='postponed'; case Expired='expired';
    public function label(): string { /* A fazer, Em curso, Concluída, Adiada, Expirada */ } }
enum ProjectStatus: string { case Active='active'; case Paused='paused'; case Done='done'; }
enum ActivityType: string { case Created='created'; case StatusChanged='status_changed'; case Note='note'; case Postponed='postponed';
    case Edited='edited'; case NextStepChanged='next_step_changed'; case Expired='expired';
    case TimerStarted='timer_started'; case TimerPaused='timer_paused'; case TimerResumed='timer_resumed'; case TimerStopped='timer_stopped'; }
enum TimerState: string { case Idle='idle'; case Running='running'; case Paused='paused'; case Stopped='stopped'; }
enum NotificationType: string { /* ver Fase 9 */ }
```

## Models
- `Category`, `Project`, `Task`, `ActivityEntry`, `TaskTimeEntry`, `NotificationPreference`, `NotificationDispatchLog`.
- `Project` e `Task`: `HasUuids`, casts (`urgency`→`Urgency`, `status`→enum, `due_date`→`date:Y-m-d`, `tags`→`array`, booleans), relações (`user`, `project`/`tasks`, `activity()` morphMany ordenada por `at` desc, `timeEntries()`).
- `Task`: accessor `due_at_local` (combina `due_date`+`due_time` em `CarbonImmutable` no fuso do utilizador), accessor `due_date_string` (formato do contrato), scopes `forUser`, `open()` (`todo|in_progress|postponed`), `active()` (≠done, ≠expired).
- Global scope **não** — usar `Task::query()->where('user_id', …)` via policies/`$request->user()->tasks()`.
- `ActivityEntry`: `$timestamps = false` (usa `at`/`created_at`), cast `type`→`ActivityType`.

## Factories
`UserFactory` (já existe; acrescentar `timezone`), `CategoryFactory`, `ProjectFactory`, `TaskFactory` (states: `done()`, `expired()`, `critical()`, `withDue(date,time?)`), `TaskTimeEntryFactory`.

## Serviços base (criar já)
- `App\Services\UserClock`: `today(User): CarbonImmutable` (início do dia no fuso), `now(User)`, `toUtc(date,time,User)`.
- `App\Exceptions\DomainRuleException(string $code, string $message, int $status = 422)` + render no `bootstrap/app.php`: `{ "message": "...", "code": "..." }`.

## Testes
- `tests/Unit/EnumsTest`, `tests/Feature/SchemaTest` (cria registos de cada model via factory; apagar projeto apaga tarefas; segunda entrada aberta para o mesmo utilizador viola índice único).
- `php artisan migrate:fresh` em SQLite e em Postgres local (compose profile `local-db`) sem erros.

## Aceitação
- `php artisan migrate:fresh --seed` limpo.
- Factories criam dados válidos; `composer test` verde; Pint limpo.
