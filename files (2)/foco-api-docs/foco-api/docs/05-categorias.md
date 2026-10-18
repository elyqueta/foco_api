# Fase 5 — Categorias

## Objetivo
Gestão de categorias por utilizador, igual à página `/categorias` do front (regras §3 do doc de negócio).

## Regras
- Padrão e **não removíveis**: `professional`, `personal`, `household` (`is_default=true`, criadas pelo `UserObserver`).
- Nome: trim, **mínimo 2 caracteres**, máximo 60, **sem duplicados** (comparação por `name_key` = minúsculas e espaços internos colapsados). Duplicado → `422 { errors: { name: ["Esta categoria já existe."] } }`.
- Ao **remover** uma categoria personalizada: numa transação, todas as tarefas **e projetos** do utilizador com essa categoria passam a `professional`; depois a categoria é apagada. Atividade `edited` ("Categoria alterada para professional") em cada tarefa/projeto afetado.
- Categorias não são renomeadas (fora de âmbito).
- O front guarda o **nome** como valor (`task.category = 'Estudos'`); a API devolve o nome exatamente como foi escrito.

## Endpoints

| Método | Rota | Corpo | Resposta |
|---|---|---|---|
| GET | `/api/categories` | — | `[ { name, isDefault, tasksCount, projectsCount } ]` (padrão primeiro, depois ordem alfabética) |
| POST | `/api/categories` | `{ name }` | `201 { name, isDefault:false, tasksCount:0, projectsCount:0 }` |
| DELETE | `/api/categories/{name}` | — | `204`. Padrão → `422 { code: "CATEGORY_PROTECTED", message: "As categorias padrão não podem ser removidas." }`. Inexistente → `404` |

`{name}` na rota é URL-encoded; resolver por `name_key`.

## Tarefas
- [ ] **5.1** `CategoryController`, `StoreCategoryRequest`, `CategoryResource`, `CategoryPolicy` (só o dono).
- [ ] **5.2** Action `App\Actions\Categories\RemoveCategory` (transação + atividade + reatribuição).
- [ ] **5.3** Regra reutilizável `ValidCategory` (usada nas Fases 6, 7 e 11): o valor tem de existir em `categories` do utilizador (comparação por `name_key`; guardar o **nome canónico** da tabela, não o texto recebido).
- [ ] **5.4** Contagens via `withCount` (sem N+1).

## Testes
- Listar devolve as 3 padrão no arranque.
- Criar ok; duplicado (mesmo com maiúsculas/minúsculas) 422; 1 caractere 422.
- Remover padrão 422; remover custom reatribui tarefas **e** projetos a `professional` e cria atividade.
- Isolamento: utilizador B não vê/remove categorias do A.

## Aceitação
Todos os testes verdes; `route:list` mostra as 3 rotas protegidas.
