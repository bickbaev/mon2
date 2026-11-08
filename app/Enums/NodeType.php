<?php

namespace App\Enums;

enum NodeType: string
{
    case Authority = 'authority';
    case Organization = 'organization';
    case Branch = 'branch';

    /** Допустимо ли узлу этого типа находиться под родителем данного типа (null — корень). */
    public function canSitUnder(?self $parent): bool
    {
        return match ($this) {
            self::Authority => $parent === null || $parent === self::Authority,
            self::Organization => $parent === self::Authority,
            self::Branch => $parent === self::Organization,
        };
    }
}
