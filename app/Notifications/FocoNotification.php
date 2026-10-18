<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Mail\FocoMail;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Notificação do Foco (doc 09 §"Arquitetura" + doc 10 §"Emails"): carrega o
 * tipo e o conteúdo PT já construído por `Messages` e grava-se na tabela
 * `notifications` do Laravel pelo canal `database`.
 *
 * O `type` guardado é o valor do enum (`task_completed`, …) e não o nome da
 * classe — ver `databaseType()`. Os canais são passados explicitamente pelo
 * `NotificationDispatcher` (`Notification::sendNow(..., ['database'])`), por
 * isso `via()` só define o que usar quando a notificação é enviada sem essa
 * indicação.
 *
 * O canal `email` (doc 10, Resend) devolve o mailable `FocoMail`: mesmo
 * conteúdo PT, assunto com prefixo `[Foco]`, botão para o front e rodapé com
 * o link das definições. O assunto e os extras (secções do digest) viajam no
 * construtor porque `notifications.data` tem de ficar plano
 * (`{ title, body, taskId? | projectId? }`) — o que só importa ao email não
 * é gravado na base de dados.
 */
class FocoNotification extends Notification
{
    /**
     * @var list<string>
     */
    private array $channels = ['database'];

    /**
     * @param  array{title: string, body: string, taskId?: string, projectId?: string}  $content
     * @param  array<string, mixed>  $context  extras do disparo (ex. `sections`, `minutes`)
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly array $content,
        private readonly ?Model $subject = null,
        private readonly array $context = [],
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
     * Todos os tipos têm representação em email (doc 10): quem decide se o
     * canal email entra são as preferências do utilizador, resolvidas pelo
     * dispatcher.
     */
    public function supportsMail(): bool
    {
        return true;
    }

    /**
     * Email (doc 10). O canal `mail` do Laravel chama `toMail` com o
     * notificável e espera que o mailable traga o destinatário — por isso o
     * `->to()` é feito aqui (o `FocoMail` não conhece o utilizador).
     */
    public function toMail(mixed $notifiable): MailableContract
    {
        $mail = new FocoMail($this->type, $this->content, $this->subject, $this->context);

        $email = is_object($notifiable) ? ($notifiable->email ?? null) : null;

        return is_string($email) && $email !== '' ? $mail->to($email) : $mail;
    }

    /**
     * Nome do assunto usado nos assuntos dos emails (título da tarefa, nome
     * do projeto).
     */
    public function subjectName(): string
    {
        return match (true) {
            $this->subject instanceof Task => (string) $this->subject->title,
            $this->subject instanceof Project => (string) $this->subject->name,
            default => '',
        };
    }
}
