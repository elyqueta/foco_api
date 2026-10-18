# Fase 7 — Tarefas

## Objetivo
CRUD de tarefas, máquina de estados, adiar, concluir/reabrir, expiração automática, filtros, pesquisa e calendário (regras §5, §6.1, §7, §9.1, §10, §11, §12.1, §22).

## Endpoints

| Método | Rota | Notas |
|---|---|---|
| GET | `/api/tasks` | Filtros abaixo. Lista **sem** `activity`. |
| POST | `/api/tasks` | Cria. `201` com detalhe. |
| GET | `/api/tasks/{id}` | Detalhe com `activity`. |
| PATCH | `/api/tasks/{id}` | Atualização parcial (inclui `status` conforme máquina de estados). |
| DELETE | `/api/tasks/{id}` | `204`. Apaga entradas de tempo (cascade). |
| POST | `/api/tasks/{id}/complete` | → `done`. Pode incluir `suggestCompleteProject`. |
| POST | `/api/tasks/{id}/reopen` | `done` (ou `expired`, G-01) → `todo`. |
| POST | `/api/tasks/{id}/postpone` | `{ dueDate }` (`YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm`). |
| POST | `/api/tasks/{id}/notes` | `{ text }` → atividade `note`. |

### Filtros de `GET /api/tasks` (todos opcionais)
`category`, `urgency`, `status` (um ou vários separados por vírgula), `projectId` (ou `none` para tarefas soltas), `q` (título, descrição, nome do projeto, tags — case-insensitive), `dueFrom`/`dueTo` (`YYYY-MM-DD`, para o calendário), `includeDone` (default `false`), `includeExpired` (default `false`), `sort` (`urgency|dueDate|createdAt`, default `urgency` e depois `dueDate` asc, nulls por último).
Antes de listar: `ExpireOverdueTasks` para o utilizador (preguiçoso, Regras §5.2).

## Validação de criação (`StoreTaskRequest`)
- `title`: obrigatório, trim, **mín. 2**, máx. 255.
- `description` (default `''`), `category` (`ValidCategory`, default `professional`), `urgency` (default `medium`), `status` (default `todo`; só `todo|in_progress|postponed` na criação), `canPostpone` (default `true`), `nextStep` (≤ 255, default `''`), `estimateMinutes` (int ≥ 0 ou null), `tags` (array de strings ≤ 40 chars cada, máx. 20), `projectId` (UUID existente **do mesmo utilizador** ou null).
- `dueDate`: `null` | `YYYY-MM-DD` | `YYYY-MM-DDTHH:mm`.
  - **Parte da data < hoje (fuso do utilizador) → `422 { code: "PAST_DUE_DATE", errors: { dueDate: ["Não é possível criar tarefas com prazo no passado."] } }`** (regra §6.1/§7.1: compara só a data, ignora a hora).
  - Hora sem data → 422.

## Máquina de estados (`App\Services\TaskStateMachine`)
Permitidas (doc §5.2 + G-01/G-02):

| De | Para |
|---|---|
| `todo` | `in_progress`, `postponed`, `done`, `expired`(sistema) |
| `in_progress` | `todo`, `postponed`, `done`, `expired`(sistema) |
| `postponed` | `done`, `in_progress`(só via timer) |
| `done` | `todo` |
| `expired` | `done`, `todo` (só com novo `dueDate ≥ hoje` no mesmo `PATCH`/reopen) |

Qualquer outra → `422 { code: "INVALID_TRANSITION", message: "Transição de estado inválida: {de} → {para}." }`. Pedir `expired` manualmente → `INVALID_TRANSITION` (G-07).
Efeitos:
- `→ done`: `completed_at=now`, fecha timer aberto (motivo `complete`), atividade `status_changed` "Estado alterado para Concluída" + `timer_stopped` se havia timer, notificação `task_completed`.
- `done → todo`: `completed_at=null`, atividade `status_changed`.
- `→ postponed` via `PATCH status` **sem** nova data é rejeitado (`422 code: POSTPONE_REQUIRES_DATE`); usar o endpoint `postpone`.
- Mudança para `todo` com timer aberto → fecha o timer (motivo `pause`).

