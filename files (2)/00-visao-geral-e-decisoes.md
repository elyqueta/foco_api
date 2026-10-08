# 00 — Visão geral, decisões e regras adicionais

## 1. Decisões fechadas (confirmadas pelo Zua)

| ID | Decisão |
|---|---|
| D-01 | BD: **PostgreSQL no Neon**. Sem SQLite em produção. |
| D-02 | Auth: **Laravel Sanctum**, tokens Bearer. |
| D-03 | Email/agendamento: **opção gratuita** → Resend (plano free) + tick externo gratuito. Sem Cron Job/worker pagos do Render. |
| D-04 | Todo o tempo de tarefa (início/pausa/retoma) é gerido **pela API**; notificações in-app e email também. O front só chama a API e mostra. |
| D-05 | Deploy alvo: Render (Web Service Docker). |

## 2. Decisões técnicas tomadas na análise (podem ser alteradas, registar em PROGRESS.md)

| ID | Decisão | Porquê |
|---|---|---|
| T-01 | Prazo de tarefa = `due_date` (DATE) + `due_time` (TIME, nullable), **hora local do utilizador**. | O front envia `YYYY-MM-DD` (docs) **e** `YYYY-MM-DDTHH:mm` (form `datetime-local`). Evita bugs de fuso. |
| T-02 | Prazo de projeto = só `due_date` (DATE). | Form do front usa `type="date"`. |
| T-03 | Fuso: `users.timezone` (default `Africa/Luanda`). `config/app.php` fica UTC. | "Hoje" tem de ser o dia do utilizador. |
| T-04 | `QUEUE_CONNECTION=sync`; sem worker. Envio de email dentro do tick. | Sem worker gratuito no Render. |
| T-05 | Dedupe de notificações/emails via tabela `notification_dispatch_log` (unique). | Tick corre de 5/10 em 5 min e pode repetir/falhar. |
| T-06 | Respostas JSON em camelCase, sem wrapper `data`, listas sem paginação. | Casa com `core/models.ts` do front. |
| T-07 | Categoria é string (nome) em tarefa/projeto, validada contra a tabela `categories` do utilizador. | O front tipa `Category = string`. |
| T-08 | Listas devolvem tarefas/projetos **sem** `activity`; detalhe devolve com `activity`. | Payload leve. |

## 3. Regras de negócio — resumo do front (obrigatórias)

Detalhe completo no snapshot de `docs/regras-negocio.md` do front. A API tem de reproduzir:

- **Enums:** Urgency `critical|high|medium|low` (ordem 0..3). Task.status `todo|in_progress|done|postponed|expired`. Project.status `active|paused|done`. ActivityEntry.type `created|status_changed|note|postponed|edited|next_step_changed|expired` (+ extensões em §5).
- **Categorias:** padrão `professional|personal|household` (não removíveis). Custom: nome ≥ 2 chars, sem duplicados. Remover custom → reatribuir tarefas e projetos a `professional`.
- **Criar tarefa:** título ≥ 2 chars; `dueDate` **no passado bloqueia a criação** (compara só a parte da data); defaults `category=professional`, `urgency=medium`, `status=todo`, `canPostpone=true`, `estimateMinutes=null`, `tags=[]`, `projectId=null`.
- **Criar projeto:** nome ≥ 2; defaults `professional`, `medium`, `active`, `canPostpone=true`, `color=#6C5CE7`, `nextStep=''`.
- **Transições de estado:** `todo→in_progress`, `in_progress→todo`, `todo|in_progress|postponed→done`, `done→todo`, `todo|in_progress→postponed`, `todo|in_progress→expired` (só automático).
- **Expirar:** tarefa `todo|in_progress` com `dueDate` (parte data) < hoje → `expired`, `updatedAt` atualizado, atividade `expired` "Tarefa expirada por prazo vencido". `done|postponed|expired` não são processadas.
- **Adiar:** só se `canPostpone=true`; atualiza `dueDate`, `status=postponed`, atividade `postponed` "Adiada para {data}".
- **Concluir:** `status=done`, `completedAt=now`, atividade `status_changed`. **Reabrir:** `status=todo`, `completedAt=null`.
- **Listas:** `todayTasks` (≠done, ≠expired, e (critical OU dueDate ≤ hoje)), `pendingTasks` (`todo|in_progress|postponed`), `urgentTasks` (critical|high, ≠done/expired; ordenadas urgência, dueDate asc), `nextSteps` (máx. 6, ordem urgência; projetos ≠done, tarefas ≠done/expired), `statsByCategory` (total/done/percent; expiradas contam), `completedThisWeek` (done e completedAt ≥ agora−7d).
- **Atividade:** `{id, at, type, message}`; mensagens padrão: "Tarefa criada", "Projeto criado", "Estado alterado para {status}", "Próximo passo atualizado", texto da nota, etc.
- **Eliminar projeto:** apaga também as suas tarefas. **Importação:** aditiva, novos IDs.

## 4. Lacunas / inconsistências encontradas no front (a API resolve assim; registar como `CONFIRMAR`)

