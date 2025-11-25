<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;

class InvoiceCancellation extends Invoice
{
    /**
     * @var Generator
     */
    protected Generator $generator;
    protected string $invoiceHash;

    public function __construct(
        LegalPerson $issuer,
        string $invoiceNumber,
        Carbon $invoiceDate,
        string $invoiceHash = '',
        Carbon $timestamp = null,
    )
    {
        $this->issuer = $issuer;
        $this->invoiceNumber = Str::trim($invoiceNumber);
        $this->invoiceDate = $invoiceDate;
        $this->invoiceHash = $invoiceHash;
        $this->timestamp = $timestamp ?? Carbon::now();
    }

    /**
     * Set generator data for invoice cancellation
     *
     * @param Generator $generator
     * @return void
     */
    public function setGenerator(Generator $generator): void
    {
        $this->generator = $generator;
    }

    /**
     * Invoice cancellation has generator
     *
     * @return bool
     */
    public function hasGenerator(): bool
    {
        return isset($this->generator);
    }

    /**
     * Get generator for invoice cancellation
     *
     * @return Generator
     * @throws GeneratorException
     */
    public function getGenerator(): Generator
    {
        if (!isset($this->generator)) {
            throw new GeneratorException('Recipient not found.');
        }

        return $this->generator;
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
