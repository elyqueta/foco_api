<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificação in-app do Foco (doc 09 §"Arquitetura"): carrega o tipo e o
 * conteúdo PT já construído por `Messages` e grava-se na tabela
 * `notifications` do Laravel pelo canal `database`.
 *
 * O `type` guardado é o valor do enum (`task_completed`, …) e não o nome da
 * classe — ver `databaseType()`. Os canais são passados explicitamente pelo
 * `NotificationDispatcher` (`Notification::sendNow(..., ['database'])`), por
 * isso `via()` só define o que usar quando a notificação é enviada sem essa
 * indicação.
 *
 * O canal `email` (Resend) chega na Fase 10: até lá `supportsMail()` é
 * `false` e o dispatcher não tenta esse canal.
 */
class FocoNotification extends Notification
{
    /**
     * @var list<string>
     */
    private array $channels = ['database'];

    /**
     * @param  array{title: string, body: string, taskId?: string, projectId?: string}  $content
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly array $content,
    ) {}

    /**
     * Conteúdo gravado em `notifications.data` (o `title`/`body` PT e o
     * identificador do assunto no mesmo array).
     *
     * @return array{title: string, body: string, taskId?: string, projectId?: string}
     */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->content;
    }

    /**
     * Tipo visível na API (contrato: `type` = valor do `NotificationType`).
     */
    public function databaseType(mixed $notifiable): string
    {
        return $this->type->value;
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Canais a usar quando a notificação é enviada sem indicação explícita.
     *
     * @param  list<string>  $channels
     */
    public function viaChannels(array $channels): static
    {
        $this->channels = $channels;

        return $this;
    }

    /**
     * Ainda não há envio de email nesta fase (Fase 10 implementa `toMail`).
     */
    public function supportsMail(): bool
    {
        return false;
    }

    public function toMail(mixed $notifiable): ?MailMessage
    {
        return null;
    }
}
