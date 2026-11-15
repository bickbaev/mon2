<?php

namespace App\Enums;

enum Periodicity: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Еженедельно',
            self::Monthly => 'Ежемесячно',
            self::Yearly => 'Ежегодно',
        };
    }
}
