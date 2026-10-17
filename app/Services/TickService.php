<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Notifications\SendDailyDigests;
use App\Actions\Notifications\SendReminders;
use App\Actions\Tasks\ExpireOverdueTasks;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tick do scheduler (doc 10): unidade única de trabalho chamada de fora
 * (`POST /api/internal/scheduler/tick` ou o comando `foco:tick`). Um tick
 * corre **uma vez de cada vez** — o lock `foco:tick` faz o agendador externo
 * sobrepor-se a si mesmo sem processar duas vezes.
 *
 * Passos (a ordem importa: a expiração cria as notificações antes dos
 * lembretes):
 *  1. `ExpireOverdueTasks::forAll()` — expira tarefas vencidas e notifica
 *     `task_expired` (in-app + email);
 *  2. higiene dos timers (`foco:check-timers`): fecha os esquecidos > 16 h
 *     com `auto_pause`, avisa dos timers longos e da estimativa ultrapassada;
 *  3. `SendReminders` por utilizador, no fuso dele (lembretes de prazo);
 *  4. `SendDailyDigests` por utilizador, no fuso dele (digest diário);
 *  5. limpeza 1×/dia: tokens Sanctum expirados + notificações antigas.
 *
 * **Nunca levanta excepção:** cada passo falha sozinho (`report()` + conta
 * no `failed`) e o tick devolve sempre o resumo.
 * `{ ok, durationMs, expired, sent: { in_app, email }, skipped, failed, timeBudgetExceeded }`.
 *
 * O **orçamento de tempo** (`time_budget`, ~25 s) é respeitado: passado o
 * prazo, o tick deixa de processar novos itens e responde com
 * `timeBudgetExceeded: true` — o que falta corre no tick seguinte (tudo é
 * idempotente, as janelas usam `<=` e a dedupe está no log).
 */
class TickService
{
    /**
     * Chave do lock: só um tick de cada vez, mesmo com o agendador externo
     * a chamar mais do que uma vez por minuto.
     */
    public const LOCK_KEY = 'foco:tick';

    /**
     * Vida do lock. Maior que o orçamento de tempo de sobra, para o caso de
     * o processo morrer a meio (o lock expira sozinho).
     */
    public const LOCK_SECONDS = 120;

    /**
     * Prefixo da chave que garante a limpeza uma única vez por dia
     * (`Cache::add` devolve false se já existe).
     */
    private const PRUNE_KEY_PREFIX = 'foco:prune:';

    /**
     * Já existe um tick a correr: `202` com `{ ok, skipped }`.
     */
    public const SKIPPED_LOCKED = 'locked';

    public function run(): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return [
                'ok' => true,
                'skipped' => self::SKIPPED_LOCKED,
            ];
        }

        try {
            return $this->execute();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{ok: bool, durationMs: int, expired: int, sent: array{in_app: int, email: int}, skipped: int, failed: int, timeBudgetExceeded: bool}
     */
    private function execute(): array
    {
        $startedAt = CarbonImmutable::now();
        $startedMicrotime = microtime(true);
        $budgetExceeded = false;
        $expired = 0;
        $failures = 0;

        // 1. Expiração (cria `task_expired`, que já segue os canais do
        //    utilizador).
        try {
            $expired = app(ExpireOverdueTasks::class)->forAll();
        } catch (Throwable $e) {
            report($e);
            $failures++;
        }

        // 2. Higiene dos timers — o mesmo que `foco:check-timers` corre.
        $failures += $this->guard(fn () => app(CloseStaleTimers::class)->handle()) ? 0 : 1;
        $failures += $this->guard(fn () => app(NotifyLongTimers::class)->handle()) ? 0 : 1;

        // 3. e 4. Lembretes e digest por utilizador, cada um no seu fuso.
        //    `notificationPreference` vem carregada para não haver uma query
        //    por utilizador a procurar as preferências.
        $users = User::query()->with('notificationPreference')->get();
        $deadline = $startedAt->addSeconds($this->budgetSeconds());

        $steps = [
            fn (User $user) => app(SendReminders::class)->forUser($user),
            fn (User $user) => app(SendDailyDigests::class)->forUser($user),
        ];

        foreach ($steps as $step) {
            foreach ($users as $user) {
                if (CarbonImmutable::now() >= $deadline) {
                    $budgetExceeded = true;
                    break 2;
                }

                $failures += $this->guard(fn () => $step($user)) ? 0 : 1;
            }
        }

        // 5. Limpeza: uma vez por dia (o lock do tick não chega — o
        //    agendador chama o tick várias vezes ao dia).
        if (Cache::add(self::PRUNE_KEY_PREFIX.$startedAt->format('Y-m-d'), 1, 86400)) {
            $failures += $this->guard(fn () => Artisan::call('sanctum:prune-expired --hours=24')) ? 0 : 1;
            $failures += $this->guard(fn () => Artisan::call('foco:prune-notifications')) ? 0 : 1;
        }

        [$sent, $skipped, $failed] = $this->counts($startedAt);

        return [
            'ok' => true,
            'durationMs' => (int) round((microtime(true) - $startedMicrotime) * 1000),
            'expired' => $expired,
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed + $failures,
            'timeBudgetExceeded' => $budgetExceeded,
        ];
    }

    /**
     * Corre um passo sem deixar falhar o tick: cada passo é independente e
     * uma falha conta no resumo. Devolve `true` se correu sem excepção.
     */
    private function guard(callable $step): bool
    {
        try {
            $step();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Contagens do resumo, lidas do próprio log do dispatcher: o que mudou
     * durante o tick é o que o tick fez. `failed` conta as linhas que
     * ficaram `failed`/`pending` por esgotar tentativas.
     *
     * @return array{0: array{in_app: int, email: int}, 1: int, 2: int}
     */
    private function counts(CarbonImmutable $startedAt): array
    {
        $rows = NotificationDispatchLog::query()
            ->where('updated_at', '>=', $startedAt)
            ->get(['channel', 'status']);

        $sent = ['in_app' => 0, 'email' => 0];
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if ($row->status === NotificationDispatcher::STATUS_SENT) {
                $channel = $row->channel === NotificationDispatcher::CHANNEL_IN_APP ? 'in_app' : $row->channel;
                $sent[$channel] = ($sent[$channel] ?? 0) + 1;
            } elseif ($row->status === NotificationDispatcher::STATUS_SKIPPED) {
                $skipped++;
            } elseif ($row->status === NotificationDispatcher::STATUS_FAILED) {
                $failed++;
            }
        }

        return [$sent, $skipped, $failed];
    }

    /**
     * Segundos de trabalho novos antes de o tick parar por orçamento. `0`
     * significa "sem passos por utilizador" (só expiração e higiene).
     */
    private function budgetSeconds(): int
    {
        return max(0, (int) config('services.scheduler.time_budget', 25));
    }
}
