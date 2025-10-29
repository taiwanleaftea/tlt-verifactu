<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

/*
 * VAT validation response
 */
class ResponseVies extends Response
{
    public string $vatNumber;
    public string $countryCode;
    public bool $valid;
    public string $requestDate;
    public string $name;
    public string $address;
}
