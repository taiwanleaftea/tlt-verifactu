<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

enum VerifactuRecordType: string
{
    case ALTA = 'alta';
    case ANULACION = 'anulacion';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases()
        );
    }
}
