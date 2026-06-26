<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\CancellationRejectionStatus;
use Taiwanleaftea\TltVerifactu\Enums\PreviousRecordStatus;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;

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
        $this->optionsKeys = ['sin_registro_previo', 'rechazo_previo'];
    }

    /**
     * Set/add options
     *
     * @throws InvoiceValidationException
     */
    public function setOptions(array $options, bool $reset = false): void
    {
        if (array_key_exists('sin_registro_previo', $options)) {
            if ($options['sin_registro_previo'] instanceof PreviousRecordStatus) {
                $previousRecordStatus = $options['sin_registro_previo'];
            } elseif (! is_string($options['sin_registro_previo'])) {
                throw new InvoiceValidationException('Sin registro previo must be a valid PreviousRecordStatus value.');
            } else {
                $previousRecordStatus = PreviousRecordStatus::tryFrom($options['sin_registro_previo']);
            }

            if ($previousRecordStatus === null) {
                throw new InvoiceValidationException('Sin registro previo must be a valid PreviousRecordStatus value.');
            }

            $options['sin_registro_previo'] = $previousRecordStatus->value;
        }

        if (array_key_exists('rechazo_previo', $options)) {
            if ($options['rechazo_previo'] instanceof CancellationRejectionStatus) {
                $rejectionStatus = $options['rechazo_previo'];
            } elseif (! is_string($options['rechazo_previo'])) {
                throw new InvoiceValidationException('Rechazo previo must be a valid CancellationRejectionStatus value.');
            } else {
                $rejectionStatus = CancellationRejectionStatus::tryFrom($options['rechazo_previo']);
            }

            if ($rejectionStatus === null) {
                throw new InvoiceValidationException('Rechazo previo must be a valid CancellationRejectionStatus value.');
            }

            $options['rechazo_previo'] = $rejectionStatus->value;
        }

        parent::setOptions($options, $reset);
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
