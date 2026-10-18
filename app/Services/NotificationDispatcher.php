<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\FocoNotification;
use App\Notifications\Messages;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envio de notificações com dedupe e retry (doc 09 §"Arquitetura").
 *
 * Um disparo grava **um registo por canal** em `notification_dispatch_log`
 * (índice único `(user_id, type, channel, subject_id, dedupe_key)`), o que
 * garante:
 *  - **idempotência** — repetir o mesmo evento (tick a correr duas vezes,
 *    duplo clique) não cria uma segunda notificação;
 *  - **canais independentes** — uma falha de email não impede o in-app nem
 *    o duplica num retry;
 *  - **retry** — uma linha `failed` volta a ser tentada até
 *    `MAX_ATTEMPTS`, com o erro guardado (truncado).
 *
 * O dispatcher **nunca faz falhar o pedido HTTP** que o chamou: qualquer
 * exceção é registada (`report`) e o envio fica `failed`/`pending` para a
 * tentativa seguinte.
 *
 * Os canais resolvem-se pelas preferências do utilizador
 * (`NotificationPreference::channelEnabled`): `canal activo =
 * prefs.{canal}Enabled && (types[tipo][canal] ?? default_do_tipo)`.
 */
class NotificationDispatcher
{
    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_EMAIL = 'email';

    /**
     * Nome do canal do Laravel para a tabela `notifications` (o valor
     * guardado no log é `in_app`).
     */
    public const LARAVEL_DATABASE_CHANNEL = 'database';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Tentativas máximas por canal (doc 09 / doc 10 §10.5).
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Tecto do texto de erro guardado no log (coluna `text`).
     */
    private const ERROR_LIMIT = 500;

    public function __construct(
        private readonly Messages $messages,
        // Contrato (e não a facade `Notification`): permite `Notification::fake()`
        // nos testes da Fase 10 e evita a colisão de nomes com a classe
        // `Illuminate\Notifications\Notification`.
        private readonly NotificationSender $notifier,
    ) {}

    /**
     * Despacha a notificação em todos os canais activos do utilizador.
     * Devolve `true` se pelo menos um canal foi enviado **agora** (um
     * disparo repetido devolve `false`).
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatch(
        User $user,
        NotificationType $type,
        ?Model $subject,
        string $dedupeKey,
        array $context = [],
    ): bool {
        return $this->dispatchChannels(
            $user,
            $type,
            $subject,
            $dedupeKey,
            $this->activeChannels($user, $type),
            $context,
        );
    }

    /**
     * Despacha apenas nos canais indicados (usado pelo tick da Fase 10 para
     * aplicar o teto diário de emails e a agregação anti-spam). `$skipReason`
     * regista todos esses canais como `skipped` sem enviar.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $context
     */
    public function dispatchChannels(
        User $user,
        NotificationType $type,
        ?Model $subject,
        string $dedupeKey,
        array $channels,
        array $context = [],
        ?string $skipReason = null,
    ): bool {
        $sent = false;

        foreach ($channels as $channel) {
            if ($this->dispatchChannel($user, $type, $subject, $dedupeKey, $channel, $context, $skipReason)) {
                $sent = true;
            }
        }

        return $sent;
    }

    /**
     * Despacha num único canal. `$skipReason` regista o canal como
     * `skipped` com o motivo (ex. `daily_limit`, `batched`) sem enviar.
     *
     * @param  array<string, mixed>  $context
     */
    public function dispatchChannel(
        User $user,
        NotificationType $type,
        ?Model $subject,
        string $dedupeKey,
        string $channel,
        array $context = [],
        ?string $skipReason = null,
    ): bool {
        try {
            return $channel === self::CHANNEL_IN_APP
                ? $this->sendInApp($user, $type, $subject, $dedupeKey, $context, $skipReason)
                : $this->sendEmail($user, $type, $subject, $dedupeKey, $context, $skipReason);
        } catch (Throwable $e) {
            // Nunca falhar o pedido chamador: o envio fica para a próxima
            // tentativa (row `pending`/`failed`).
            report($e);

            return false;
        }
    }

