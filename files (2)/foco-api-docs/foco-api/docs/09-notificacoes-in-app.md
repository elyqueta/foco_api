# Fase 9 — Notificações in-app e preferências

## Objetivo
Camada de notificações: tipos, texto PT, criação in-app (tabela `notifications` do Laravel), leitura/marcação, preferências por utilizador e por tipo. O **envio de emails e os lembretes agendados** ficam na Fase 10; aqui constrói-se o `NotificationDispatcher` e os eventos síncronos (concluir, expirar, estimativa).

## Tipos (`NotificationType`)

| Valor | Quando | Subject | Dedupe key | In-app | Email (default) |
|---|---|---|---|---|---|
| `daily_digest` | hora do digest (Fase 10): pendentes/hoje/atrasadas | — | `digest:{YYYY-MM-DD}` | sim | **sim** |
| `task_due_soon` | tarefa a ≤ `due_soon_hours` (24 h) de expirar | task | `due_soon:{due_date}T{due_time?}` | sim | **sim** |
| `task_due_imminent` | tarefa com hora a ≤ `due_imminent_minutes` (60) | task | `imminent:{due_date}T{due_time}` | sim | **sim** |
| `task_expired` | tarefa passa a `expired` | task | `expired:{expired_at date}` | sim | **sim** |
| `task_completed` | tarefa concluída | task | `completed:{completed_at ISO}` | sim | não |
| `task_estimate_exceeded` | tempo registado ≥ estimativa | task | `estimate:{estimate_minutes}` | sim | **sim** |
| `timer_running_long` | timer aberto ≥ `timer_long_hours` | task | `timerlong:{entry_id}` | sim | não |
| `project_due_soon` | projeto a ≤ 24 h do prazo e não `done` | project | `due_soon:{due_date}` | sim | **sim** |
| `project_overdue` | `due_date` < hoje e projeto `active` (1×) | project | `overdue:{due_date}` | sim | **sim** |
| `project_ready_to_complete` | todas as tarefas do projeto `done` | project | `ready:{last_completed_at}` | sim | não |
| `task_overdue_postponed` | tarefa `postponed` com data < hoje (G-03) | task | `postponed_overdue:{due_date}` | sim | não |

As chaves de dedupe incluem a data/estimativa para que **adiar** ou **mudar o prazo** gere novo lembrete legítimo, mas repetir o tick não.

## Textos (PT) — `app/Notifications/Messages.php` (ou métodos `title()/body()` por classe)

| Tipo | Título | Corpo |
|---|---|---|
| `task_due_soon` | "Tarefa a expirar" | "«{título}» expira {hoje/amanhã} {às HH:mm}." |
| `task_due_imminent` | "Prazo daqui a pouco" | "«{título}» expira às {HH:mm} (em {N} min)." |
| `task_expired` | "Tarefa expirada" | "«{título}» passou do prazo ({data})." |
| `task_completed` | "Tarefa concluída 🎉" | "Concluíste «{título}»{ — tempo registado: Xh Ym}." |
| `task_estimate_exceeded` | "Tempo estimado ultrapassado" | "«{título}»: {Xh Ym} registados para uma estimativa de {E} min." |
| `timer_running_long` | "Timer ainda a correr" | "O tempo de «{título}» está a correr há {Xh Ym}. Queres pausar?" |
| `project_due_soon` | "Projeto a expirar" | "«{projeto}» tem prazo {hoje/amanhã}." |
| `project_overdue` | "Projeto atrasado" | "«{projeto}» passou do prazo ({data})." |
| `project_ready_to_complete` | "Projeto pronto a concluir" | "Todas as tarefas de «{projeto}» estão concluídas. Marcar o projeto como concluído?" |
| `task_overdue_postponed` | "Tarefa adiada em atraso" | "«{título}» foi adiada para {data} e já passou." |
| `daily_digest` | "O teu dia" | resumo (Fase 10) |

Datas em `pt-PT` (`d MMM yyyy`; "hoje"/"amanhã" quando aplicável no fuso do utilizador).

