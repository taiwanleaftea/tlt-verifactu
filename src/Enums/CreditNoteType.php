<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Enumeration for credit note types (TipoRectificativa).
 * Field that identifies whether the type of corrective invoice is for substitution or for difference.
 */
enum CreditNoteType: string
{
    /**
     * Por sustitución
     */
    case SUBSTITUTION = 'S';

    /**
     * Por diferencias
     */
    case DIFFERENCE = 'I';
}
