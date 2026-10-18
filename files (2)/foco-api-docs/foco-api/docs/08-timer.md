# Fase 8 — Gestão do tempo (timer) pela API

## Objetivo
A API é a única fonte de verdade do tempo de execução: **início, pausa, retoma**, totais e alertas. O front só chama endpoints e mostra um contador calculado a partir de `runningSince` + `trackedSeconds` + `serverNow`.

## Modelo
- `task_time_entries`: uma linha por período de trabalho (`started_at`, `ended_at` null = a correr, `ended_reason`).
- `tasks.tracked_seconds`: soma das entradas **fechadas** (cache; atualizada ao fechar).
- `tasks.first_started_at`: primeira vez que o timer arrancou.
- Índices parciais (Fase 2) garantem **1 entrada aberta por tarefa** e **1 por utilizador**.
- Relógio: **sempre hora do servidor** (`now()` UTC). O cliente nunca envia timestamps.

## Estado do timer (`TimerState`, calculado)
| Estado | Condição |
|---|---|
| `running` | existe entrada com `ended_at` null |
| `paused` | sem entrada aberta, `tracked_seconds > 0`, tarefa não `done`/`expired` |
| `stopped` | tarefa `done` (ou `expired`) com tempo registado |
| `idle` | sem nenhuma entrada |

Bloco devolvido em **todas** as respostas de tarefa:
```json
"timer": {
  "state": "running",
  "runningSince": "2026-10-05T09:12:00Z",
  "trackedSeconds": 1840,
  "serverNow": "2026-10-05T09:20:12Z",
  "firstStartedAt": "2026-10-05T08:40:00Z"
}
```
`trackedSeconds` já **inclui** o tempo da entrada aberta (`serverNow - runningSince`) para evitar contas no cliente; o cliente só soma o tempo decorrido desde que recebeu a resposta.

## Endpoints

| Método | Rota | Resposta |
|---|---|---|
| POST | `/api/tasks/{id}/timer/start` | `{ task, pausedTask }` — primeira vez (`idle`) |
| POST | `/api/tasks/{id}/timer/pause` | `{ task }` |
| POST | `/api/tasks/{id}/timer/resume` | `{ task, pausedTask }` — estado `paused` |
| GET | `/api/tasks/{id}/time-entries` | `[ { id, startedAt, endedAt, endedReason, seconds } ]` (mais recente primeiro) |
| GET | `/api/time/summary?from=&to=&groupBy=day\|task\|category` | totais agregados (ver abaixo) |
| GET | `/api/timer/active` | `{ task }` da tarefa com timer a correr, ou `204` |

`start` e `resume` partilham a mesma Action (`StartTimer`) mas validam o estado de partida: `start` exige `idle`; `resume` exige `paused`; chamar o endpoer errado → `422 { code: "TIMER_WRONG_STATE" }`. Chamar `start`/`resume` numa tarefa já `running` → `409 { code: "TIMER_ALREADY_RUNNING" }` (idempotência amigável: devolve também `task` atual).

## Regras
1. **Um timer por utilizador.** `start`/`resume` com outra tarefa a correr → essa é **pausada automaticamente** (`ended_reason=auto_pause`, atividade `timer_paused` "Timer pausado automaticamente") e devolvida em `pausedTask`. Tudo na mesma transação (índice único protege de corridas; em violação, repetir 1×).
2. **Elegibilidade** de `start/resume`: tarefa `todo`, `in_progress` ou `postponed`. `done`/`expired` → `422 { code: "TIMER_NOT_ALLOWED", message: "Não é possível iniciar o tempo de uma tarefa concluída/expirada." }`.
3. `start/resume` numa tarefa `todo` ou `postponed` → `status=in_progress` (atividade `status_changed`) e `first_started_at` se nulo. Se estava `postponed`, a `due_date` mantém-se.
4. **Pausa** (`pause`): fecha a entrada (`ended_reason=pause`), soma a `tracked_seconds`. O estado da tarefa **mantém-se** `in_progress`. Sem entrada aberta → `422 TIMER_NOT_RUNNING`.
5. **Concluir** (`complete`): fecha entrada aberta (`complete`), atividade `timer_stopped` "Tempo registado: Xh Ym". Tempo mantém-se ao reabrir.
6. **Adiar / voltar a `todo` / apagar**: ver Regras §5.5.
7. Segundos de uma entrada fechada: `max(0, ended_at - started_at)` em segundos inteiros. Entradas com < 1 s são descartadas (apagadas) ao fechar.
8. **Atividade** (tipos novos): `timer_started` "Timer iniciado", `timer_resumed` "Timer retomado", `timer_paused` "Timer pausado", `timer_stopped`.
9. **Estimativa**: `estimateMinutes` não limita o timer; ultrapassá-la gera a notificação `task_estimate_exceeded` (Fase 10).
10. **Timer esquecido**: timer aberto há ≥ `timer_long_hours` (default 4 h) → notificação `timer_running_long` (1× por entrada). Nunca pausa sozinho.
11. **Higiene (tick):** entrada aberta há > 16 h → fechada automaticamente (`ended_reason=auto_pause`) com atividade "Timer pausado automaticamente (inatividade)" — evita somar noites inteiras por esquecimento. (`CONFIRMAR`)

## Resumo de tempo (`/api/time/summary`)
- `from`/`to` `YYYY-MM-DD` (fuso do utilizador, inclusive); default: últimos 7 dias.
- Divide entradas que atravessam a meia-noite pelo dia local.
- `groupBy=day` → `[ { key: "2026-10-05", seconds } ]`; `task` → `[ { key: taskId, title, seconds } ]`; `category` → `[ { key: "professional", seconds } ]`.
- Resposta inclui `totalSeconds`.

## Tarefas
- [ ] **8.1** Model `TaskTimeEntry` + `TimerService` (`state(Task)`, `trackedSeconds(Task, now)`, `open(Task)`).
- [ ] **8.2** Actions `StartTimer`, `PauseTimer`, e integração em `CompleteTask`/`PostponeTask`/`UpdateTask`/`ExpireOverdueTasks` (fechar entrada).
- [ ] **8.3** `TimerController`, `TimeEntryResource`, `TimeSummaryController`.
- [ ] **8.4** Bloco `timer` no `TaskResource` (substitui o placeholder da Fase 7). Evitar N+1: `withSum`/`with('openEntry')` nas listas.
- [ ] **8.5** Comando `foco:check-timers` (usado pelo tick): timers longos (notificação) + higiene de 16 h.
- [ ] **8.6** Estender `ActivityType` e labels PT.

## Testes
- Fluxo: `start` → `running`, `status=in_progress`, `first_started_at` setado; `pause` (soma segundos com `Carbon::setTestNow`); `resume`; `complete` fecha e `stopped`.
- 2 tarefas: `start` na B pausa a A (`auto_pause`), `pausedTask` devolvido; índice único nunca violado (teste de corrida simulada).
- Erros: `start` em `done`/`expired` (`TIMER_NOT_ALLOWED`), `pause` sem timer (`TIMER_NOT_RUNNING`), `start` com timer já a correr (409), `resume` em `idle` (`TIMER_WRONG_STATE`).
- `postpone` e `PATCH status=todo` fecham timer aberto.
- `ExpireOverdueTasks` não expira tarefa com timer aberto; expira depois da pausa.
- `time/summary` por dia atravessando meia-noite e fuso `Africa/Luanda`.
- `trackedSeconds` inclui tempo em curso.
- Higiene 16 h e notificação de timer longo (idempotente).

## Aceitação
Testes verdes; `GET /api/tasks/{id}` reflete o estado correto do timer em todas as situações acima.
