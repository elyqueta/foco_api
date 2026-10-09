<?php

declare(strict_types=1);

namespace App\Enums;

enum TimerState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Inativo',
            self::Running => 'A correr',
            self::Paused => 'Pausado',
            self::Stopped => 'Parado',
        };
    }
}
