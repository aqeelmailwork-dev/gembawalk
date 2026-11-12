<?php

namespace App\Enums;

enum YesNo: string
{
    case Yes = 'yes';
    case No = 'no';

    /**
     * Human-readable label for the answer.
     */
    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
        };
    }

    /**
     * Flux badge color for the answer.
     */
    public function color(): string
    {
        return match ($this) {
            self::Yes => 'green',
            self::No => 'red',
        };
    }
}
