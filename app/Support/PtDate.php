<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formatação de datas e horas em português (pt-PT) usada nas mensagens da
 * API: notificações, atividade e emails.
 *
 * Os meses são abreviados explicitamente (não via `Intl`) para não depender
 * do catálogo de localização `pt_PT` do ICU — o resultado é determinista em
 * qualquer ambiente (igual ao `PostponeMessage` da Fase 7, que passou a usar
 * esta classe como fonte única).
 */
final class PtDate
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

    /**
     * Data no formato `d MMM yyyy` (ex.: "17 out 2026").
     */
    public static function dayMonthYear(DateTimeInterface $date): string
    {
        return $date->format('j').' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    /**
     * Hora no formato `HH:mm` (aceita `DateTimeInterface` ou `HH:MM[:SS]`).
     */
    public static function time(DateTimeInterface|string|null $time): string
    {
        if ($time === null || $time === '') {
            return '';
        }

        if ($time instanceof DateTimeInterface) {
            return $time->format('H:i');
        }

        return substr($time, 0, 5);
    }

    /**
     * Rótulo relativo de um dia: "hoje", "amanhã" ou a data formatada.
     * A comparação é feita pela parte da data, no mesmo fuso de `$today`
     * (fuso do utilizador).
     */
    public static function dayLabel(CarbonImmutable $date, CarbonImmutable $today): string
    {
        $day = $date->format('Y-m-d');

        if ($day === $today->format('Y-m-d')) {
            return 'hoje';
        }

        if ($day === $today->addDay()->format('Y-m-d')) {
            return 'amanhã';
        }

        return self::dayMonthYear($date);
    }
}
