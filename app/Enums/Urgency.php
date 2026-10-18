<?php

declare(strict_types=1);

namespace App\Enums;

enum Urgency: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function order(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Crítica',
            self::High => 'Alta',
            self::Medium => 'Média',
            self::Low => 'Baixa',
        };
    }

    public static function fromOrder(int $order): self
    {
        return match ($order) {
            0 => self::Critical,
            1 => self::High,
            2 => self::Medium,
            3 => self::Low,
            default => self::Medium,
        };
    }
}
