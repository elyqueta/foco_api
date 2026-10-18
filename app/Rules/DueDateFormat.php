<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\InvokableRule;

/**
 * Valida o prazo de uma tarefa (`YYYY-MM-DD` ou `YYYY-MM-DDTHH:mm`, também
 * com espaço e segundos opcionais), a fonte única usada pelo request de
 * criação/atualização e pelo de adiar. Assim `POST/PATCH /tasks` e
 * `POST /tasks/{id}/postpone` aceitam exactamente os mesmos formatos e
 * devolvem as mesmas mensagens (evita divergência entre endpoints).
 *
 * Exige data de calendário real (o PostgreSQL rejeitaria "2026-02-30" na
 * coluna `date`) e valida os intervalos da hora quando presente (o `TIME`
 * rejeitaria "25:00"). `DueDate::parse` mantém-se como rede de segurança em
 * tempo de escrita.
 */
class DueDateFormat implements InvokableRule
{
    public function __invoke(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m) !== 1) {
            $fail('A data de prazo não é válida.');

            return;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $fail('A data de prazo não é válida.');

            return;
        }

        if (isset($m[4])) {
            $hour = (int) $m[4];
            $minute = (int) $m[5];
            $second = (int) ($m[6] ?? 0);

            if ($hour > 23 || $minute > 59 || $second > 59) {
                $fail('A hora do prazo não é válida.');
            }
        }
    }
}
