<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for SinRegistroPrevioType.
 */
enum PreviousRecordStatus: string
{
    use EnumValues;

    case YES = 'S';

    case NO = 'N';
}
