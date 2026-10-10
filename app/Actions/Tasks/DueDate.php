<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Exceptions\DomainRuleException;

/**
 * Parser/serializer do prazo de uma tarefa (doc 07 §7.2).
 *
 * O contrato aceita `YYYY-MM-DD` (só data) ou `YYYY-MM-DDTHH:mm` (data e
 * hora local) e devolve **no mesmo formato** (G-10). Na base de dados o
 * prazo vive em duas colunas — `due_date` (DATE) e `due_time` (TIME) — e
 * esta classe é a fonte única da conversão entre os dois mundos.
 *
 * `parse()` valida o calendário (rejeita "2026-02-30" e "2026-13-01") para
 * que uma data inválida nunca chegue a uma coluna `date` no PostgreSQL.
 */
final class DueDate
{
    /**
     * Converte o prazo recebido em `{ date, time }`:
     *  - `null`/vazio → `{ null, null }`;
     *  - `2026-10-05` → `{ "2026-10-05", null }`;
     *  - `2026-10-05T14:30` → `{ "2026-10-05", "14:30:00" }`;
     *  - hora sem data (`14:30`, `T14:30`) → erro (o contrato exige a data).
     *
     * Aceita separador `T` ou espaço e segundos opcionais na hora.
     *
     * @return array{date: string|null, time: string|null}
     */
    public static function parse(mixed $value): array
    {
        if ($value instanceof \DateTimeInterface) {
            return ['date' => $value->format('Y-m-d'), 'time' => null];
        }

        if (! is_string($value) || trim($value) === '') {
            return ['date' => null, 'time' => null];
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            self::assertRealDate((int) $m[1], (int) $m[2], (int) $m[3]);

            return ['date' => $value, 'time' => null];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m) === 1) {
            self::assertRealDate((int) $m[1], (int) $m[2], (int) $m[3]);
            self::assertRealTime((int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));

            return [
                'date' => $m[1].'-'.$m[2].'-'.$m[3],
                'time' => $m[4].':'.$m[5].':'.($m[6] ?? '00'),
            ];
        }

        throw self::invalid();
    }

    /**
     * Serializa `due_date` + `due_time` de volta ao formato do contrato:
     * com hora → `YYYY-MM-DDTHH:mm`, sem hora → `YYYY-MM-DD`, sem data → null.
     */
    public static function format(mixed $date, mixed $time): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $dateStr = $date instanceof \DateTimeInterface
            ? $date->format('Y-m-d')
            : substr(trim((string) $date), 0, 10);

        $timeStr = ($time === null || $time === '') ? null : substr(trim((string) $time), 0, 5);

        return $timeStr !== null ? $dateStr.'T'.$timeStr : $dateStr;
    }

    /**
     * Rejeita datas de calendário inexistentes antes de tocar na coluna.
     */
    private static function assertRealDate(int $year, int $month, int $day): void
    {
        if (! checkdate($month, $day, $year)) {
            throw self::invalid();
        }
    }

    /**
     * Rejeita horas fora de um dia (ex. "25:00") — a coluna `time` do
     * PostgreSQL rejeitaria o valor em tempo de escrita.
     */
    private static function assertRealTime(int $hour, int $minute, int $second): void
    {
        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw self::invalid();
        }
    }

    private static function invalid(): DomainRuleException
    {
        return DomainRuleException::withErrors(
            'INVALID_DUE_DATE',
            'A data de prazo não é válida.',
            ['dueDate' => ['A data de prazo não é válida.']],
        );
    }
}