## Preferências (`notification_preferences`)
Forma (também em `GET/PATCH /api/settings` → `notifications`):
```json
{
  "emailEnabled": true,
  "inAppEnabled": true,
  "digestHour": 8,
  "dueSoonHours": 24,
  "dueImminentMinutes": 60,
  "timerLongHours": 4,
  "types": { "task_completed": { "email": false, "inApp": true } }
}
```
Validação: `digestHour` 0–23; `dueSoonHours` 1–168; `dueImminentMinutes` 5–720; `timerLongHours` 1–24; `types` com chaves válidas de `NotificationType` e booleanos `email`/`inApp`.
Resolução de canal: `canal ativo = prefs.{canal}Enabled && (types[tipo][canal] ?? default_do_tipo)`. Defaults por tipo = tabela acima.

## Endpoints

| Método | Rota | Resposta |
|---|---|---|
| GET | `/api/notifications?unread=1&limit=50&before=<id>` | `[ Notification ]` mais recentes primeiro |
| GET | `/api/notifications/unread-count` | `{ count }` |
| POST | `/api/notifications/{id}/read` | `204` |
| POST | `/api/notifications/read-all` | `204` |
| DELETE | `/api/notifications/{id}` | `204` |
| DELETE | `/api/notifications` | `204` (limpa todas do utilizador) |

`Notification` = `{ id, type, title, body, data: { taskId?, projectId? }, readAt, createdAt }`.
Retenção: `foco:prune-notifications` apaga lidas > 30 dias e quaisquer > 90 dias (corre no tick, 1×/dia).

## Arquitetura
- `App\Services\NotificationDispatcher::dispatch(User, NotificationType, ?Model $subject, string $dedupeKey, array $payload)`:
  1. Resolve canais ativos (prefs).
  2. Para cada canal, `insertOrIgnore` em `notification_dispatch_log` (`status=pending`); se já existe `sent|skipped` → não repete; se `failed` com `attempts < 3` → tenta de novo.
  3. In-app: cria `DatabaseNotification` (via `Notification::sendNow($user, $notification, ['database'])`).
  4. Email: `Notification::sendNow($user, $notification, ['mail'])` (Fase 10) com teto diário.
  5. Marca `sent`/`failed` (`error` truncado a 500 chars; nunca falha o pedido HTTP: `try/catch` + `report()`).
- Canais **separados** (um registo de log por canal) para que uma falha de email não duplique o in-app no retry.
- Classes `App\Notifications\{TaskExpiredNotification,…}` com `toDatabase()` (title/body/data) e `toMail()` (Fase 10), `via()` devolve os canais passados explicitamente.
- Disparos síncronos nesta fase (dentro das Actions, após `commit`): `task_completed`, `task_expired` (quando expira via `ExpireOverdueTasks`), `project_ready_to_complete`, `task_estimate_exceeded` (ao pausar/concluir se `tracked ≥ estimate`).

## Tarefas
- [ ] **9.1** `NotificationType` completo (labels, defaults por canal, `title/body`).
- [ ] **9.2** `NotificationDispatcher` + `NotificationDispatchLog` + testes de idempotência.
- [ ] **9.3** Classes `Notification` (canal database) e `NotificationResource`.
- [ ] **9.4** Controller + rotas de notificações; `PATCH /api/settings` aceita `notifications`.
- [ ] **9.5** Ligar disparos síncronos às Actions (Fases 6–8).
- [ ] **9.6** Comando `foco:prune-notifications`.

## Testes
- Concluir tarefa cria `task_completed` in-app (e **não** envia email por defeito).
- Expirar tarefa cria `task_expired` 1× (segunda execução não duplica).
- Preferências: desligar `inAppEnabled` não cria; override por tipo vence o default.
- Último `done` de um projeto cria `project_ready_to_complete`.
- Listar/ler/limpar; isolamento entre utilizadores.
- `failed` é re-tentado até 3× e não duplica o canal que já teve sucesso.

## Aceitação
Testes verdes; contrato `Notification` e `Settings.notifications` respeitado.
