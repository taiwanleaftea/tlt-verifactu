<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Indicador que especifica quién se ha encargado de generar materialmente el registro de facturación de anulación.
 */
enum GeneratorType: string
{
    /**
     * Expedidor (obligado a Expedir la factura anulada).
     */
    case ISSUER = 'E';

    /**
     * Destinatario
     */
    case RECIPIENT = 'D';

    /**
     * Tercero
     */
    case THIRD_PARTY = 'T';
}
