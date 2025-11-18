<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;

/*
 * AEAT response
 */
class ResponseAeat extends Response
{
    public ?string $request = null;
    public mixed $response;
    public ?string $rawResponse = null;
    public ?EstadoRegistro $status = null;
    public ?string $statusRaw = null;
    public ?int $aeatErrorCode = null;
    public bool $duplicate;
    public string $duplicateStatus;
    public ?string $hash = null;
    public ?string $qrSVG = null;
    public string $json = '';
}
