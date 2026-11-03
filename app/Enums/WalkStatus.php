<?php

namespace App\Enums;

enum WalkStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';

    /**
     * Human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Completed => 'Completed',
        };
    }

    /**
     * Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Completed => 'green',
        };
    }
}
