<?php

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Models\VerifactuRecord;

/*
 * AEAT response
 */
class ResponseAeat extends Response
{
    public ?string $request = null;

    public ?string $signedRequest = null;

    public ?Carbon $timestamp = null;

    public mixed $response = null;

    public ?string $rawResponse = null;

    public ?string $csv = null;

    public ?EstadoRegistro $status = null;

    public ?string $statusRaw = null;

    public ?int $aeatErrorCode = null;

    public bool $duplicate = false;

    public string $duplicateStatus = '';

    public ?string $hash = null;

    public ?string $qrSVG = null;

    public ?string $qrURI = null;

    public string $json = '';

    public ?int $registryRecordId = null;

    public ?VerifactuRecord $registryRecord = null;

    public bool $storedOnly = false;
}
