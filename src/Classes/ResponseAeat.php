<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Classes\Response;

class ResponseAeat extends Response
{
    /*
     * AEAT response
     */
    public string $request;
    public mixed $response;
    public ?string $rawResponse;
}
