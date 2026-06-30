<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for RechazoPrevioAnulacionType.
 */
enum CancellationRejectionStatus: string
{
    use EnumValues;

    case YES = 'S';

    case NO = 'N';
}
