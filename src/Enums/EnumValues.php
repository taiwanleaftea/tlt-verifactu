<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

use BackedEnum;

trait EnumValues
{
    /**
     * @return list<int|string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): int|string => $case instanceof BackedEnum ? $case->value : $case->name,
            self::cases()
        );
    }
}
