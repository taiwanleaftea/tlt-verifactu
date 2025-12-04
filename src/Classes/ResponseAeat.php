<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;

/*
 * AEAT response
 */
class ResponseAeat extends Response
{
    public ?string $request = null;
    public ?Carbon $timestamp = null;
    public mixed $response;
    public ?string $rawResponse = null;
    public ?EstadoRegistro $status = null;
    public ?string $statusRaw = null;
    public ?int $aeatErrorCode = null;
    public bool $duplicate;
    public string $duplicateStatus;
    public ?string $hash = null;
    public ?string $qrSVG = null;
    public ?string $qrURI = null;
    public string $json = '';
}
