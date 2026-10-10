<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

class UserClock
{
    /**
     * Start of the current day in the user's timezone.
     */
    public function today(User $user): CarbonImmutable
    {
        $tz = $this->timezone($user);

        return CarbonImmutable::now($tz)->startOfDay();
    }

    /**
     * Current instant in the user's timezone.
     */
    public function now(User $user): CarbonImmutable
    {
        $tz = $this->timezone($user);

        return CarbonImmutable::now($tz);
    }

    /**
     * Convert a date + optional time (local) to UTC.
     */
    public function toUtc(string $date, ?string $time, User $user): CarbonImmutable
    {
        $tz = $this->timezone($user);

        if ($time === null || $time === '') {
            return CarbonImmutable::parse($date, $tz)->startOfDay()->utc();
        }

        return CarbonImmutable::parse("{$date} {$time}", $tz)->utc();
    }

    /**
     * Fuso do utilizador (default UTC, com validação do identificador).
     */
    public function timezone(User $user): string
    {
        $tz = $user->timezone ?? 'UTC';

        if (! is_string($tz) || $tz === '') {
            return 'UTC';
        }

        if (! in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return 'UTC';
        }

        return $tz;
    }
}
