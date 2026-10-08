<?php

namespace Taiwanleaftea\TltVerifactu\Constants;

class VIES
{
    /*
     * EU VIES Service
     */
    public const string EU_VAT_API_URL = 'https://ec.europa.eu';

    public const string EU_VAT_WSDL_ENDPOINT = '/taxation_customs/vies/services/checkVatService.wsdl';

    public const string EU_VAT_SERVICE_ENDPOINT = '/taxation_customs/vies/services/checkVatService';

    public const array MEMBERS = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PO', 'PT', 'RO', 'SE', 'SI', 'SK'];
}
