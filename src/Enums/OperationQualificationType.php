<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

enum OperationQualificationType: string
{
    // Operación Sujeta y No exenta - Sin inversión del sujeto pasivo.
    // Spanish companies and any private persons, incl. simplified
    case SUBJECT_DIRECT = 'S1';

    // Operación Sujeta y No exenta - Con Inversión del sujeto pasivo
    // Companies from EU
    case SUBJECT_REVERSE = 'S2';

    // Operación No Sujeta artículo 7, 14, otros.
    // I.e. selling of business
    case NOT_SUBJECT_ARTICLE = 'N1';

    // Operación No Sujeta por Reglas de localización.
    // Companies outside of EU, and from Canarias, Ceuta, Melilla
    case NOT_SUBJECT_LOCALIZATION = 'N2';

}
