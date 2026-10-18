<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Support\PtDate;
use Carbon\CarbonImmutable;

/**
 * Formatação pt-PT da mensagem de adiar (doc 07): "Adiada para {data}",
 * onde `{data}` é `d MMM yyyy` (ex.: "7 out 2026") e, havendo hora,
 * "… às HH:mm".
 *
 * A formatação da data/hora vive em `App\Support\PtDate` (fonte única
 * partilhada com as notificações).
 */
final class PostponeMessage
{
    public static function for(?string $date, ?string $time): string
    {
        if ($date === null || $date === '') {
            return 'Adiada';
        }

        $carbon = CarbonImmutable::parse($date);
        $formatted = PtDate::dayMonthYear($carbon);

        if ($time !== null && $time !== '') {
            $formatted .= ' às '.PtDate::time($time);
        }

        return 'Adiada para '.$formatted;
    }
}
