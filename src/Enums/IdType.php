<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

/**
 * Tipo de identificación, en el país de residencia.
 */
enum IdType: string
{
    /**
     * NIF-IVA
     */
    case NIF = '02';

    /**
     * Pasaporte
     */
    case PASSPORT = '03';

    /**
     * Documento oficial de identificación expedido por el país o territorio de residencia
     */
    case NATIONAL_ID = '04';

    /**
     * Certificado de residencia
     */
    case RESIDENCE_PERMIT = '05';

    /**
     * Otro documento probatorio
     */
    case OTHER = '06';

    /**
     * No censado
     */
    case NOT_REGISTERED = '07';
}
