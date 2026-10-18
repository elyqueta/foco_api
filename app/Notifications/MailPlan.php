<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * Plano de um email individual criado pelo tick (doc 10). O `SendReminders`
 * e o `SendDailyDigests` constroem a lista de planos do utilizador; o
 * `MailGate` decide depois se os envia um a um, se agrega num único email
 * (> 5 no mesmo tick) ou se os marca como `skipped` por teto diário
 * (`MAIL_DAILY_LIMIT`).
 */
final class MailPlan
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly ?Model $subject,
        public readonly string $dedupeKey,
        public readonly array $context = [],
    ) {}
}
