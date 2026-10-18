# Fase 10 — Emails, lembretes, digest e scheduler gratuito (tick)

## Objetivo
Envio de emails (Resend, plano gratuito) e lembretes agendados **sem worker nem Cron Job pagos**: um endpoint protegido (`tick`) é chamado por um agendador externo gratuito; ele executa tudo o que está pendente de forma **idempotente e com tempo limitado**.

## Restrições do ambiente gratuito
- Sem processo em background → `QUEUE_CONNECTION=sync`; emails enviados dentro do tick.
- Render free adormece → o tick também o acorda. Pontualidade aproximada (±intervalo do agendador).
- Resend free tem limite diário/mensal → teto próprio `MAIL_DAILY_LIMIT` (default 90/dia, contado em `notification_dispatch_log` onde `channel=email` e `status=sent` no dia UTC).
- Neon free suspende; ticks muito frequentes gastam quota → recomendar **cada 10 min, das 06:00 às 23:00 (Luanda)**.

## Endpoint do tick

`POST /api/internal/scheduler/tick`
- Cabeçalho `X-Cron-Secret: <CRON_SECRET>` — comparado com `hash_equals`. Ausente/errado → `401`. `CRON_SECRET` vazio no servidor → `503` (nunca aceita tick sem segredo).
- `throttle:6,1` próprio. Sem sessão/Sanctum. Resposta curta: `{ "ok": true, "durationMs": 1830, "expired": 2, "sent": { "in_app": 5, "email": 3 }, "skipped": 1, "failed": 0, "timeBudgetExceeded": false }` (sem dados pessoais).
- Também aceita `GET` com o mesmo cabeçalho (alguns agendadores só fazem GET). Documentar ambos; preferir POST.
- **Orçamento de tempo:** `TickService` para de processar novos itens após ~25 s (cron-job.org tem timeout curto) e responde `timeBudgetExceeded=true`; o resto fica para o tick seguinte (tudo é idempotente).
- Lock: `Cache::lock('foco:tick', 120)->get()`; se já houver tick a correr → `202 { "ok": true, "skipped": "locked" }`. (Cache `database`.)

## `App\Services\TickService::run()` — ordem
1. `ExpireOverdueTasks::forAll()` → cria `task_expired` (in-app + email).
2. `foco:check-timers` (timers longos, estimativa ultrapassada, higiene de 16 h — Fase 8).
3. **Lembretes** (`SendReminders`), por utilizador, no fuso dele:
   - `task_due_soon`: tarefas `todo|in_progress|postponed` com prazo definido, `due_at_local` entre agora e agora+`due_soon_hours` e ainda **não** passado.
     Para tarefas **sem hora** usar o fim do dia (23:59) como `due_at_local` **para a janela de 24 h**, mas só notificar a partir das **18:00 do dia anterior** (evita avisar às 00:05). `CONFIRMAR`.
   - `task_due_imminent`: tarefas **com hora** com `due_at_local - agora ≤ due_imminent_minutes` e `> 0`.
   - `project_due_soon`: projeto `active|paused`, `due_date` = hoje ou amanhã (até 24 h), a partir das 18:00 do dia anterior / às `digest_hour` do próprio dia.
   - `project_overdue`: `active`, `due_date < hoje`, `overdue_notified_at IS NULL` → notifica e preenche `overdue_notified_at`.
   - `task_overdue_postponed`: tarefas `postponed` com `due_date < hoje` (G-03).
   - Apanhar atrasos de ticks perdidos: janelas com `<=` (não igualdade de minuto); a dedupe garante 1 envio.
4. **Digest** (`SendDailyDigests`): para cada utilizador cuja hora local ≥ `digest_hour` e ainda não tem `daily_digest` com chave `digest:{data local}` **e** hora local < `digest_hour + 6` (não enviar o "bom dia" às 22:00 se o tick falhou o dia todo). Conteúdo: contagem e lista (máx. 10 por secção): **Para hoje** (todayTasks), **Atrasadas** (dueDate < hoje, ≠done/expired), **Críticas/Altas** pendentes, **Expiradas ontem**, tempo registado ontem. Se não houver nada em nenhuma secção → **não enviar** (dedupe `skipped`).
5. `sanctum:prune-expired --hours=24` e `foco:prune-notifications` (1×/dia: só se `Cache::add('foco:prune:'.date('Y-m-d'), 1, 86400)`).
6. Devolver resumo.

