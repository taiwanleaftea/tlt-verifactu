<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for invoice types (ClaveTipoFacturaType).
 */
enum InvoiceType: string
{
    /**
     * Factura (art. 6, 7.2 y 7.3 del RD 1619/2012)
     */
    case STANDARD = 'F1';

    /**
     * Factura Simplificada y Facturas sin identificación del destinatario art. 6.1.d) RD 1619/2012
     */
    case SIMPLIFIED = 'F2';

    /**
     * Factura emitida en sustitución de facturas simplificadas facturadas y declaradas
     */
    case REPLACEMENT = 'F3';

    /**
     * Factura Rectificativa (Error fundado en derecho y Art. 80 Uno Dos y Seis LIVA)
     */
    case RECTIFICATION_1 = 'R1';

    /**
     * Factura Rectificativa (Art. 80.3)
     */
    case RECTIFICATION_2 = 'R2';

    /**
     * Factura Rectificativa (Art. 80.4)
     */
    case RECTIFICATION_3 = 'R3';

    /**
     * Factura Rectificativa (Resto)
     */
    case RECTIFICATION_4 = 'R4';

    /**
     * Factura Rectificativa en facturas simplificadas
     */
    case RECTIFICATION_SIMPLIFIED = 'R5';
}
