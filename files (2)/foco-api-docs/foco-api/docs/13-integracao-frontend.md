# Fase 13 — Integração com o front-end (referência)

> Este documento descreve o que o repositório **foco** (Angular) terá de mudar para consumir a API. **Não é para implementar no repo da API.** Serve como checklist para a próxima etapa (front) e para validar o contrato.

## 1. Configuração
- `environment.ts` / `environment.development.ts`: `useMockAuth: false`; `apiBaseUrl: '/api'` (dev, via `proxy.conf.json`).
- `proxy.conf.json`: alvo `http://localhost:8000` (já coincide com o `docker-compose` da API: `8000:8080`).
- Produção: `apiBaseUrl` = `https://<servico>.onrender.com/api` (a URL absoluta tem de começar por `environment.apiBaseUrl` — o `authInterceptor` só injeta o token nesses pedidos). Adicionar a origem do front a `CORS_ALLOWED_ORIGINS` na API.

## 2. Auth (já preparado)
`ApiAuthRepository` + `authInterceptor` já seguem o contrato. Confirmar:
- Mensagens de 401/422/429 (iguais às do front).
- `expires_at` ISO; `AuthService.isAuthenticated` usa-o.
- Login com `admin@todo.ao` / `12345678` apenas em dev (utilizador do seeder local).

## 3. Camada de dados
Hoje: `DataStore` (signals) + `DataRepository` **síncrono** (`load()/save()`) + `LocalStorageRepository`.
Proposta:
1. Criar `ApiDataRepository` com `HttpClient` e **trocar o padrão para assíncrono** (`DataRepository` passa a expor operações por entidade, não `save(AppData)`), mantendo o `DataStore` como cache em signals.
2. Fluxo de arranque: após login → `GET /api/dashboard` + `GET /api/tasks?includeDone=true&includeExpired=true` + `GET /api/projects` + `GET /api/categories` + `GET /api/settings`.
3. Cada ação do store chama a API e atualiza os signals com a **resposta** (a API é a fonte de verdade — nada de atualização otimista para estados/tempo).
4. Remover do front: `seed.ts` (a seed passa a ser do servidor), `expireOverdueTasks()` (a API expira), cálculo local de `todayTasks`/`urgentTasks`/`nextSteps`/`statsByCategory`/`completedThisWeek` (usar `GET /api/dashboard`), `ImportService` local, `window.foco` com acesso direto ao store (pode manter `window.foco` a chamar a API).
5. Persistência local `foco:data:v1` deixa de ser a fonte; oferecer **migração única**: ler `localStorage` → `POST /api/import` (itens com prazo passado serão rejeitados por campo — mostrar o relatório).

## 4. Mapeamento de modelos (`core/models.ts`)
| Campo front | API |
|---|---|
| `Task.dueDate` | `YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm` (igual ao form atual) |
| `Task.activity` / `Project.activity` | só no detalhe (`GET /tasks/{id}`, `/projects/{id}`); nas listas vem ausente → tipar como opcional |
| `Task.timer` (novo) | `{ state, runningSince, trackedSeconds, serverNow, firstStartedAt }` |
| `Task.isOverdue` (novo) | boolean calculado pela API (substitui `isOverdue()` do front) |
| `Project.progress` (novo) | `{ total, done, percent }` (substitui `progress()/doneCount()/totalCount()` locais) |
| `ActivityEntry.type` | acrescentar `timer_started`, `timer_paused`, `timer_resumed`, `timer_stopped` e labels PT |
| `AppData.categories` | `GET /api/categories` devolve objetos `{ name, isDefault, ... }` → mapear para `string[]` |
| `Category` | continua `string` |
| `Status` no select | **remover `expired`** das opções (a API rejeita) |

## 5. Alterações de UI sugeridas
- **Modo foco (dashboard):** botão "Começar" → `POST /tasks/{id}/timer/start`; mostrar cronómetro (`trackedSeconds + (now - serverNow)`), botões Pausar/Retomar; usar `GET /api/timer/active` ao carregar a app para retomar a visualização do contador.
- **Detalhe da tarefa:** secção "Tempo" (total, estado, histórico via `/time-entries`), botões Iniciar/Pausar/Retomar.
- **Notificações:** ícone de sino na topbar com `GET /api/notifications/unread-count` (poll a cada 60 s e ao focar a janela), painel com lista e "marcar tudo como lido".
- **Definições:** secção Notificações (canais, hora do digest, antecedência, por tipo) e fuso horário; tema e nome via `PATCH /api/settings`.
- **Importar:** botão "Copiar prompt" usa `GET /api/import/prompt`; importar/exportar usam a API.
- **Concluir tarefa:** se a resposta trouxer `suggestCompleteProject`, mostrar toast "Marcar projeto como concluído?" (ação → `PATCH /projects/{id}` `{status:'done'}`).
- **Calendário:** `GET /api/tasks?dueFrom=&dueTo=&includeDone=true&includeExpired=true`.
- **Erros de domínio:** mapear `code` → mensagens/toasts (ver `contrato-api.md`).
- **Eliminar projeto/tarefa/limpar dados:** manter os diálogos de confirmação; chamar `DELETE`.

## 6. Adendas a acrescentar a `docs/regras-negocio.md` (front)
Copiar para o doc de regras do front as secções novas da API para manter a "fonte de verdade" única:
- §7.2 — expiração passa a ser feita pela API (tick + preguiçosa) e **tarefas com timer a correr não expiram**.
- Nova **§24 Timer** (resumo de `docs/08-timer.md`: 1 timer por utilizador, auto-pausa, estados, atividade).
- Nova **§25 Notificações** (tipos, canais, preferências, janelas de lembrete, digest, teto de emails).
- Nova **§26 API** (base URL, auth Bearer, códigos de erro).
- §5.2 — acrescentar `expired → done/todo (com novo prazo)` e `postponed → in_progress (via timer)`.
- §13.2 — resolver a contradição (importação atómica) e `projectName`.
- §3.2 — categorias validadas contra as do utilizador também na importação.

## 7. Critérios para dar o front como integrado
- [ ] Login/logout/me contra a API; 401 redireciona para `/login`.
- [ ] Todas as páginas carregam dados da API; recarregar a página mantém tudo.
- [ ] Timer: iniciar, pausar, retomar, concluir; contador consistente após recarregar e entre dispositivos.
- [ ] Sino de notificações e definições de notificações.
- [ ] Email recebido para tarefa a expirar (ambiente de teste).
- [ ] Migração do `localStorage` via importação.
- [ ] `ng build --configuration production` limpo.
