# Contrato da API — Foco

Base: `/api` · JSON · UTF-8 · `Accept: application/json` · Auth: `Authorization: Bearer <token>` (Sanctum) em tudo exceto `auth/login`, `health` e `internal/scheduler/tick` (este usa `X-Cron-Secret`).

Convenções: chaves **camelCase** (exceto `auth/login` → `expires_at`); datas-hora em ISO 8601 UTC (`2026-10-05T09:12:00Z`); prazos em hora local (`YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm`); sem wrapper `data`; listas = arrays simples; recurso de outro utilizador → `404`.

## Erros

| HTTP | Corpo | Quando |
|---|---|---|
| 401 | `{ "message": "Não autenticado." }` | token ausente/inválido/expirado |
| 404 | `{ "message": "Não encontrado." }` | recurso inexistente ou de outro utilizador |
| 409 | `{ "message": "...", "code": "..." }` | conflito de timer/dados |
| 422 | `{ "message": "...", "errors": { "campo": ["..."] }, "code"?: "..." }` | validação / regra de negócio |
| 429 | `{ "message": "..." }` | throttle |
| 5xx | `{ "message": "Ocorreu um erro. Tenta novamente." }` | sem detalhes internos |

### Códigos de domínio (`code`)
`PAST_DUE_DATE`, `INVALID_TRANSITION`, `POSTPONE_REQUIRES_DATE`, `TASK_CANNOT_POSTPONE`, `CATEGORY_PROTECTED`, `CATEGORY_EXISTS`, `TIMER_ALREADY_RUNNING` (409), `TIMER_NOT_RUNNING`, `TIMER_WRONG_STATE`, `TIMER_NOT_ALLOWED`, `DATA_NOT_EMPTY` (409), `IMPORT_INVALID`.

## Endpoints

### Sistema
| Método | Rota | Auth | Resposta |
|---|---|---|---|
| GET | `/api/health` | não | `{ status, app, db, time }` |
| POST/GET | `/api/internal/scheduler/tick` | `X-Cron-Secret` | resumo do tick (Fase 10) |

### Auth e definições
| Método | Rota | Corpo | Resposta |
|---|---|---|---|
| POST | `/api/auth/login` | `{ email, password }` | `{ token, user: {id,name,email}, expires_at }` |
| POST | `/api/auth/logout` | — | `204` |
| POST | `/api/auth/logout-all` | — | `204` |
| GET | `/api/auth/me` | — | `{ id, name, email }` |
| PATCH | `/api/auth/password` | `{ currentPassword, password, passwordConfirmation }` | `204` |
| GET | `/api/settings` | — | `Settings` |
| PATCH | `/api/settings` | parcial | `Settings` |

### Categorias
`GET /api/categories` · `POST /api/categories {name}` · `DELETE /api/categories/{name}` (Fase 5)

### Projetos
`GET /api/projects` · `POST /api/projects` · `GET|PATCH|DELETE /api/projects/{id}` · `POST /api/projects/{id}/notes` (Fase 6)

### Tarefas
`GET /api/tasks` · `POST /api/tasks` · `GET|PATCH|DELETE /api/tasks/{id}` · `POST /api/tasks/{id}/{complete|reopen|postpone|notes}` (Fase 7)

### Timer
`POST /api/tasks/{id}/timer/{start|pause|resume}` · `GET /api/tasks/{id}/time-entries` · `GET /api/timer/active` · `GET /api/time/summary` (Fase 8)

### Notificações
`GET /api/notifications` · `GET /api/notifications/unread-count` · `POST /api/notifications/{id}/read` · `POST /api/notifications/read-all` · `DELETE /api/notifications/{id}` · `DELETE /api/notifications` (Fase 9)

### Dashboard, dados, importação
`GET /api/dashboard` · `GET /api/search?q=` · `GET /api/export` · `POST /api/import` · `GET /api/import/prompt` · `DELETE /api/data` · `POST /api/data/seed-demo` · `POST /api/data/reset-demo` (Fase 11)

## Formas (JSON)

### Task
```json
{
  "id": "0b9e…-uuid",
  "projectId": "uuid|null",
  "title": "Integrar API real no destino-mussulo",
  "description": "",
  "category": "professional",
  "urgency": "critical",
  "status": "todo",
  "canPostpone": false,
  "dueDate": "2026-10-05T14:30",
  "nextStep": "Trocar mock service por HttpClient",
  "estimateMinutes": 90,
  "tags": ["angular", "api"],
  "isOverdue": false,
  "postponedCount": 0,
  "timer": {
    "state": "idle|running|paused|stopped",
    "runningSince": "2026-10-05T09:12:00Z|null",
    "trackedSeconds": 0,
    "serverNow": "2026-10-05T09:20:12Z",
    "firstStartedAt": "…|null"
  },
  "createdAt": "…", "updatedAt": "…", "completedAt": "…|null",
  "activity": [ActivityEntry]        // só no detalhe
}
```
Respostas de ações (`complete`, `postpone`, etc.) devolvem `Task` (detalhe). `complete` pode acrescentar `suggestCompleteProject: { id, name }` (devolver `{ task, suggestCompleteProject }`); `timer/start|resume` devolvem `{ task, pausedTask }`; `timer/pause` devolve `{ task }`.
> Padronização: ações simples (`complete`, `reopen`, `postpone`) devolvem **`{ task, suggestCompleteProject? }`**; `GET/POST/PATCH /tasks` devolvem `Task` direto.

### Project
```json
{
  "id": "uuid", "name": "Loja Nerd", "description": "", "category": "personal",
  "urgency": "high", "status": "active", "canPostpone": true,
  "dueDate": "2026-10-20|null", "nextStep": "…", "color": "#3FBF9A",
  "progress": { "total": 4, "done": 1, "percent": 25 },
  "createdAt": "…", "updatedAt": "…",
  "activity": [ActivityEntry]        // só no detalhe
}
```

### ActivityEntry
`{ "id": "uuid", "at": "ISO", "type": "created|status_changed|note|postponed|edited|next_step_changed|expired|timer_started|timer_paused|timer_resumed|timer_stopped", "message": "…" }`

### Category
`{ "name": "professional", "isDefault": true, "tasksCount": 3, "projectsCount": 1 }`

### TimeEntry
`{ "id": "uuid", "startedAt": "ISO", "endedAt": "ISO|null", "endedReason": "pause|auto_pause|complete|postpone|reset|null", "seconds": 1840 }`

### Notification
`{ "id": "uuid", "type": "task_due_soon", "title": "…", "body": "…", "data": { "taskId": "uuid?", "projectId": "uuid?" }, "readAt": "ISO|null", "createdAt": "ISO" }`

### Settings
```json
{
  "userName": "Zua",
  "theme": "light",
  "timezone": "Africa/Luanda",
  "email": "admin@todo.ao",
  "notifications": {
    "emailEnabled": true, "inAppEnabled": true,
    "digestHour": 8, "dueSoonHours": 24, "dueImminentMinutes": 60, "timerLongHours": 4,
    "types": { "task_completed": { "email": false, "inApp": true } }
  }
}
```

### Dashboard / Export / Import
Ver Fase 11.

## Regras transversais
- `PATCH` é **parcial**: campos ausentes não mudam; `null` explícito limpa campos anuláveis (`dueDate`, `estimateMinutes`, `projectId`).
- Strings são `trim`-adas. Texto vazio onde há default → default.
- Erros de validação sempre em PT.
- Versionamento: sem prefixo de versão na v1; mudanças incompatíveis ⇒ `/api/v2`.
