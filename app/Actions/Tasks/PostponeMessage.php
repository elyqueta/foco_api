<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use Carbon\CarbonImmutable;

/**
 * Formatação pt-PT da mensagem de adiar (doc 07): "Adiada para {data}",
 * onde `{data}` é `d MMM yyyy` (ex.: "7 out 2026") e, havendo hora,
 * "… às HH:mm".
 *
 * Os meses são abreviados explicitamente (não via `Intl`) para não depender
 * do catálogo de localização `pt_PT` do ICU — o resultado é determinista.
 */
final class PostponeMessage
{
    /**
     * Meses abreviados em português (jan..dez).
     *
     * @var list<string>
     */
    private const MONTHS = [
        'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
        'jul', 'ago', 'set', 'out', 'nov', 'dez',
    ];

    public static function for(?string $date, ?string $time): string
    {
        if ($date === null || $date === '') {
            return 'Adiada';
        }

        $carbon = CarbonImmutable::parse($date);
        $formatted = $carbon->day.' '.self::MONTHS[$carbon->month - 1].' '.$carbon->year;

        if ($time !== null && $time !== '') {
            $formatted .= ' às '.substr($time, 0, 5);
        }

        return 'Adiada para '.$formatted;
    }
}
