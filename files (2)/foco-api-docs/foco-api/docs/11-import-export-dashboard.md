# Fase 11 — Importação/exportação, dashboard e dados

## Objetivo
Substituir as funções que hoje vivem no `DataStore`/`ImportService`/`window.foco` do front: listas computadas do dashboard, import/export JSON, reset e dados de exemplo — agora no servidor.

## Dashboard — `GET /api/dashboard`
Antes de calcular: `ExpireOverdueTasks::forUser`. Tudo no fuso do utilizador. Resposta:
```json
{
  "today": [Task],           // regras §8.1
  "pending": [Task],         // §8.2
  "urgent": [Task],          // §8.3 (ordem: urgência, dueDate asc, nulls por último)
  "nextSteps": [ { "kind": "task|project", "id": "...", "title": "...", "nextStep": "...", "urgency": "high" } ],  // §8.4, máx. 6
  "statsByCategory": { "professional": { "total": 3, "done": 1, "percent": 33 } },                                // §8.5 (inclui TODAS as categorias do utilizador)
  "completedThisWeek": 4,    // §8.6
  "focusTask": Task|null,    // 1.ª de `urgent` (Modo foco); se houver timer ativo, essa tarefa
  "activeTimer": Task|null,  // tarefa com timer a correr
  "unreadNotifications": 2,
  "serverNow": "2026-10-05T09:20:12Z"
}
```
- `today` / `pending` / `urgent` devolvem tarefas **sem** `activity`.
- `percent = total==0 ? 0 : round(done/total*100)`.
- `completedThisWeek` = `done` com `completed_at >= now()-7d`.

## Exportação — `GET /api/export`
Devolve o estado do utilizador no formato `AppData` do front (compatível com `foco-backup.json`):
```json
{ "schemaVersion": 1, "projects": [Project+activity], "tasks": [Task+activity], "settings": { "theme": "light", "userName": "Zua" }, "categories": ["professional","personal","household"] }
```
Cabeçalho `Content-Disposition: attachment; filename="foco-backup-YYYY-MM-DD.json"`. Inclui `timer.trackedSeconds` por tarefa.

## Importação — `POST /api/import`
Corpo = JSON do §13.3 (`{ projects?: [], tasks?: [] }`) **ou** o `AppData` exportado. Regras (§13 + G-04/G-05/G-06):
- **Aditiva**: nunca apaga; gera sempre novos UUIDs; `status` de projetos importados `active`, de tarefas `todo` (ignora status/ids recebidos).
- Validação manual, **todos** os erros reportados por campo; se existir **qualquer** erro, **nada** é importado (transação + rollback): `422 { ok:false, errors: ["projects[0]: nome é obrigatório", "tasks[2].urgency: valor inválido (urgente)"] }` (formato de strings igual ao do front).
- Campos: projeto → `name` (obrigatório, ≥ 2), `category` (existente nas categorias do utilizador), `urgency` (enum), `dueDate` (`YYYY-MM-DD`), `nextStep`, `description`, `canPostpone`, `color`. Tarefa → `title` (≥ 2), `projectName` (G-05) / `projectId`, `category`, `urgency`, `dueDate` (aceita hora; **no passado ⇒ erro** `tasks[i].dueDate: prazo no passado`, igual à regra de criação), `nextStep`, `canPostpone`, `estimateMinutes`, `tags`, `description`.
- `projectName`: resolve primeiro nos projetos do mesmo payload, depois nos do utilizador (case-insensitive). Inexistente → erro `tasks[i].projectName: projeto não encontrado ({nome})`.
- Limites: ≤ 500 itens por pedido e corpo ≤ 1 MB.
- Atividade `created` "Projeto importado" / "Tarefa importada".
- Resposta de sucesso: `201 { ok:true, projectsCreated: 1, tasksCreated: 4 }`.
- Sem notificações disparadas pela importação (evita rajadas); lembretes normais aplicam-se depois.

## Prompt para IA — `GET /api/import/prompt`
`{ "prompt": "Converte a lista de tarefas abaixo em JSON no formato do Foco ..." }` — o texto do §5 do prompt mestre do front, **atualizado** com as categorias reais do utilizador (`category ∈ <lista>`). O front passa a usar este endpoint no botão "Copiar prompt para IA".

## Dados
| Método | Rota | Efeito |
|---|---|---|
| DELETE | `/api/data` | Apaga **todos** os projetos, tarefas, atividade, entradas de tempo e notificações do utilizador; repõe categorias padrão (apaga as personalizadas); mantém conta e definições. Requer corpo `{ "confirm": "APAGAR" }` → `204` (confirmação visual continua no front). |
| POST | `/api/data/seed-demo` | Só se o utilizador **não tiver** projetos nem tarefas → corre `DemoDataSeeder` para ele; senão `409 { code: "DATA_NOT_EMPTY" }`. (Substitui o "Restaurar dados de exemplo" do front, que hoje só esvazia.) |
| POST | `/api/data/reset-demo` | `{ "confirm": "APAGAR" }` → apaga tudo e semeia demo (equivale ao botão "Restaurar dados de exemplo" tal como o texto diz). |

## Pesquisa global — `GET /api/search?q=`
Substitui o `SearchDropdownComponent`: devolve `{ projects: [{id,name,tasksCount}], tasks: [{id,title,dueDate,projectName}] }`, máx. 10 itens no total (projetos primeiro), pesquisa case-insensitive em título/descrição/nome do projeto/tags; `q` vazio → listas vazias. (`ILIKE` em Postgres; `LOWER(...) LIKE` portável para SQLite nos testes.)

## Tarefas
- [ ] **11.1** `DashboardController` + `DashboardQuery` (reutilizar `TaskQuery`).
- [ ] **11.2** `ExportController` + `AppDataResource`.
- [ ] **11.3** `ImportController`, `ImportRequest` (limites), Action `ImportData` (validador manual por campo, transação).
- [ ] **11.4** `ImportPromptController`.
- [ ] **11.5** `DataController` (`DELETE /data`, `seed-demo`, `reset-demo`).
- [ ] **11.6** `SearchController`.

## Testes
- Dashboard: cada lista com cenários (crítica sem prazo aparece em `today`; atrasada aparece; `done`/`expired` nunca; `nextSteps` máx. 6 e ordem; `statsByCategory` conta expiradas; `completedThisWeek` janela 7 dias; `focusTask`).
- Export → Import round-trip (IDs novos, contagens iguais).
- Import: válido; cada erro por campo; qualquer erro ⇒ nada gravado; `projectName` no payload / existente / inexistente; categoria personalizada aceite; data passada rejeitada; limites.
- `DELETE /data` sem `confirm` ⇒ 422; com `confirm` ⇒ apaga e repõe categorias padrão.
- `seed-demo` 409 se já há dados.
- Search.

## Aceitação
O `foco-backup.json` exportado do front (localStorage atual) é importável via `POST /api/import` (ignorando `settings`), exceto itens com prazo no passado, que são reportados como erro por campo.
