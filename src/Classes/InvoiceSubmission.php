<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\ExemptOperationType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\TaxRegimeIVA;
use Taiwanleaftea\TltVerifactu\Enums\TaxType;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;

class InvoiceSubmission extends Invoice
{
    public InvoiceType $type;
    public string $description;
    public float $taxRate;
    protected float $taxableBase;
    protected float $taxAmount;
    protected float $totalAmount;
    protected Recipient $recipient;
    protected TaxType $taxType;
    protected TaxRegimeIVA $taxRegimeIVA;
    protected OperationQualificationType $operationQualification;
    public ExemptOperationType $exemptOperation;

    public function __construct(
        LegalPerson $issuer,
        string $invoiceNumber,
        Carbon $invoiceDate,
        string $description,
        InvoiceType $type,
        float $taxRate,
        float $taxableBase,
        float $taxAmount,
        float $totalAmount,
        Carbon $timestamp = null,
    )
    {
        $this->issuer = $issuer;
        $this->invoiceNumber = Str::trim($invoiceNumber);
        $this->invoiceDate = $invoiceDate;
        $this->description = Str::trim($description);
        $this->type = $type;
        $this->taxRate = $taxRate;
        $this->taxableBase = $taxableBase;
        $this->taxAmount = $taxAmount;
        $this->totalAmount = $totalAmount;
        $this->timestamp = $timestamp ?? Carbon::now();
    }

    /**
     * Get total amount in normalized format
     *
     * @return string
     */
    public function getTotalAmount(): string
    {
        return $this->normalizeDecimal($this->totalAmount);
    }

    /**
     * Get tax amount in normalized format
     *
     * @return string
     */
    public function getTaxAmount(): string
    {
        return $this->normalizeDecimal($this->taxAmount);
    }

    /**
     * Get tax rate in normalized format
     *
     * @return string
     */
    public function getTaxRate(): string
    {
        return $this->normalizeDecimal($this->taxRate);
    }

    /**
     * Get taxable base in normalized format
     *
     * @return string
     */
    public function getTaxableBase(): string
    {
        return $this->normalizeDecimal($this->taxableBase);
    }

    /**
     * Set recipient data for invoice
     *
     * @param Recipient $recipient
     * @return void
     */
    public function setRecipient(Recipient $recipient): void
    {
        $this->recipient = $recipient;
    }

    /**
     * Get recipient for invoice
     * @return Recipient
     * @throws RecipientException
     */
    public function getRecipient(): Recipient
    {
        if (!isset($this->recipient)) {
            throw new RecipientException('Recipient not found.');
        }

        return $this->recipient;
    }

    /**
     * Set tax type
     *
     * @param TaxType $taxType
     * @return void
     */
    public function setTaxType(TaxType $taxType): void
    {
        $this->taxType = $taxType;
    }

    /**
     * Get tax type value or type IVA by default
     *
     * @return string
     */
    public function getTaxType(): string
    {
        return isset($this->taxType) ? $this->taxType->value : TaxType::IVA->value;
    }

    /**
     * Set tax regime IVA
     *
     * @param TaxRegimeIVA $taxRegime
     * @return void
     */
    public function setTaxRegimeIVA(TaxRegimeIVA $taxRegime): void
    {
        $this->taxRegimeIVA = $taxRegime;
    }

    /**
     * Get tax regime value or type General by default
     *
     * @return string
     */
    public function getTaxRegimeIVA(): string
    {
        return isset($this->taxRegimeIVA) ? $this->taxRegimeIVA->value : TaxRegimeIVA::GENERAL->value;
    }

    /**
     * Set operation qualification
     *
     * @param OperationQualificationType $operationQualification
     * @return void
     */
    public function setOperationQualification(OperationQualificationType $operationQualification): void
    {
        $this->operationQualification = $operationQualification;
    }

    /**
     * Get operation qualification
     *
     * @return string
     * @throws InvoiceValidationException
     */
    public function getOperationQualification(): string
    {
        if ($this->type == InvoiceType::SIMPLIFIED) {
            return OperationQualificationType::SUBJECT_DIRECT->value;
        }

        if (!isset($this->operationQualification) && !isset($this->recipient)) {
            throw new InvoiceValidationException('Recipient must be set.');
        }

        if (!isset($this->operationQualification)) {
            throw new InvoiceValidationException('Operation qualification must be set for normal invoice.');
        }

        return $this->operationQualification->value;
    }

    public function hash(string $timestamp = null): string
    {
        $parts = [
            'IDEmisorFactura=' . $this->issuer->id,
            'NumSerieFactura=' . $this->invoiceNumber,
            'FechaExpedicionFactura=' . $this->invoiceDate->format('d-m-Y'),
            'TipoFactura=' . $this->type->value,
            'CuotaTotal=' . $this->normalizeDecimal($this->taxAmount),
            'ImporteTotal=' . $this->normalizeDecimal($this->totalAmount),
            'Huella=' . $this->previousHash,
            is_null($timestamp) ? 'FechaHoraHusoGenRegistro=' . Carbon::now()->toAtomString() : 'FechaHoraHusoGenRegistro=' . $timestamp,
        ];

        return Str::upper(hash('sha256', implode('&', $parts)));
    }

    /**
     * Check for Standard (F2) invoice type
     *
     * @return bool
     */
    public function isSimplified(): bool
    {
        return $this->type === InvoiceType::SIMPLIFIED;
    }
}