## Adiar (`PostponeTask`)
- Só `todo|in_progress`. `canPostpone=false` → `422 { code: "TASK_CANNOT_POSTPONE", message: "Esta tarefa não pode ser adiada." }`.
- Nova data **≥ hoje** (G-08) e, se hoje, hora (se houver) no futuro → senão `422 PAST_DUE_DATE`.
- Efeito: `due_date/due_time` novos, `status=postponed`, `postponed_count++`, fecha timer aberto (motivo `postpone`), atividade `postponed` "Adiada para {data formatada pt-PT}" (`Intl`-equivalente: `d MMM yyyy`, ex.: "7 out 2026"; com hora acrescentar "às HH:mm").
- Limpa chaves de dedupe de lembretes anteriores (as chaves incluem a data — ver Fase 10).

## Atualização (`PATCH`)
- Qualquer campo editável (§9.1). `updatedAt` atualizado em cada edição.
- Atividade:
  - `status` mudou → `status_changed` (mensagem PT, G-09).
  - `nextStep` mudou (valor diferente) → `next_step_changed`.
  - outros campos → 1 entrada `edited` "Tarefa editada".
- `dueDate` passado **na criação** é bloqueado; **no PATCH é permitido** (doc só bloqueia criação). Se a nova data (parte data) < hoje e a tarefa está `todo|in_progress`, ela fica `expired` na próxima verificação (preguiçosa/tick). Se a tarefa está `expired` e o PATCH define `dueDate ≥ hoje` → passa a `todo` (G-01, atividade `edited` "Prazo atualizado; tarefa reativada").

## Expiração (`ExpireOverdueTasks`, regras §7.2 / Regras §5.2)
Query: `status IN (todo,in_progress) AND due_date < hoje(tz do user) AND NOT EXISTS entrada de tempo aberta`.
Para cada uma (transação): `status=expired`, `expired_at=now`, `updated_at`, atividade `expired` "Tarefa expirada por prazo vencido", notificação `task_expired` (dedupe por tarefa).
Expõe-se como: `ExpireOverdueTasks::forUser(User)` e `::forAll()` (usada pelo tick e pelo comando `foco:expire-overdue`). Idempotente.

## `isOverdue` e campos calculados
- `isOverdue`: Regras §5.1.
- `timer`: ver Fase 8 (incluir o bloco no `TaskResource` desde já, com `state=idle` até a Fase 8).

## Projeto concluído (sugestão)
Em `complete`: se a tarefa tem `project_id` e **todas** as tarefas do projeto estão `done` → resposta inclui `suggestCompleteProject: { id, name }` e cria `project_ready_to_complete` (Fase 9). (Tarefas `expired` impedem a sugestão — só `done` conta.)

## Tarefas de implementação
- [ ] **7.1** `TaskController`, Form Requests (Store/Update/Postpone/Note), `TaskResource` (+ `TaskCollection` simples), `TaskPolicy`.
- [ ] **7.2** Parser/serializer de prazo: `DueDate::parse(?string): array{date,time}` e `DueDate::format($date,$time): ?string`.
- [ ] **7.3** Actions: `CreateTask`, `UpdateTask`, `DeleteTask`, `CompleteTask`, `ReopenTask`, `PostponeTask`, `AddTaskNote`, `ExpireOverdueTasks`.
- [ ] **7.4** `TaskStateMachine` + testes de tabela (todas as combinações).
- [ ] **7.5** Filtros em `TaskQuery` (query object) — sem N+1 (`with('project:id,name')` apenas se necessário).
- [ ] **7.6** Comando `foco:expire-overdue` (todos os utilizadores).
- [ ] **7.7** Mensagens PT e `DomainRuleException` com os `code` listados no contrato.

## Testes (Feature)
- Criação com defaults; título curto 422; prazo passado 422 (`PAST_DUE_DATE`); prazo hoje com hora passada **permitido** (só compara data).
- Aceita `2026-10-05` e `2026-10-05T14:30`; devolve no mesmo formato.
- Tabela de transições (válidas e inválidas); `expired` manual → 422.
- Concluir/reabrir (`completedAt`); atividade criada; `suggestCompleteProject`.
- Adiar: `canPostpone=false` → 422; data passada → 422; sucesso muda estado, data, `postponedCount`, atividade.
- `ExpireOverdueTasks`: expira só `todo|in_progress` com data < hoje; ignora `done|postponed|expired`; **não** expira com timer aberto; idempotente; respeita fuso do utilizador (testar com `Africa/Luanda` vs `UTC` na fronteira da meia-noite usando `Carbon::setTestNow`).
- Filtros (`category`, `urgency`, `status`, `projectId=none`, `q`, `dueFrom/dueTo`, `includeDone`, `includeExpired`).
- Isolamento entre utilizadores; `projectId` de outro utilizador → 422.

## Aceitação
Todos os testes verdes; contrato respeitado campo a campo.
