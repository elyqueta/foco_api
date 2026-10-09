<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Users\ProvisionUserDefaults;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Auth\LoginRequest;
use App\Http\Requests\V1\Auth\RegisterRequest;
use App\Http\Requests\V1\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Hash bcrypt (custo 12, o mesmo da configuração) de uma palavra-passe
     * arbitraria. Usado para que um email inexistente corra o mesmo
     * Hash::check de um email existente, igualando os tempos de resposta.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$79PnjmpvP4VTS755xjFSeOei/ChpdbeOuGGCJhxsZCQcQM.J9h.Vu';

    public function login(LoginRequest $request): JsonResponse
    {
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        $user = User::where('email', $email)->first();

        // Corre sempre o Hash::check (mesmo sem utilizador) para igualar tempos.
        $hash = $user?->password ?? self::DUMMY_PASSWORD_HASH;

        if (! Hash::check($password, $hash) || ! $user instanceof User) {
            return response()->json([
                'message' => 'Email ou palavra-passe incorretos.',
            ], 401);
        }

        return $this->issueToken($request, $user);
    }

    /**
     * Cria o token Sanctum (nome por dispositivo + expiração da config) e
     * devolve o payload de autenticação do contrato.
     */
    private function issueToken(Request $request, User $user, int $status = 200): JsonResponse
    {
        $tokenName = 'foco-web';
        $userAgent = $request->userAgent();

        if (is_string($userAgent) && $userAgent !== '') {
            $tokenName .= ' | '.Str::limit($userAgent, 60, '…');
        }

        $expiration = config('sanctum.expiration');
        $expiresAt = is_numeric($expiration) ? now()->addMinutes((int) $expiration) : null;

        $token = $user->createToken($tokenName, ['*'], $expiresAt);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user),
            'expires_at' => $expiresAt?->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $status);
    }

    /**
     * Auto-registo de utilizador comum. Cria a conta, provisiona as 3
     * categorias padrão elimináveis (sem projetos nem tarefas) e devolve o
     * mesmo payload do login (token + user + expires_at), ou seja, o
     * utilizador fica autenticado a seguir ao registo.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            app(ProvisionUserDefaults::class)->handle($user, protected: false);

            return $user;
        });

        return $this->issueToken($request, $user, 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(null, 204);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $user?->tokens()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->userPayload($user));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user instanceof User) {
            DB::transaction(function () use ($user, $data): void {
                $user->update(['password' => $data['password']]);

                $currentTokenId = $user->currentAccessToken()?->id;

                $user->tokens()->where('id', '!=', $currentTokenId)->delete();
            });
        }

        return response()->json(null, 204);
    }

    /**
     * @return array{id: int, name: string, email: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
