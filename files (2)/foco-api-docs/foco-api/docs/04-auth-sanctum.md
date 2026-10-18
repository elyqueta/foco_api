# Fase 4 — Autenticação (Sanctum) e definições do utilizador

## Objetivo
Sessão por token Bearer compatível com `ApiAuthRepository` do front, mais endpoint de definições (nome, tema, fuso, preferências de notificação).

## Contrato esperado pelo front (`src/app/core/auth/api-auth.repository.ts`)

| Pedido | Sucesso | Erros |
|---|---|---|
| `POST /api/auth/login` `{ email, password }` | `200 { token, user: { id, name, email }, expires_at }` (`expires_at` ISO 8601 ou `null`) | `422 { message, errors: { email?: string[], password?: string[] } }`, `401 { message }`, `429 { message }` |
| `POST /api/auth/logout` | `200`/`204` (o front lê como texto; corpo irrelevante) | `401` |
| `GET /api/auth/me` | `200 { id, name, email }` | `401` |

> Atenção: `expires_at` e `user.id` (número) são **snake/escalar** — não passar por camelCase aqui.
> O interceptor do front, em `401` fora de `/auth/login`, limpa a sessão e redireciona para `/login`.

## Tarefas

- [ ] **4.1** `App\Http\Requests\LoginRequest`: `email` required|email, `password` required|string|min:8 (mensagens PT; a mensagem do `min` do front é "Mínimo de 8 caracteres.").
- [ ] **4.2** `AuthController@login`:
  - `RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(Str::lower($r->input('email')).'|'.$r->ip()))` registado no `AppServiceProvider`; rota com `throttle:login`. Em excesso → `429 { message: "Demasiadas tentativas. Tenta novamente dentro de instantes." }`.
  - Credenciais inválidas → `401 { message: "Email ou palavra-passe incorretos." }` (mesma mensagem para email inexistente e password errada; usar `Hash::check` mesmo se o utilizador não existir para igualar tempos).
  - Sucesso → `$user->createToken('foco-web', ['*'], now()->addMinutes(config('sanctum.expiration')))`; resposta `{ token: plainTextToken, user: {id,name,email}, expires_at: <ISO> }`.
  - Nome do token inclui `User-Agent` truncado (60 chars) para o utilizador reconhecer dispositivos.
- [ ] **4.3** `logout`: `$request->user()->currentAccessToken()->delete()` → `204`.
- [ ] **4.4** `me`: devolve `{ id, name, email }`.
- [ ] **4.5** `POST /api/auth/logout-all` (extra): revoga todos os tokens do utilizador → `204`.
- [ ] **4.6** `GET/PATCH /api/settings` (auth:sanctum). `PATCH` aceita parcial:
  - `userName` (string 2–60) → `users.name`
  - `theme` (`light|dark`)
  - `timezone` (identificador PHP válido)
  - `notifications` (objeto; ver Fase 9 para a forma e validação)
  Resposta = objeto `Settings` do `contrato-api.md`. (O front guarda hoje `settings.theme`/`settings.userName` no `localStorage`; passam a vir daqui.)
- [ ] **4.7** `PATCH /api/auth/password` `{ currentPassword, password, passwordConfirmation }` (min 8) → `204`; revoga os outros tokens.
- [ ] **4.8** Middleware `auth:sanctum` em todas as rotas exceto `login` e `health`. Resposta `401` JSON `{ message: "Não autenticado." }` (garantir via `shouldRenderJsonWhen`).
- [ ] **4.9** Comando agendado (usado também no tick): `sanctum:prune-expired --hours=24`.

## Segurança
- Sem rota de registo. Sem endpoint de "esqueci a password" nesta versão (documentar: reset via `foco:create-user`/seeder com `SEED_ADMIN_RESET_PASSWORD=true`).
- Nunca registar passwords/tokens em log. `APP_DEBUG=false` em produção.
- `User` mantém `#[Hidden(['password','remember_token'])]`.

## Testes (Feature)
- login ok (estrutura exata do contrato), `expires_at` ≈ agora+7d.
- login 422 (email vazio/inválido, password < 8) com `errors.email`/`errors.password`.
- login 401 (password errada; email inexistente).
- login 429 após 5 falhas.
- `me` com token válido / sem token (401) / token expirado (401).
- logout revoga o token (segundo `me` → 401); `logout-all`.
- `settings` GET/PATCH (tema, nome, fuso inválido → 422).

## Aceitação
- O front com `useMockAuth=false` consegue autenticar com `admin@todo.ao` / `12345678` (utilizador criado em local).
- `composer test` verde.
