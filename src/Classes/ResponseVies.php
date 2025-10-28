<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Classes\Response;

class ResponseVies extends Response
{
    /*
     * VAT validation response
     */
    public string $vatNumber;
    public string $countryCode;
    public bool $valid;
    public string $requestDate;
    public string $name;
    public string $address;
}
