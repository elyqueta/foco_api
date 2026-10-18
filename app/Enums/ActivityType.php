<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Note = 'note';
    case Postponed = 'postponed';
    case Edited = 'edited';
    case NextStepChanged = 'next_step_changed';
    case Expired = 'expired';
    case TimerStarted = 'timer_started';
    case TimerPaused = 'timer_paused';
    case TimerResumed = 'timer_resumed';
    case TimerStopped = 'timer_stopped';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Criada',
            self::StatusChanged => 'Estado alterado',
            self::Note => 'Nota',
            self::Postponed => 'Adiada',
            self::Edited => 'Editada',
            self::NextStepChanged => 'Próximo passo atualizado',
            self::Expired => 'Expirada',
            self::TimerStarted => 'Timer iniciado',
            self::TimerPaused => 'Timer pausado',
            self::TimerResumed => 'Timer retomado',
            self::TimerStopped => 'Timer parado',
        };
    }
}
