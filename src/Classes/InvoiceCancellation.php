<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;

class InvoiceCancellation extends Invoice
{
    protected string $invoiceHash;

    public function __construct(
        LegalPerson $issuer,
        string $invoiceNumber,
        Carbon $invoiceDate,
        string $invoiceHash = ''
    )
    {
        $this->issuer = $issuer;
        $this->invoiceNumber = Str::trim($invoiceNumber);
        $this->invoiceDate = $invoiceDate;
        $this->invoiceHash = $invoiceHash;
    }

    public function hash(string $timestamp = null): string
    {
        $parts = [
            'IDEmisorFacturaAnulada=' . $this->issuer->id,
            'NumSerieFacturaAnulada=' . $this->invoiceNumber,
            'FechaExpedicionFacturaAnulada=' . $this->invoiceDate->format('d-m-Y'),
            'Huella=' . $this->invoiceHash,
            is_null($timestamp) ? 'FechaHoraHusoGenRegistro=' . Carbon::now()->toAtomString() : 'FechaHoraHusoGenRegistro=' . $timestamp,
        ];

        return Str::upper(hash('sha256', implode('&', $parts)));
    }
}
