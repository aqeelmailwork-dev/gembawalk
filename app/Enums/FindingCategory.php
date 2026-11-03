<?php

namespace App\Enums;

enum FindingCategory: string
{
    case Safety = 'safety';
    case Quality = 'quality';
    case FiveS = 'five_s';
    case Waste = 'waste';
    case Process = 'process';
    case Other = 'other';

    /**
     * Human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Safety => 'Safety',
            self::Quality => 'Quality',
            self::FiveS => '5S',
            self::Waste => 'Waste',
            self::Process => 'Process',
            self::Other => 'Other',
        };
    }

    /**
     * Flux badge color for the category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Safety => 'red',
            self::Quality => 'blue',
            self::FiveS => 'violet',
            self::Waste => 'amber',
            self::Process => 'cyan',
            self::Other => 'zinc',
        };
    }

    /**
     * Heroicon name for the category.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Safety => 'shield-exclamation',
            self::Quality => 'check-badge',
            self::FiveS => 'sparkles',
            self::Waste => 'trash',
            self::Process => 'arrow-path',
            self::Other => 'ellipsis-horizontal-circle',
        };
    }

    /**
     * One-line description of the category.
     */
    public function description(): string
    {
        return match ($this) {
            self::Safety => 'Unsafe conditions, hazards, or near misses.',
            self::Quality => 'Defects or quality risks on the product or process.',
            self::FiveS => 'Sort, set in order, shine, standardize, and sustain issues.',
            self::Waste => 'Wasted motion, waiting, defects, or excess inventory.',
            self::Process => 'Broken or inefficient process steps.',
            self::Other => 'Any other observation worth recording.',
        };
    }
}
