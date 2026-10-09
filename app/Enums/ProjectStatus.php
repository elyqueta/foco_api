<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Done => 'Concluído',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Done;
    }
}
