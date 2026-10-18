<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskTimeEntry;
use Illuminate\Support\Facades\DB;

/**
 * Fecha a entrada de tempo aberta de uma tarefa, se existir (efeitos de
 * `complete`, `postpone`, `PATCH status → todo`, `reopen`, `pause` e da
 * higiene do tick; doc 00 §5.5 e doc 08).
 *
 * Para além de fechar a entrada (`ended_at` + `ended_reason`), acumula os
 * segundos em `tasks.tracked_seconds` (cache) e descarta entradas com menos
 * de 1 segundo — um clique acidental não deve criar tempo (doc 08 regra 7).
 *
 * O fecho é **guardado**: só fecha a entrada que ainda está aberta
 * (`whereNull('ended_at')`) e a acumulação é um incremento atómico em SQL.
 * Dois pedidos a chegar ao mesmo tempo — duplo clique em `pause`, `pause` a
 * correr ao mesmo tempo que `complete`, ou dois ticks do scheduler — não
 * podem contar o mesmo tempo duas vezes.
 */
class CloseOpenTimer
{
    public function __construct(private readonly TimerService $timers) {}

    /**
     * @return TaskTimeEntry|null a entrada fechada; `null` quando não havia
     *                            entrada aberta, quando ela foi descartada
     *                            por durar menos de 1 segundo ou quando outro
     *                            pedido a fechou primeiro.
     */
    public function handle(Task $task, string $reason): ?TaskTimeEntry
    {
        $entry = $this->timers->open($task);

        if (! $entry instanceof TaskTimeEntry) {
            return null;
        }

        $seconds = $this->timers->secondsOf($entry);

        // A relação em memória continuaria a apontar para a entrada fechada
        // (ou descartada), e o bloco `timer` mentiria no estado.
        $task->unsetRelation('activeTimeEntry');

        if ($seconds < 1) {
            $entry->delete();

            return null;
        }

        $endedAt = now();

        // Fecho guardado: `UPDATE ... WHERE ended_at IS NULL`. Se as linhas
        // afectadas forem 0, outro pedido já a fechou — nada a acumular.
        $closed = $entry->newQuery()
            ->whereKey($entry->getKey())
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $endedAt,
                'ended_reason' => $reason,
            ]);

        if ($closed === 0) {
            return null;
        }

        // Modelo coerente com a BD (quem chamou usa `$entry->seconds()` na
        // mensagem de actividade).
        $entry->forceFill(['ended_at' => $endedAt, 'ended_reason' => $reason]);
        $entry->syncOriginal();

        $this->accumulate($task, $seconds);

        return $entry;
    }

    /**
     * Soma os segundos ao cache da tarefa. Incremento em SQL (atómico, e sem
     * mexer em `updated_at` nem no dirty tracking: a ação chamadora atualiza
     * a tarefa a seguir, na mesma transação).
     */
    private function accumulate(Task $task, int $seconds): void
    {
        $task->newQuery()
            ->whereKey($task->getKey())
            ->update(['tracked_seconds' => DB::raw('tracked_seconds + '.$seconds)]);

        $total = (int) $task->tracked_seconds + $seconds;

        // Mantém o modelo coerente com a BD sem o marcar como alteração.
        $task->tracked_seconds = $total;
        $task->syncOriginalAttribute('tracked_seconds');
    }
}
