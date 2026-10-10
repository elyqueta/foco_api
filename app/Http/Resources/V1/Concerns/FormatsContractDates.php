<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use DateTimeInterface;

/**
 * Serialização de datas do contrato da API: ISO 8601 em UTC no formato
 * `YYYY-MM-DDTHH:MM:SSZ` (igual ao `expires_at` do login) — as respostas
 * nunca expõem `+00:00` nem o fuso do servidor.
 */
trait FormatsContractDates
{
    protected function contractDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return (clone $value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        if (is_string($value)) {
            try {
                return (new \DateTimeImmutable($value))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
