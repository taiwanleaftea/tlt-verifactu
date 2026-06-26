<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for RechazoPrevioType.
 */
enum RejectionStatus: string
{
    /**
     * No ha habido rechazo previo por la AEAT.
     */
    case NO = 'N';

    /**
     * Ha habido rechazo previo por la AEAT.
     */
    case YES = 'S';

    /**
     * El registro de facturación no existe en la AEAT.
     */
    case NOT_IN_AEAT = 'X';
}
