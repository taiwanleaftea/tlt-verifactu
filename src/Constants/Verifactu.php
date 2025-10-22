<?php

namespace Taiwanleaftea\TltVerifactu\Constants;

class Verifactu
{
    /*
     * VERIFACTU Service
     */
    public const string URL_PRODUCTION = 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';
    public const string URL_SANDBOX = 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP';

    /*
     * QR Verification service
     */
    public const string QR_VERIFICATION_SANDBOX = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?';
    public const string QR_VERIFICATION_PRODUCTION = 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?';

    /*
     * XSD namespaces
     */
    // SuministroInformacion.xsd namespace
    public const string SF_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    // SuministroLR.xsd namespace
    public const string SFLR_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    // ConsultaLR.xsd namespace
    public const string QUERY_NAMESPACE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/ConsultaLR.xsd';

    // XML Digital Signature namespace
    public const string DS_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    // VERIFACTU version id
    public const string VERSION = '1.0';

    // Yes/No
    public const string YES = 'S';
    public const string NO = 'N';

    // Hash type
    public const string SHA_256 = '01';
}
