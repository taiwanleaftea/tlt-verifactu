<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Enums;

enum TaxRegimeIVA: string
{
    // Operación de régimen general.
    case GENERAL = '01';

    // Exportación.
    case EXPORT = '02';

    // Operaciones a las que se aplique el régimen especial de bienes usados, objetos de arte, antigüedades y objetos de colección.
    case USED_GOODS = '03';

    // Régimen especial del oro de inversión.
    case GOLD = '04';

    // Régimen especial de las agencias de viajes.
    case TRAVEL_SPECIAL = '05';

    // Régimen especial grupo de entidades en IVA (Nivel Avanzado)
    case HOLDING = '06';

    // Régimen especial del criterio de caja.
    case SPECIAL = '07';

    // Operaciones sujetas al IPSI/IGIC (Impuesto sobre la Producción, los Servicios y la Importación  / Impuesto General Indirecto Canario).
    case IGIC = '08';

    // Facturación de las prestaciones de servicios de agencias de viaje que actúan como mediadoras en nombre y por cuenta ajena (D.A.4ª RD1619/2012)
    case TRAVEL = '09';

    // Cobros por cuenta de terceros de honorarios profesionales o de derechos derivados de la propiedad industrial, de autor u otros por cuenta de sus socios,
    // asociados o colegiados efectuados por sociedades, asociaciones, colegios profesionales u otras entidades que realicen estas funciones de cobro.
    case THIRD_PARTY = '10';

    // Operaciones de arrendamiento de local de negocio.
    case RENT = '11';

    // Factura con IVA pendiente de devengo en certificaciones de obra cuyo destinatario sea una Administración Pública.
    case GOV = '14';

    // Factura con IVA pendiente de devengo en operaciones de tracto sucesivo.
    case TRACT_OP = '15';

    // Operación acogida a alguno de los regímenes previstos en el Capítulo XI del Título IX (OSS e IOSS)
    case CAP_XI = '17';

    // Recargo de equivalencia.
    case EQUIVALENCE_SURCHARGE = '18';

    // Operaciones de actividades incluidas en el Régimen Especial de Agricultura, Ganadería y Pesca (REAGYP)
    case REAGYP = '19';

    // Régimen simplificado
    case SIMPLIFIED = '20';
}
