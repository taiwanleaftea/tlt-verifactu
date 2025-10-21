<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;

abstract class Invoice
{
    public string $issuerNif;
    public string $issuerName;
    public string $invoiceNumber;
    public string $externalReference;
    public Carbon $invoiceDate;
    public Carbon $timestamp;

    // Previous invoice
    protected bool $firstInvoice = true;
    public string $previousNumber;
    public Carbon $previousDate;
    public ?string $previousHash = '';

    /**
     * Generate hash in AEAT format
     *
     * @param string|null $timestamp
     * @return string
     */
    abstract public function hash(string $timestamp = null): string;

    /**
     * Get invoice date in required format
     *
     * @param bool $previous
     * @return string
     */
    public function getDate(bool $previous = false): string
    {
        return $previous ? $this->previousDate->format('d-m-y') : $this->invoiceDate->format('d-m-Y');
    }

    /**
     * Get timestamp in required format
     *
     * @return string
     */
    public function getTimestamp(): string
    {
        return $this->timestamp->toAtomString();
    }

    /**
     * Set previous invoice data
     *
     * @param string $invoiceNumber
     * @param Carbon $date
     * @param string $hash
     * @return void
     */
    public function setPreviousInvoice(
        string $invoiceNumber,
        Carbon $date,
        string $hash
    ): void
    {
        $this->firstInvoice = false;
        $this->previousNumber = $invoiceNumber;
        $this->previousDate = $date;
        $this->previousHash = $hash;
    }

    /**
     * Get previous invoice data
     *
     * @return array
     * @throws InvoiceValidationException
     */
    public function getPreviousInvoice(): array
    {
        if (!$this->firstInvoice) {
            throw new InvoiceValidationException('Previous invoice not found.');
        } else {
            return [
                'number' => $this->previousNumber,
                'date' => $this->previousDate->format('d-m-Y'),
                'hash' => $this->previousHash,
            ];
        }
    }

    /**
     * Check if the first invoice
     *
     * @return bool
     */
    public function isFirstInvoice(): bool
    {
        return $this->firstInvoice;
    }

    protected function normalizeDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
