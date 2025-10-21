<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for exempt operations (OperacionExentaType).
 */
enum ExemptOperationType: string
{
    // Exenta por el artículo 20
    case E1 = 'E1';

    // Exenta por el artículo 21
    case E2 = 'E2';

    // Exenta por el artículo 22
    case E3 = 'E3';

    // Exenta por los artículos 23 y 24
    case E4 = 'E4';

    // Exenta por el artículo 25
    case E5 = 'E5';

    // Exenta por otros
    case E6 = 'E6';
}
