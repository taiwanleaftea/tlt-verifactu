<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;

class InvoiceCancellation extends Invoice
{
    protected Generator $generator;

    public function __construct(
        LegalPerson $issuer,
        string $invoiceNumber,
        Carbon $invoiceDate,
        string $invoiceHash = '',
        ?Carbon $timestamp = null,
    ) {
        $this->issuer = $issuer;
        $this->invoiceNumber = Str::trim($invoiceNumber);
        $this->invoiceDate = $invoiceDate;
        $this->previousHash = $invoiceHash;
        $this->timestamp = $timestamp ?? Carbon::now();
    }

    /**
     * Set generator data for invoice cancellation
     */
    public function setGenerator(Generator $generator): void
    {
        $this->generator = $generator;
    }

    /**
     * Invoice cancellation has generator
     */
    public function hasGenerator(): bool
    {
        return isset($this->generator);
    }

    /**
     * Get generator for invoice cancellation
     *
     * @throws GeneratorException
     */
    public function getGenerator(): Generator
    {
        if (! isset($this->generator)) {
            throw new GeneratorException('Recipient not found.');
        }

        return $this->generator;
    }

    /**
     * Hash generator for cancellation
     */
    public function hash(?string $timestamp = null): string
    {
        $parts = [
            'IDEmisorFacturaAnulada='.$this->issuer->id,
            'NumSerieFacturaAnulada='.$this->invoiceNumber,
            'FechaExpedicionFacturaAnulada='.$this->invoiceDate->format('d-m-Y'),
            'Huella='.$this->previousHash,
            is_null($timestamp) ? 'FechaHoraHusoGenRegistro='.$this->getTimestamp() : 'FechaHoraHusoGenRegistro='.$timestamp,
        ];

        $source = implode('&', $parts);

        if ($this->hashSource !== $source) {
            $this->hashSource = $source;
            $this->hash = Str::upper(hash('sha256', $source));
        }

        return $this->hash;
    }
}
