<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Enums\EstadoEnvio;

/*
 * AEAT response
 */
class ResponseAeat extends Response
{
    public string $request;
    public mixed $response;
    public ?string $rawResponse;
    public ?EstadoEnvio $status;
    public ?string $statusRaw;
    public bool $duplicate;
    public string $duplicateStatus;
}
