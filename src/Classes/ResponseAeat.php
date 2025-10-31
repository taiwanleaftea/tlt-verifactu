<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;

/*
 * AEAT response
 */
class ResponseAeat extends Response
{
    public string $request;
    public mixed $response;
    public ?string $rawResponse;
    public ?EstadoRegistro $status = null;
    public ?string $statusRaw;
    public ?int $aeatErrorCode;
    public bool $duplicate;
    public string $duplicateStatus;
    public ?string $hash = null;
    public ?string $qrSVG = null;
    public string $json = '';
}
