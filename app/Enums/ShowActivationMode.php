<?php

namespace App\Enums;

enum ShowActivationMode: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Scheduled => 'Scheduled',
        };
    }
}
