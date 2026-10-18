<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Postponed = 'postponed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'A fazer',
            self::InProgress => 'Em curso',
            self::Done => 'Concluída',
            self::Postponed => 'Adiada',
            self::Expired => 'Expirada',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Todo => true,
            self::InProgress => true,
            self::Postponed => true,
            self::Done => false,
            self::Expired => false,
        };
    }

    public function isCompleted(): bool
    {
        return $this === self::Done;
    }
}
