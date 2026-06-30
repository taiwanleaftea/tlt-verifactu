<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

enum VerifactuRecordVariant: string
{
    use EnumValues;

    case STANDARD = 'standard';
    case SUBSANACION = 'subsanacion';
    case RECTIFICATIVA = 'rectificativa';
}
