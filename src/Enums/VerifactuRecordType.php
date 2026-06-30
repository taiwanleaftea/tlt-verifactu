<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

enum VerifactuRecordType: string
{
    use EnumValues;

    case ALTA = 'alta';
    case ANULACION = 'anulacion';
}
