<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;

abstract class Invoice
{
    public LegalPerson $issuer;
    public string $invoiceNumber;
    public string $externalReference;
    public Carbon $invoiceDate;
    public Carbon $timestamp;
    protected ?string $hash;

    // Previous invoice
    protected bool $firstInvoice = true;
    public string $previousNumber;
    public Carbon $previousDate;
    public ?string $previousHash = '';

    // Additional options
    protected array $options = [];
    protected array $optionsKeys = [];

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
     * @param string $number
     * @param Carbon $date
     * @param string $hash
     * @return void
     */
    public function setPreviousInvoice(
        string $number,
        Carbon $date,
        string $hash
    ): void
    {
        $this->firstInvoice = false;
        $this->previousNumber = $number;
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
        if ($this->firstInvoice) {
            throw new InvoiceValidationException('This is the first invoice.');
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

    /**
     * Set/add options
     *
     * @param array $options
     * @param bool $reset
     * @throws InvoiceValidationException
     */
    public function setOptions(array $options, bool $reset = false): void
    {
        $keys = $this->optionsKeys;

        foreach ($options as $option => $value) {
            if (!in_array($option, $keys)) {
                throw new InvoiceValidationException("Option $option does not allowed here.");
            }

            if ($option === 'rectificado' && !isset($value['invoice_number'], $value['invoice_date'], $value['simplified'])) {
                throw new InvoiceValidationException(Str::ucfirst($option) . " must contain 'invoice_number', 'invoice_date' and 'simplified'.");
            }
        }

        if ($reset) {
            $this->options = [];
        }

        foreach ($keys as $key) {
            if (isset($options[$key])) {
                $this->options[$key] = $options[$key];
            }
        }
    }

    /**
     * Get option value or null
     *
     * @param string $key
     * @return mixed
     */
    public function getOption(string $key): mixed
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Convert float to AEAT formatted string
     *
     * @param float $value
     * @return string
     */
    protected function normalizeDecimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
