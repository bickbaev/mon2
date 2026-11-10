<?php

namespace App\Enums;

enum ReportStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Rework = 'rework';
    case Blocked = 'blocked';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новый',
            self::InProgress => 'В работе',
            self::Rework => 'Доработать',
            self::Blocked => 'Заблокирован',
            self::Accepted => 'Принят',
        };
    }
}
