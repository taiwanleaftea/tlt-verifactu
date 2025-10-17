<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use stdClass;

class Response extends stdClass
{
    public bool $success;
    public array $errors = [];

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