| ID | Lacuna | Default adotado pela API |
|---|---|---|
| G-01 | Tarefa `expired` fica sem saída pelas transições do doc. | Permitir `expired→done` (concluída tarde) e `expired→todo` **apenas** se um `PATCH` definir novo `dueDate ≥ hoje` (atividade `edited`). |
| G-02 | `postponed→in_progress` não existe no doc, mas iniciar o timer numa tarefa adiada é natural. | Permitir `postponed→in_progress` **só através do timer** (`start`). |
| G-03 | Tarefa `postponed` cuja nova data passa nunca expira (doc §7.2). | Seguir o doc: não expira. Continua a aparecer em "Hoje" como atrasada e dispara `task_overdue_postponed` uma vez (in-app). |
| G-04 | Doc §13.2 é contraditório (reporta erros por campo sem interromper **vs** aborta se houver erros). | Validar tudo; se houver qualquer erro, **abortar atomicamente** e devolver todos os erros por campo (igual ao `import.service.ts`). |
| G-05 | Doc §13.3 usa `projectName` nas tarefas; o `import.service.ts` do front usa `projectId`. | API aceita `projectName` (e `projectId` opcional). Resolve contra projetos do mesmo payload e depois do utilizador (case-insensitive); inexistente → erro em `tasks[i].projectName`. |
| G-06 | Import do front só valida as 3 categorias padrão. | API valida contra as categorias reais do utilizador. |
| G-07 | O front permite escolher `expired` manualmente nos selects (detalhe/form). | API rejeita (`422 INVALID_TRANSITION`). O front deve remover a opção (Fase 13). |
| G-08 | `postpone` sem validação de data nova. | Nova data tem de ser ≥ hoje (no fuso do utilizador). |
| G-09 | `updateTask` no front regista atividade `status_changed` com o status cru em inglês. | API regista mensagem PT: "Estado alterado para Em curso" (labels: A fazer, Em curso, Adiada, Concluída, Expirada). |
| G-10 | Datas: docs dizem `YYYY-MM-DD`, form grava `YYYY-MM-DDTHH:mm`. | Aceitar ambos (T-01) e devolver o formato original. |

## 5. Regras adicionais do back-end (novas — adicionar ao doc de regras do front na Fase 13)

### 5.1 Prazos
- `dueDate` recebido: `YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm` (hora local). Guardado em `due_date` + `due_time`.
- Serialização: com hora → `YYYY-MM-DDTHH:mm`; sem hora → `YYYY-MM-DD`.
- **Expiração é por dia** (doc §7.2): `due_date < hoje(tz)`. A hora do prazo serve só para lembretes e para o campo `isOverdue` (ver contrato).
- `isOverdue` (calculado): não concluída/expirada e (`due_date < hoje` OU (`due_date = hoje` E `due_time` definida E já passou)).

### 5.2 Expiração sem cliente aberto
- O front deixa de expirar no arranque. A API expira: (a) em cada `tick`, (b) de forma preguiçosa no início de `GET /api/tasks`, `GET /api/dashboard` e `GET /api/tasks/{id}` (para o utilizador autenticado).
- **Tarefa com timer a correr não expira** até o timer ser pausado/terminado (evita parar o trabalho à meia-noite). É expirada no tick seguinte à pausa. (`CONFIRMAR`)

### 5.3 Timer (resumo; detalhe na Fase 8)
- Entradas de tempo `task_time_entries`: cada entrada tem `started_at` e `ended_at`. Tempo total = soma das entradas (a em curso conta até agora, hora do servidor).
- Apenas **1 timer ativo por utilizador**. `start`/`resume` noutra tarefa **pausa automaticamente** a que estava a correr (motivo `auto_pause`) e devolve-a em `pausedTask`.
- `start` numa tarefa `todo` ou `postponed` → `in_progress`. Não permitido em `done`/`expired`.
- Concluir, reabrir? Concluir fecha a entrada aberta (motivo `complete`). Reabrir mantém o tempo acumulado.
- Eliminar a tarefa elimina as entradas (cascade).
- Atividade (extensão de `ActivityType`): `timer_started`, `timer_paused`, `timer_resumed`, `timer_stopped` (ao concluir).

### 5.4 Notificações (resumo; detalhe nas Fases 9–10)
Tipos (`NotificationType`):
`daily_digest` (pendentes/hoje/atrasadas), `task_due_soon` (para expirar, 24 h por defeito), `task_due_imminent` (60 min por defeito, só tarefas com hora), `task_expired`, `task_completed`, `task_estimate_exceeded` (tempo de execução ultrapassou a estimativa), `timer_running_long`, `project_due_soon`, `project_overdue`, `project_ready_to_complete`, `task_overdue_postponed`.
Canais: `in_app` (tabela `notifications` do Laravel) e `email` (Resend), com preferências por utilizador e por tipo.

### 5.5 Estados de tarefa × timer
| Ação | Efeito no timer |
|---|---|
| `complete` | fecha entrada aberta |
| `postpone` | fecha entrada aberta (motivo `pause`) |
| `PATCH status → todo` | fecha entrada aberta |
| `delete` | cascade |
| `expire` (sistema) | só corre se não houver entrada aberta (§5.2) |

## 6. Limites dos serviços gratuitos (verificar valores atuais antes do deploy)

Os planos gratuitos mudam. Confirma nas páginas oficiais antes de depender deles:
- **Render free:** o serviço "adormece" após inatividade; o tick externo acorda-o (primeiro pedido pode demorar). Sem Shell/SSH e sem Cron Jobs no plano gratuito → migrations/seeders correm via entrypoint ou a partir da tua máquina contra o Neon.
- **Neon free:** computação suspende por inatividade e tem quota mensal de horas de computação. Um tick de 5 em 5 min, 24/7, pode mantê-la acordada e gastar a quota → usar intervalo de **10 min** e janela **06:00–23:00 (Luanda)** no agendador externo.
- **Resend free:** limite diário/mensal de emails e, sem domínio verificado, só envia para o email do dono da conta (suficiente para app pessoal). A API aplica um teto diário próprio (`MAIL_DAILY_LIMIT`, default 90) e continua a criar a notificação in-app quando o teto é atingido.
- **cron-job.org (ou GitHub Actions):** gratuitos; intervalo mínimo e pontualidade variam.
