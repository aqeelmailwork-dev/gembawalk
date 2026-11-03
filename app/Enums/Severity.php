<?php

namespace App\Enums;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Human-readable label for the severity.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /**
     * Flux badge color for the severity.
     */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'green',
            self::Medium => 'amber',
            self::High => 'red',
        };
    }

    /**
     * Numeric weight used for sorting and prioritisation.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
