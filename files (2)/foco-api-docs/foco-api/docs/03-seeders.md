# Fase 3 — Seeders

## Objetivo
Seeders **idempotentes** (podem correr em cada arranque) para: utilizador admin, categorias padrão, preferências e dados de demonstração opcionais. Como o Render free não tem Shell, o entrypoint corre `ProductionSeeder` quando `RUN_SEEDERS=true`.

## Tarefas

- [ ] **3.1** `UserObserver::created`: cria as 3 categorias padrão (`professional`, `personal`, `household`, `is_default=true`) e a linha de `notification_preferences` com defaults. Registar no `AppServiceProvider`. Extrair a lógica para `App\Actions\Users\ProvisionUserDefaults` (reutilizável pelo comando e pelo seeder).
- [ ] **3.2** `Database\Seeders\ProductionSeeder` (seguro em produção):
  - Lê `SEED_ADMIN_NAME`, `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` do ambiente.
  - Se email ou password faltarem → **não faz nada** e escreve aviso no log (nunca cria utilizador com password por defeito em produção).
  - `User::updateOrCreate(['email' => …], ['name' => …, 'password' => …])` — só atualiza a password se `SEED_ADMIN_RESET_PASSWORD=true`.
  - Chama `ProvisionUserDefaults` (idempotente: `firstOrCreate` por `name_key`).
  - Se `SEED_DEMO_DATA=true` → `DemoDataSeeder` para esse utilizador **apenas se não tiver projetos nem tarefas**.
- [ ] **3.3** `Database\Seeders\DatabaseSeeder` (dev/local): chama `ProductionSeeder`; se `APP_ENV=local` e variáveis vazias, usa o utilizador demo do front: `admin@todo.ao` / `12345678` / nome `Zua` (**apenas em `local`/`testing`**).
- [ ] **3.4** `Database\Seeders\DemoDataSeeder` — espelha §23 do doc de regras do front, usando as Actions reais (para gerar atividade, estados e datas corretos) e datas **relativas a hoje no fuso do utilizador**:
  - Projetos: **Loja Nerd** (`personal`, `high`, cor `#3FBF9A`, nextStep "Definir catálogo inicial de 10 produtos", descrição "Marca de acessórios tech para geeks e gamers.") e **destino-mussulo** (`professional`, `critical`, `canPostpone=false`, cor `#6C5CE7`, nextStep "Integrar API real no backend", descrição "Plataforma de reservas para o Mussulo.").
  - Tarefas: (1) "Integrar API real no destino-mussulo" — professional, critical, `canPostpone=false`, hoje, estimativa 90, tags `angular, api`, nextStep "Trocar mock service por HttpClient"; (2) "Definir catálogo inicial" — personal, high, `in_progress`, hoje, 60 min, tag `produto`, nextStep "Pesquisar fornecedores de acessórios"; (3) "Compras do supermercado" — household, medium, hoje, 30 min, tag `casa`, nextStep "Fazer lista antes de sair"; (4) "Ligar para a mãe" — personal, low, sem prazo, 15 min.
  - Todas com atividade `created`. Tarefa 2 (`in_progress`) **sem** timer a correr.
- [ ] **3.5** Comando `php artisan foco:create-user {email} {--name=} {--password=}` (pede password por prompt se omitida; valida ≥ 8 chars). Usa `ProvisionUserDefaults`. Para uso local/CI.
- [ ] **3.6** Garantir que `RUN_SEEDERS=true` no entrypoint chama só `ProductionSeeder` (já definido na Fase 1).

## Testes
- `ProductionSeederTest`: sem variáveis → nenhum utilizador criado; com variáveis → cria utilizador + 3 categorias + preferências; correr 2× não duplica; password só muda com `SEED_ADMIN_RESET_PASSWORD=true`.
- `DemoDataSeederTest`: cria 2 projetos e 4 tarefas, uma só `in_progress`, nenhuma com timer aberto; não corre se o utilizador já tem dados.
- `CreateUserCommandTest`.

## Aceitação
- `php artisan migrate:fresh --seed` em local cria `admin@todo.ao` com login válido (usado pelo front em modo API).
- Em produção sem `SEED_ADMIN_*` nenhum utilizador é criado.