    /**
     * Canais activos do utilizador para o tipo, por ordem (in-app primeiro).
     *
     * @return list<string>
     */
    public function activeChannels(User $user, NotificationType $type): array
    {
        $channels = [];

        foreach ([self::CHANNEL_IN_APP, self::CHANNEL_EMAIL] as $channel) {
            if ($this->preferences($user)->channelEnabled($type->value, $channel)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * O evento já foi entregue em algum canal? Usado pelo tick para marcar
     * `projects.overdue_notified_at` só depois de a notificação existir
     * (in-app ou email) — assim uma falha de envio tenta novamente no tick
     * seguinte em vez de marcar o projeto como avisado.
     */
    public function wasDelivered(User $user, NotificationType $type, ?Model $subject, string $dedupeKey): bool
    {
        return $this->find($user, $type, self::CHANNEL_IN_APP, $subject, $dedupeKey)
            ->where('status', self::STATUS_SENT)
            ->exists()
            || $this->find($user, $type, self::CHANNEL_EMAIL, $subject, $dedupeKey)
                ->where('status', self::STATUS_SENT)
                ->exists();
    }

    /**
     * Emails já enviados ao utilizador no dia UTC (teto diário da Fase 10:
     * `MAIL_DAILY_LIMIT`, contado no log onde `channel=email` e
     * `status=sent`).
     */
    public function emailsSentToday(User $user): int
    {
        return NotificationDispatchLog::query()
            ->where('user_id', $user->id)
            ->where('channel', self::CHANNEL_EMAIL)
            ->where('status', self::STATUS_SENT)
            ->where('sent_at', '>=', now()->utc()->startOfDay())
            ->count();
    }

    /**
     * Canal in-app: cria a `DatabaseNotification` na mesma transação que
     * reclama a linha do log, para que dois pedidos concorrentes não
     * enviem duas vezes (a linha fica bloqueada até ao commit).
     *
     * @param  array<string, mixed>  $context
     */
    private function sendInApp(
        User $user,
        NotificationType $type,
        ?Model $subject,
        string $dedupeKey,
        array $context,
        ?string $skipReason,
    ): bool {
        if ($skipReason !== null) {
            return $this->recordSkipped($user, $type, self::CHANNEL_IN_APP, $subject, $dedupeKey, $skipReason);
        }

        try {
            return DB::transaction(function () use ($user, $type, $subject, $dedupeKey, $context): bool {
                $log = $this->claim($user, $type, self::CHANNEL_IN_APP, $subject, $dedupeKey);

                if (! $log instanceof NotificationDispatchLog) {
                    return false;
                }

                $notification = $this->notification($user, $type, $subject, $context);

                $this->notifier->sendNow(
                    $user,
                    $notification->viaChannels([self::LARAVEL_DATABASE_CHANNEL]),
                    [self::LARAVEL_DATABASE_CHANNEL],
                );

                $this->mark($log, self::STATUS_SENT);

                return true;
            });
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($user, $type, self::CHANNEL_IN_APP, $subject, $dedupeKey, $e);

            return false;
        }
    }

    /**
     * Canal email (doc 10, Resend): mesma forma do in-app — a linha do log
     * é reclamada na mesma transação do envio, para que uma falha não deixe
     * o canal marcado como enviado e um retry não duplique a mensagem.
     *
     * @param  array<string, mixed>  $context
     */
    private function sendEmail(
        User $user,
        NotificationType $type,
        ?Model $subject,
        string $dedupeKey,
        array $context,
        ?string $skipReason,
    ): bool {
        if ($skipReason !== null) {
            return $this->recordSkipped($user, $type, self::CHANNEL_EMAIL, $subject, $dedupeKey, $skipReason);
        }

        // Sem representação em email nada é enviado nem registado (hoje todos
        // os tipos a têm; a rede fica para tipos futuros sem `toMail`).
        if (! (new FocoNotification($type, []))->supportsMail()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($user, $type, $subject, $dedupeKey, $context): bool {
                $log = $this->claim($user, $type, self::CHANNEL_EMAIL, $subject, $dedupeKey);

                if (! $log instanceof NotificationDispatchLog) {
                    return false;
                }

                $notification = $this->notification($user, $type, $subject, $context);

                $this->notifier->sendNow(
                    $user,
                    $notification->viaChannels(['mail']),
                    ['mail'],
                );

                $this->mark($log, self::STATUS_SENT);

                return true;
            });
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($user, $type, self::CHANNEL_EMAIL, $subject, $dedupeKey, $e);

            return false;
        }
    }

    /**
     * Notificação pronta a enviar: o conteúdo vem do `Messages` (fonte única
     * dos textos PT) e o assunto/contexto viajam com a notificação para o
     * canal email sem serem gravados em `notifications.data`.
     *
     * @param  array<string, mixed>  $context
     */
    private function notification(
        User $user,
        NotificationType $type,
        ?Model $subject,
        array $context,
    ): FocoNotification {
        return new FocoNotification(
            $type,
            $this->messages->make($user, $type, $subject, $context),
            $subject,
            $context,
        );
    }

    /**
     * Reclama a linha do canal para este envio: cria-a se não existir e
     * devolve `null` quando o disparo não deve prosseguir (já enviado, já
     * saltado ou sem tentativas restantes). A leitura é `lockForUpdate`
     * (ignorada no SQLite) para serializar disparos concorrentes do mesmo
     * evento.
     */
    private function claim(
        User $user,
        NotificationType $type,
        string $channel,
        ?Model $subject,
        string $dedupeKey,
    ): ?NotificationDispatchLog {
        $this->insertPending($user, $type, $channel, $subject, $dedupeKey);

        /** @var NotificationDispatchLog|null $log */
        $log = $this->find($user, $type, $channel, $subject, $dedupeKey)
            ->lockForUpdate()
            ->first();

        if (! $log instanceof NotificationDispatchLog) {
            return null;
        }

        if ($log->status === self::STATUS_SENT || $log->status === self::STATUS_SKIPPED) {
            return null;
        }

        if ($log->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        $log->increment('attempts');

        return $log;
    }

    /**
     * Garante que existe uma linha `pending` para o disparo (`insert or
     * ignore`): a chave única é o que faz a dedupe, e uma linha já existente
     * nunca é rebaixada.
     */
    private function insertPending(
        User $user,
        NotificationType $type,
        string $channel,
        ?Model $subject,
        string $dedupeKey,
    ): void {
        // Disparos **sem assunto** (digest diário, email agregado) não são
        // protegidos pelo índice único: PostgreSQL e SQLite tratam NULLs
        // como distintos. Sem esta guarda cada tick acumularia uma linha
        // nova para a mesma chave. Estes disparos só acontecem dentro do
        // tick, serializado pelo lock `foco:tick`.
        if ($subject === null && $this->find($user, $type, $channel, $subject, $dedupeKey)->exists()) {
            return;
        }

        DB::table('notification_dispatch_log')->insertOrIgnore([
            'user_id' => $user->id,
            'type' => $type->value,
            'channel' => $channel,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'dedupe_key' => $dedupeKey,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Query da linha do log para um disparo concreto (sem executar).
     */
    private function find(
        User $user,
        NotificationType $type,
        string $channel,
        ?Model $subject,
        string $dedupeKey,
    ): Builder {
        return NotificationDispatchLog::query()
            ->where('user_id', $user->id)
            ->where('type', $type->value)
            ->where('channel', $channel)
            ->where('subject_type', $subject?->getMorphClass())
            ->where('subject_id', $subject?->getKey())
            ->where('dedupe_key', $dedupeKey);
    }

    /**
     * Regista o canal como `skipped` com o motivo. Uma linha já `sent` (ou
     * `skipped`) nunca é rebaixada.
     */
    private function recordSkipped(
        User $user,
        NotificationType $type,
        string $channel,
        ?Model $subject,
        string $dedupeKey,
        string $reason,
    ): bool {
        $this->insertPending($user, $type, $channel, $subject, $dedupeKey);

        $this->find($user, $type, $channel, $subject, $dedupeKey)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED])
            ->update([
                'status' => self::STATUS_SKIPPED,
                'error' => Str::limit($reason, self::ERROR_LIMIT, '…'),
                'sent_at' => null,
                'updated_at' => now(),
            ]);

        return false;
    }

    private function mark(NotificationDispatchLog $log, string $status): void
    {
        $log->forceFill([
            'status' => $status,
            'sent_at' => $status === self::STATUS_SENT ? now() : null,
            'error' => null,
        ])->save();
    }

    /**
     * Marca a tentativa falhada. À terceira tentativa a linha passa a
     * `failed` e deixa de ser tentada; antes disso volta a `pending` para
     * o retry seguinte.
     *
     * A transação que falhou desfez também a linha criada pelo `claim`, por
     * isso ela volta a ser criada aqui — nunca sobrescrevendo um `sent`.
     */
    private function markFailed(
        User $user,
        NotificationType $type,
        string $channel,
        ?Model $subject,
        string $dedupeKey,
        Throwable $e,
    ): void {
        $this->insertPending($user, $type, $channel, $subject, $dedupeKey);

        /** @var NotificationDispatchLog|null $log */
        $log = $this->find($user, $type, $channel, $subject, $dedupeKey)->first();

        if (! $log instanceof NotificationDispatchLog) {
            return;
        }

        $attempts = (int) $log->attempts + 1;

        $log->forceFill([
            'status' => $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : self::STATUS_PENDING,
            'attempts' => $attempts,
            'error' => Str::limit($e->getMessage(), self::ERROR_LIMIT, '…'),
            'sent_at' => null,
        ])->save();
    }

    /**
     * Preferências do utilizador; sem registo (conta antiga), os defaults.
     */
    private function preferences(User $user): NotificationPreference
    {
        $preferences = $user->notificationPreference;

        if ($preferences instanceof NotificationPreference) {
            return $preferences;
        }

        $preferences = new NotificationPreference(NotificationPreference::defaultAttributes());
        $preferences->user_id = $user->id;

        return $preferences;
    }
}
