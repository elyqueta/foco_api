<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Notifications\ListNotificationsRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Notificações in-app (doc 09 §"Endpoints"): listar (não lidas, paginadas
 * por cursor), contagem de não lidas, marcar como lida (uma ou todas) e
 * remover (uma ou todas).
 *
 * Todas as consultas são sempre dentro do utilizador autenticado (regra 6):
 * uma notificação de outro utilizador — ou um `id` que não é UUID — devolve
 * `404 { "message": "Não encontrado." }` em vez de um erro da base de dados.
 */
class NotificationController extends Controller
{
    public const DEFAULT_LIMIT = 50;

    /**
     * `GET /notifications` — mais recentes primeiro. `?unread=1` filtra as
     * não lidas; `?limit=` (1…100, 50 por omissão) limita a página;
     * `?before=<uuid>` pagina para trás a partir de uma notificação.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $limit = max(1, min(self::DEFAULT_LIMIT, (int) $request->integer('limit', self::DEFAULT_LIMIT)));

        $query = $user->notifications();

        if ($request->unreadOnly()) {
            $query->unread();
        }

        $before = $request->input('before');

        if (is_string($before) && $before !== '') {
            $cursor = $user->notifications()->whereKey($before)->first();

            if (! $cursor instanceof DatabaseNotification) {
                throw DomainRuleException::withErrors(
                    'INVALID_CURSOR',
                    'A notificação de referência não foi encontrada.',
                    ['before' => ['A notificação de referência não foi encontrada.']],
                );
            }

            // Paginação por cursor: tudo o que é mais antigo que a
            // notificação de referência (com o `id` como desempate, para a
            // ordem ser estável quando duas partilham o mesmo segundo).
            $query->where(function ($query) use ($cursor): void {
                $query->where('created_at', '<', $cursor->created_at)
                    ->orWhere(function ($query) use ($cursor): void {
                        $query->where('created_at', $cursor->created_at)
                            ->where('id', '<', $cursor->id);
                    });
            });
        }

        $notifications = $query->orderByDesc('id')->limit($limit)->get();

        return NotificationResource::collection($notifications)->response();
    }

    /**
     * `GET /notifications/unread-count` — `{ count }`.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * `POST /notifications/{id}/read` — marca uma notificação como lida.
     * Idempotente: marcar uma notificação já lida não é erro.
     */
    public function read(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->findNotification($user, $id)->markAsRead();

        return response()->json(['message' => 'Notificação marcada como lida.'], 200);
    }

    /**
     * `POST /notifications/read-all` — marca todas como lidas.
     */
    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Todas as notificações foram marcadas como lidas.'], 200);
    }

    /**
     * `DELETE /notifications/{id}` — remove uma notificação.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->findNotification($user, $id)->delete();

        return response()->json(['message' => 'Notificação removida.'], 200);
    }

    /**
     * `DELETE /notifications` — remove todas as notificações do utilizador.
     */
    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->delete();

        return response()->json(['message' => 'Notificações removidas.'], 200);
    }

    /**
     * Notificação do utilizador. `id` que não é UUID → 404 (evita o erro de
     * sintaxe do PostgreSQL na coluna `uuid`).
     */
    private function findNotification(User $user, string $id): DatabaseNotification
    {
        if (! Str::isUuid($id)) {
            throw new ModelNotFoundException;
        }

        $notification = $user->notifications()->whereKey($id)->first();

        if (! $notification instanceof DatabaseNotification) {
            throw new ModelNotFoundException;
        }

        return $notification;
    }
}
