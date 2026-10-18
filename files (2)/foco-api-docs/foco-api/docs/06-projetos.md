# Fase 6 — Projetos

## Objetivo
CRUD completo de projetos, notas, histórico de atividade e progresso (regras §6.2, §9.2, §12.2, §22.3 do doc de negócio).

## Endpoints

| Método | Rota | Notas |
|---|---|---|
| GET | `/api/projects?category=&status=&q=` | Lista **sem** `activity`; inclui `progress`. `q` procura em `name` e `description` (case-insensitive). |
| POST | `/api/projects` | Cria. `201` com detalhe. |
| GET | `/api/projects/{id}` | Detalhe **com** `activity` (mais recente primeiro) e `progress`. |
| PATCH | `/api/projects/{id}` | Atualização parcial. |
| DELETE | `/api/projects/{id}` | Apaga projeto **e todas as suas tarefas** (cascade). `204`. (A confirmação é do front.) |
| POST | `/api/projects/{id}/notes` | `{ text }` (1–2000) → atividade `note`; devolve a entrada criada. |

## Validação (`StoreProjectRequest` / `UpdateProjectRequest`)
- `name`: obrigatório no POST, trim, **mín. 2**, máx. 255.
- `description`: string, default `''`.
- `category`: `ValidCategory`, default `professional`.
- `urgency`: enum, default `medium`.
- `status`: `active|paused|done`, default `active`.
- `canPostpone`: boolean, default `true`.
- `dueDate`: `YYYY-MM-DD` ou `null` (projetos **não** têm hora). *O doc não bloqueia datas passadas em projetos — não bloquear.*
- `nextStep`: string ≤ 255, default `''`.
- `color`: `#RRGGBB`, default `#6C5CE7`. (Paleta sugerida do front: `#6C5CE7, #3FBF9A, #F0506E, #F5A524, #3B82F6, #A855F7, #14B8A6, #EF8354` — aceitar qualquer hex válido.)

## Atividade (regras §9.2/§21)
- Criar → `created` "Projeto criado".
- `PATCH` com `nextStep` alterado (valor diferente do atual) → `next_step_changed` "Próximo passo atualizado".
- `PATCH` com `status` alterado → `status_changed` "Estado alterado para {Ativo|Pausado|Concluído}".
- Qualquer outro campo alterado → **uma** entrada `edited` "Projeto editado" por pedido (não uma por campo).
- Nota → `note` com o texto.
- *(O `updateProject` do front só regista `next_step_changed` quando `nextStep` vem truthy, mesmo sem mudança — a API só regista quando o valor realmente muda.)*

## Progresso (`progress`)
`{ total, done, percent }` = tarefas do projeto; `percent = total==0 ? 0 : round(done/total*100)`. Tarefas `expired` contam no total.

## Sugestão de concluir projeto (§11, §22.3)
Quando a última tarefa pendente de um projeto passa a `done` (Fase 7), a resposta dessa ação inclui `suggestCompleteProject: { id, name }` e cria a notificação `project_ready_to_complete` (Fase 9). Não altera o projeto automaticamente.

## Tarefas
- [ ] **6.1** `ProjectController`, Form Requests, `ProjectResource` (campos do `contrato-api.md`), `ProjectPolicy`.
- [ ] **6.2** Actions: `CreateProject`, `UpdateProject` (diff + atividade), `DeleteProject`, `AddProjectNote`.
- [ ] **6.3** `RecordActivity` (service único usado por projetos e tarefas): `record(User, Model $subject, ActivityType, string $message): ActivityEntry`.
- [ ] **6.4** Eager loading: listas com `withCount` para progresso (evitar N+1); detalhe com `activity`.
- [ ] **6.5** Garantir que `PATCH` com `category` inválida → 422 `errors.category`.

## Testes
- CRUD completo + isolamento entre utilizadores (404, não 403, para recursos de outros).
- Defaults na criação (`professional`, `medium`, `active`, `canPostpone=true`, `#6C5CE7`).
- Nome de 1 caractere → 422.
- Atividade por tipo de alteração; sem alteração real → nenhuma atividade.
- Apagar projeto apaga tarefas e entradas de tempo.
- `progress` correto (incluindo expiradas).

## Aceitação
Testes verdes; resposta de lista/detalhe exatamente como `contrato-api.md`.
