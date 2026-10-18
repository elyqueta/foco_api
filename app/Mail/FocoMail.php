<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\NotificationType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email de uma notificação do Foco (doc 10 §"Emails").
 *
 * Uma única classe para todos os tipos: o canal `mail` do
 * `FocoNotification` devolve este mailable e o conteúdo já vem traduzido por
 * `App\Notifications\Messages` (título/corpo PT, com o assunto indicado pelo
 * `taskId`/`projectId`). A vista Markdown é escolhida pelo tipo
 * (`mail.task-due-soon`, `mail.daily-digest`, …), com `mail.generic` como
 * rede de segurança para os tipos sem vista própria.
 *
 * Regras do doc 10: assunto com prefixo curto (`[Foco] …`), botão "Abrir no
 * Foco" apontado a `FRONTEND_URL` e rodapé com o link para desligar as
 * notificações. Nada de dados sensíveis além do título/prazo — os títulos
 * são escapados pelo Blade (sem `{!! !!}`).
 */
class FocoMail extends Mailable
{
    use Queueable;

    /**
     * @param  array{title: string, body: string, taskId?: string, projectId?: string}  $content
     * @param  array<string, mixed>  $context  extras do disparo (ex. `sections` do digest)
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly array $content,
        private readonly ?Model $subjectModel = null,
        private readonly array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Foco] '.$this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->viewName(),
            with: [
                'title' => $this->title(),
                'body' => $this->content['body'] ?? '',
                'actionUrl' => $this->actionUrl(),
                'actionLabel' => 'Abrir no Foco',
                'settingsUrl' => $this->settingsUrl(),
                'sections' => $this->sections(),
            ],
        );
    }

    /**
     * Vista Markdown do tipo, com rede de segurança para os tipos que não
     * têm vista própria (ex. `task_completed`).
     */
    private function viewName(): string
    {
        $view = 'mail.'.str_replace('_', '-', $this->type->value);

        return view()->exists($view) ? $view : 'mail.generic';
    }

    private function title(): string
    {
        $title = $this->content['title'] ?? null;

        return is_string($title) && trim($title) !== '' ? $title : 'Notificação do Foco';
    }

    /**
     * Assunto depois do prefixo: `{título}` e, quando há assunto, o seu nome
     * (ex.: "Tarefa a expirar: Enviar relatório").
     */
    private function subjectLine(): string
    {
        $name = $this->subjectName();

        return $name === '' ? $this->title() : $this->title().': '.$name;
    }

    private function subjectName(): string
    {
        return match (true) {
            $this->subjectModel instanceof Task => (string) $this->subjectModel->title,
            $this->subjectModel instanceof Project => (string) $this->subjectModel->name,
            default => '',
        };
    }

    /**
     * Link do botão: a tarefa ou o projeto no front; sem assunto (digest,
     * email agregado) vai para a raiz.
     */
    private function actionUrl(): string
    {
        $base = rtrim((string) config('services.frontend.url'), '/');

        return match (true) {
            $this->subjectModel instanceof Task => $base.'/tarefas/'.$this->subjectModel->id,
            $this->subjectModel instanceof Project => $base.'/projetos/'.$this->subjectModel->id,
            default => $base.'/',
        };
    }

    private function settingsUrl(): string
    {
        return rtrim((string) config('services.frontend.url'), '/').'/definicoes';
    }

    /**
     * Secções do digest/agregado (lista de `{ label, items }`).
     *
     * @return list<array{label: string, items: list<string>}>
     */
    private function sections(): array
    {
        $sections = $this->context['sections'] ?? [];

        if (! is_array($sections)) {
            return [];
        }

        $rows = [];

        foreach ($sections as $section) {
            if (! is_array($section) || ! is_array($section['items'] ?? null)) {
                continue;
            }

            $items = array_values(array_filter(
                array_map(static fn ($item): string => (string) $item, $section['items']),
                static fn (string $item): bool => $item !== '',
            ));

            if ($items === []) {
                continue;
            }

            $rows[] = [
                'label' => is_string($section['label'] ?? null) ? $section['label'] : '',
                'items' => $items,
            ];
        }

        return $rows;
    }
}
