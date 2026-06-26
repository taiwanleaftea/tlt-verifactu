<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\ExemptOperationType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\RejectionStatus;
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

    public float $totalAmount;

    protected Recipient $recipient;

    protected TaxType $taxType;

    protected TaxRegimeIVA $taxRegimeIVA;

    protected OperationQualificationType $operationQualification;

    public ?ExemptOperationType $exemptOperation = null;

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
        Carbon $timestamp
    ) {
        $this->issuer = $issuer;
        $this->invoiceNumber = Str::trim($invoiceNumber);
        $this->invoiceDate = $invoiceDate;
        $this->description = Str::trim($description);
        $this->type = $type;
        $this->taxRate = $taxRate;
        $this->taxableBase = $taxableBase;
        $this->taxAmount = $taxAmount;
        $this->totalAmount = $totalAmount;
        $this->timestamp = $timestamp;

        $this->optionsKeys = ['subsanacion', 'rechazo_previo', 'rectificado', 'exempt_operation'];
    }

    /**
     * Get total amount in normalized format
     */
    public function getTotalAmount(): string
    {
        return $this->normalizeDecimal($this->totalAmount);
    }

    /**
     * Get tax amount in normalized format
     */
    public function getTaxAmount(): string
    {
        return $this->normalizeDecimal($this->taxAmount);
    }

    /**
     * Get tax rate in normalized format
     */
    public function getTaxRate(): string
    {
        return $this->normalizeDecimal($this->taxRate);
    }

    /**
     * Get taxable base in normalized format
     */
    public function getTaxableBase(): string
    {
        return $this->normalizeDecimal($this->taxableBase);
    }

    /**
     * Set recipient data for invoice
     */
    public function setRecipient(Recipient $recipient): void
    {
        $this->recipient = $recipient;
    }

    /**
     * Get recipient for invoice
     *
     * @throws RecipientException
     */
    public function getRecipient(): Recipient
    {
        if (! isset($this->recipient)) {
            throw new RecipientException('Recipient not found.');
        }

        return $this->recipient;
    }

    /**
     * Set tax type
     */
    public function setTaxType(TaxType $taxType): void
    {
        $this->taxType = $taxType;
    }

    /**
     * Get tax type value or type IVA by default
     */
    public function getTaxType(): string
    {
        return isset($this->taxType) ? $this->taxType->value : TaxType::IVA->value;
    }

    /**
     * Set tax regime IVA
     */
    public function setTaxRegimeIVA(TaxRegimeIVA $taxRegime): void
    {
        $this->taxRegimeIVA = $taxRegime;
    }

    /**
     * Get tax regime value or type General by default
     */
    public function getTaxRegimeIVA(): string
    {
        return isset($this->taxRegimeIVA) ? $this->taxRegimeIVA->value : TaxRegimeIVA::GENERAL->value;
    }

    /**
     * Set operation qualification
     */
    public function setOperationQualification(OperationQualificationType $operationQualification): void
    {
        $this->operationQualification = $operationQualification;
    }

    /**
     * Set exempt operation type
     */
    public function setExemptOperation(ExemptOperationType $exemptOperation): void
    {
        $this->exemptOperation = $exemptOperation;
    }

    /**
     * Set/add options
     *
     * @throws InvoiceValidationException
     */
    public function setOptions(array $options, bool $reset = false): void
    {
        $exemptOperation = null;
        $rejectionStatus = null;

        if (array_key_exists('exempt_operation', $options)) {
            if ($options['exempt_operation'] instanceof ExemptOperationType) {
                $exemptOperation = $options['exempt_operation'];
            } elseif (! is_string($options['exempt_operation'])) {
                throw new InvoiceValidationException('Exempt operation must be a valid ExemptOperationType value.');
            } else {
                $exemptOperation = ExemptOperationType::tryFrom($options['exempt_operation']);
            }

            if ($exemptOperation === null) {
                throw new InvoiceValidationException('Exempt operation must be a valid ExemptOperationType value.');
            }
        }

        if (array_key_exists('rechazo_previo', $options)) {
            if ($options['rechazo_previo'] instanceof RejectionStatus) {
                $rejectionStatus = $options['rechazo_previo'];
            } elseif (! is_string($options['rechazo_previo'])) {
                throw new InvoiceValidationException('Rechazo previo must be a valid RejectionStatus value.');
            } else {
                $rejectionStatus = RejectionStatus::tryFrom($options['rechazo_previo']);
            }

            if ($rejectionStatus === null) {
                throw new InvoiceValidationException('Rechazo previo must be a valid RejectionStatus value.');
            }

            if ($rejectionStatus !== RejectionStatus::NO && ! array_key_exists('subsanacion', $options)) {
                throw new InvoiceValidationException('Rechazo previo S or X requires subsanacion.');
            }

            $options['rechazo_previo'] = $rejectionStatus->value;
        }

        parent::setOptions($options, $reset);

        if ($reset) {
            $this->exemptOperation = null;
        }

        if ($exemptOperation !== null) {
            $this->setExemptOperation($exemptOperation);
        }
    }

    /**
     * Get operation qualification
     *
     * @throws InvoiceValidationException
     */
    public function getOperationQualification(): string
    {
        if ($this->type == InvoiceType::SIMPLIFIED) {
            return OperationQualificationType::SUBJECT_DIRECT->value;
        }

        if (! isset($this->operationQualification) && ! isset($this->recipient)) {
            throw new InvoiceValidationException('Recipient must be set.');
        }

        if (! isset($this->operationQualification)) {
            throw new InvoiceValidationException('Operation qualification must be set for normal invoice.');
        }

        return $this->operationQualification->value;
    }

    /**
     * Check if operation qualification type is VAT exempt
     *
     * @throws InvoiceValidationException
     */
    public function isVatExemptOperation(): bool
    {
        if (isset($this->exemptOperation)) {
            return true;
        }

        if (! isset($this->operationQualification)) {
            throw new InvoiceValidationException('Operation qualification must be set for normal invoice.');
        }

        return $this->operationQualification === OperationQualificationType::NOT_SUBJECT_LOCALIZATION || $this->operationQualification == OperationQualificationType::NOT_SUBJECT_ARTICLE;
    }

    /**
     * Check if operation qualification type is intracommunity (customer is EU company)
     */
    public function isIntracommunityOperation(): bool
    {
        return $this->operationQualification === OperationQualificationType::SUBJECT_REVERSE;
    }

    /**
     * Hash generator for submission
     */
    public function hash(?string $timestamp = null): string
    {
        $parts = [
            'IDEmisorFactura='.$this->issuer->id,
            'NumSerieFactura='.$this->invoiceNumber,
            'FechaExpedicionFactura='.$this->invoiceDate->format('d-m-Y'),
            'TipoFactura='.$this->type->value,
            'CuotaTotal='.$this->normalizeDecimal($this->taxAmount),
            'ImporteTotal='.$this->normalizeDecimal($this->totalAmount),
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

    /**
     * Check for Standard (F2) invoice type
     */
    public function isSimplified(): bool
    {
        return $this->type === InvoiceType::SIMPLIFIED;
    }

    /**
     * Check for credit note (Factura rectificada)
     */
    public function isRectificado(): bool
    {
        return $this->type === InvoiceType::RECTIFICATION_1 || $this->type === InvoiceType::RECTIFICATION_2 || $this->type === InvoiceType::RECTIFICATION_3 || $this->type === InvoiceType::RECTIFICATION_4 || $this->type === InvoiceType::RECTIFICATION_SIMPLIFIED;
    }
}