> Cada passo em `try/catch`: falha num utilizador/notificação **não** interrompe os restantes (`report($e)` e `failed++`).

## Emails (`toMail`)
- Mailer `resend` em produção (`MAIL_MAILER=resend`, `RESEND_API_KEY`); `log` em dev/testes. `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` do ambiente.
- `composer require resend/resend-php` (Fase 1). Config em `config/mail.php` já tem o transporte `resend`.
- Templates Markdown PT: `resources/views/mail/{task-due-soon,task-due-imminent,task-expired,task-estimate-exceeded,project-due-soon,project-overdue,daily-digest}.blade.php` (`<x-mail::message>` com botão "Abrir no Foco" → `FRONTEND_URL/tarefas/{id}` ou `/projetos/{id}`; digest → `FRONTEND_URL/`).
- Assunto sempre com prefixo curto: `[Foco] Tarefa a expirar: {título}`.
- Rodapé: "Recebes este email porque ativaste notificações no Foco. Podes desligá-las em Definições." (link para `FRONTEND_URL/definicoes`).
- **Sem** dados sensíveis além do título/prazo. Escapar HTML nos títulos (Blade já escapa; não usar `{!! !!}`).
- Agrupamento anti-spam: se um utilizador teria > 5 emails individuais no mesmo tick, enviar **1 email agregado** "Tens N tarefas a precisar de atenção" (lista) e registar cada notificação in-app individualmente com o canal email `skipped` (motivo `batched`).
- Teto diário: ao atingir `MAIL_DAILY_LIMIT`, canal email → `skipped` (motivo `daily_limit`) e **mantém-se** o in-app.

## Agendador externo (documentar em `docs/12`)
Opção A (recomendada): **cron-job.org** — POST `https://<servico>.onrender.com/api/internal/scheduler/tick`, cabeçalho `X-Cron-Secret`, cada 10 min, janela 06:00–23:00 (fuso Africa/Luanda), timeout 30 s, notificar em falha.
Opção B (alternativa): **GitHub Actions** `schedule` (cron `*/10 * * * *`, aceitando atrasos) com `curl -fsS -X POST -H "X-Cron-Secret: ${{ secrets.CRON_SECRET }}" ...`.
Local/dev: `php artisan schedule:work` + `routes/console.php` agenda `foco:tick` a cada 5 min (comando `foco:tick` chama `TickService::run()`).

## Tarefas
- [ ] **10.1** `TickService`, comando `foco:tick`, `routes/console.php` (apenas para dev), `SchedulerController@tick` + middleware `VerifyCronSecret`.
- [ ] **10.2** `SendReminders`, `SendDailyDigests`, `ExpireOverdueTasks::forAll()`, `CheckTimers`.
- [ ] **10.3** Notificações com `toMail` + views Markdown PT; layout de email com cor da marca (`#6C5CE7`, publicar `mail` theme via `vendor:publish --tag=laravel-mail` e ajustar CSS do tema — único sítio onde CSS é permitido neste repo).
- [ ] **10.4** Teto diário + agregação + `skipped` com motivo.
- [ ] **10.5** Retry (≤ 3 tentativas) de `failed`.
- [ ] **10.6** Lock do tick e orçamento de tempo.

## Testes
- Tick sem/ com segredo errado → 401; sem `CRON_SECRET` configurado → 503.
- **Idempotência:** duas chamadas seguidas → 2.ª não cria nada novo (notificações e emails). Usar `Mail::fake()`/`Notification::fake()` e `NotificationDispatchLog`.
- `task_due_soon` e `task_due_imminent` com `Carbon::setTestNow` (inclui tarefa sem hora, fronteira das 18:00, fuso Luanda).
- Tarefa adiada para nova data gera novo lembrete.
- Digest: 1× por dia local; não envia se vazio; não envia fora da janela `digest_hour..+6h`.
- Teto diário: o 91.º email vira `skipped`, in-app mantém-se.
- Agregação > 5 emails.
- Falha de email → `failed`, re-tentada no tick seguinte, in-app não duplicado.
- `timeBudgetExceeded` simulado.
- Tarefa com timer aberto não expira.

## Aceitação
- Em dev (`MAIL_MAILER=log`) um tick gera os emails esperados no log para dados de teste.
- Em produção, chamada manual com `curl` envia um email real (Resend) para o utilizador de teste.
- `composer test` verde.
