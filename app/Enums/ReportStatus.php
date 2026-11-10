<?php

namespace App\Enums;

enum ReportStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Rework = 'rework';
    case Blocked = 'blocked';
    case Accepted = 'accepted';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::New => [self::InProgress, self::Blocked],
            self::InProgress => [self::Rework, self::Accepted, self::Blocked],
            self::Rework => [self::InProgress, self::Blocked],
            self::Accepted => [self::InProgress, self::Rework, self::Blocked],
            self::Blocked => [self::InProgress, self::Rework],
        }, true);
    }

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
