<?php

namespace App\Enums;

enum ActionStatus: string
{
    case Open = 'open';
    case Done = 'done';

    /**
     * Human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
        };
    }

    /**
     * Flux badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Done => 'green',
        };
    }
}
