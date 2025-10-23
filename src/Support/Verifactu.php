<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use DOMException;
use Illuminate\Support\Carbon;
use RuntimeException;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\RegisterInvoiceException;
use Taiwanleaftea\TltVerifactu\Services\QRCode;

class Verifactu
{
    const string VERSION = '0.1.0';

    /*
     * Configuration variables
     */
    private string $path;
    private string $password;
    private string $type;
    private string $environment;

    /**
     * Production environment
     *
     * @var bool|null
     */
    private bool $isProduction;

    /**
     * Invoicing software information
     */
    private string $systemName;
    private string $providerName;
    private string $providerNif;

    /**
     * Load Verifactu config
     */
    public function __construct()
    {
        $this->isProduction = config('tlt-verifactu.production');

        $this->path = storage_path(config('tlt-verifactu.path'));
        $this->password = config('tlt-verifactu.password');
        /*
        $this->type = config('tlt-verifactu.type-certificate') ? VerifactuLibrary::TYPE_CERTIFICATE : VerifactuLibrary::TYPE_SEAL;
        $this->environment = $this->isProduction ? VerifactuLibrary::ENVIRONMENT_PRODUCTION : VerifactuLibrary::ENVIRONMENT_SANDBOX;
        */

        $this->systemName = config('tlt-verifactu.system_name');
        $this->providerName = config('tlt-verifactu.provider_name');
        $this->providerNif = config('tlt-verifactu.provider_nif');
    }

    /**
     * @param string $issuer
     * @param string $issuerNIF
     * @param Carbon $date
     * @param string $invoiceNumber
     * @param string $description
     * @param float $amount
     * @param float $vat
     * @param int $rate
     * @param string $recipientName
     * @param string $recipientNIF
     * @param string|null $previousNumber
     * @param Carbon|null $previousDate
     * @param string|null $previousHash
     * @return array
     * @throws InvoiceValidationException
     * @throws RegisterInvoiceException
     */
    public function submitInvoice(
        string $issuer,
        string $issuerNIF,
        Carbon $date,
        string $invoiceNumber,
        string $description,
        float $amount,
        float $vat,
        int $rate,
        string $recipientName,
        string $recipientNIF,
        ?string $previousNumber = null,
        ?Carbon $previousDate = null,
        ?string $previousHash = null,
    ): array
    {
        VerifactuLibrary::config($this->path, $this->password, $this->type, $this->environment);

        $invoice = new InvoiceSubmission();

        // Set invoice ID
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $issuerNIF;
        $invoiceId->seriesNumber = $invoiceNumber;
        $invoiceId->issueDate = $date->format('d-m-Y');
        $invoice->setInvoiceId($invoiceId);

        // Set basic invoice data
        $invoice->issuerName = $issuer;
        $invoice->invoiceType = InvoiceType::STANDARD;
        $invoice->operationDescription = $description;
        $invoice->taxAmount = $vat;
        $invoice->totalAmount = $amount + $vat;
        $invoice->simplifiedInvoice = YesNoType::NO;
        $invoice->invoiceWithoutRecipient = YesNoType::NO;

        // Add tax breakdown (using object-oriented approach)
        $breakdown = new Breakdown();
        $detail = new BreakdownDetail();
        $detail->regimeKey = '01';
        $detail->taxType = TaxType::IVA;
        $detail->taxRate = $rate;
        $detail->taxableBase = $amount;
        $detail->taxAmount = $vat;
        $detail->operationQualification = OperationQualificationType::SUBJECT_NO_EXEMPT_NO_REVERSE;
        $breakdown->addDetail($detail);
        $invoice->setBreakdown($breakdown);

        // Set chaining data (using object-oriented approach)
        $chaining = new Chaining();

        if ($previousNumber) {
            // For subsequent invoices:
            $chaining->setPreviousInvoice([
                'seriesNumber' => $previousNumber,
                'issuerNif' => $issuerNIF,
                'issueDate' => $previousDate->format('d-m-Y'),
                'hash' => $previousHash
            ]);
        } else {
            // For the first invoice in a chain
            $chaining->firstRecord = YesNoType::YES;
        }
        $invoice->setChaining($chaining);

        // Set system information (using object-oriented approach)
        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = $this->systemName;
        $computerSystem->version = self::VERSION;
        $computerSystem->providerName = $this->providerName;
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;

        // Set provider information
        $provider = new LegalPerson();
        $provider->name = $this->providerName;
        $provider->nif = $this->providerNif;
        $computerSystem->setProviderId($provider);

        $invoice->setSystemInfo($computerSystem);

        // Set other required fields
        $invoice->recordTimestamp = $date->now()->toAtomString(); //'2024-07-01T12:00:00+02:00' Date and time with timezone
        $invoice->hashType = HashType::SHA_256;
        $invoice->hash = HashGeneratorService::generate($invoice);

        // Add recipients (using object-oriented approach)
        $recipient = new Recipient();
        $recipientPerson = new LegalPerson();
        $recipientPerson->name = $recipientName;
        $recipientPerson->nif = $recipientNIF;
        //$recipient->setLegalPerson($recipientPerson);
        $invoice->addRecipient($recipientPerson);

        // Validate the invoice before submission
        $validationResult = $invoice->validate();

        if ($validationResult) {
            // Handle validation errors
            $message = 'Invoice validation failed' . PHP_EOL;
            foreach ($validationResult as $result) {
                $message .= implode(PHP_EOL, $result) . PHP_EOL;
            }

            throw new InvoiceValidationException($message);
        }

        // Submit the invoice
        try {
            $response = VerifactuLibrary::registerInvoice($invoice);
        } catch (DOMException|SoapFault|RuntimeException $e) {
            throw new RegisterInvoiceException($e->getMessage());
        }

        if ($response->submissionStatus === InvoiceResponse::STATUS_OK) {
            return [
                'success' => true,
                'hash' => $invoice->hash,
                'csv' => $response->csv,
                'invoice' => $invoice,
                'errors' => []
            ];
        } else {
            // Check error codes and messages in $response->lineResponses
            $errors = [];
            foreach ($response->lineResponses as $lineResponse) {
                $error = 'Error code: ';
                $error .= $lineResponse['CodigoErrorRegistro'] ?? 'n/a';
                $error .= PHP_EOL;
                $error .= 'Registro error description: ';
                $error .= $lineResponse['DescripcionErrorRegistro'] ?? 'n/a';
                $error .= PHP_EOL;
                $error .= 'Error description: ';
                $error .= $lineResponse['ErrorDescription'] ?? 'n/a';
                $errors[] = $error;
            }
            return [
                'success' => false,
                'hash' => $invoice->hash,
                'csv' => '',
                'invoice' => $invoice,
                'errors' => $errors
            ];
        }
    }

    /**
     * @throws InvoiceValidationException
     */
    public function cancelInvoice(
        string $issuerNIF,
        Carbon $date,
        string $invoiceNumber,
        ?string $previousNumber = null,
        ?Carbon $previousDate = null,
        ?string $previousHash = null,
    )
    {
        VerifactuLibrary::config($this->path, $this->password, $this->type, $this->environment);

        $cancellation = new InvoiceCancellation();

        // Set invoice ID (using object-oriented approach)
        $invoiceId = new InvoiceId();
        $invoiceId->issuerNif = $issuerNIF;
        $invoiceId->seriesNumber = $invoiceNumber;
        $invoiceId->issueDate = $date->format('d-m-Y');
        $cancellation->setInvoiceId($invoiceId);

        // Set chaining data (using object-oriented approach)
        $chaining = new Chaining();
        if ($previousNumber) {
            // For subsequent invoices in a chain:
            $chaining->setPreviousInvoice([
                'seriesNumber' => $previousNumber,
                'issuerNif' => $issuerNIF,
                'issueDate' => $previousDate->format('d-m-Y'),
                'hash' => $previousHash
            ]);
        } else {
            //$chaining->setAsFirstRecord();
        }
        $cancellation->setChaining($chaining);

        // Set system information (using object-oriented approach)
        $computerSystem = new ComputerSystem();
        $computerSystem->systemName = $this->systemName;
        $computerSystem->version = self::VERSION;
        $computerSystem->providerName = $this->providerName;
        $computerSystem->systemId = '01';
        $computerSystem->installationNumber = '1';
        $computerSystem->onlyVerifactu = YesNoType::YES;
        $computerSystem->multipleObligations = YesNoType::NO;

        // Set provider information
        $provider = new LegalPerson();
        $provider->name = $this->providerName;
        $provider->nif = $this->providerNif;
        $computerSystem->setProviderId($provider);

        $cancellation->setSystemInfo($computerSystem);

        // Set other required fields
        $cancellation->recordTimestamp = $date->now()->toAtomString(); //'2024-07-01T12:00:00+02:00'; // Date and time with timezone
        $cancellation->hashType = HashType::SHA_256;
        $cancellation->hash = HashGeneratorService::generate($cancellation); // Calculated hash

        // Optional fields
        if (!$previousNumber) {
            $cancellation->noPreviousRecord = YesNoType::NO; // Not a cancellation without previous record
        }

        $cancellation->previousRejection = YesNoType::NO; // Not a cancellation due to previous rejection
        $cancellation->generator = GeneratorType::ISSUER; // Generated by the issuer
        //$cancellation->externalRef = 'REF-CANCEL-123'; // External reference

        // Validate the cancellation before submission
        $validationResult = $cancellation->validate();
        if ($validationResult) {
            // Handle validation errors
            $message = 'Invoice validation failed' . PHP_EOL;
            foreach ($validationResult as $result) {
                $message .= implode(PHP_EOL, $result) . PHP_EOL;
            }

            throw new InvoiceValidationException($message);
        }

        // Submit the cancellation
        $response = VerifactuLibrary::cancelInvoice($cancellation);

        if ($response->submissionStatus === InvoiceResponse::STATUS_OK) {
            return [
                'success' => true,
                'hash' => $cancellation->hash,
                'csv' => $response->csv,
                'cancellation' => $cancellation,
                'errors' => []
            ];
        } else {
            // Check error codes and messages in $response->lineResponses
            $errors = [];
            foreach ($response->lineResponses as $lineResponse) {
                $error = 'Error code: ';
                $error .= $lineResponse['CodigoErrorRegistro'] ?? 'n/a';
                $error .= PHP_EOL;
                $error .= 'Registro error description: ';
                $error .= $lineResponse['DescripcionErrorRegistro'] ?? 'n/a';
                $error .= PHP_EOL;
                $error .= 'Error description: ';
                $error .= $lineResponse['ErrorDescription'] ?? 'n/a';
                $errors[] = $error;
            }
            return [
                'success' => false,
                'hash' => $cancellation->hash,
                'csv' => '',
                'invoice' => $cancellation,
                'errors' => $errors
            ];
        }
    }

    /**
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     * @return string
     */
    public function generateQrSVG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string
    {
        return QRCode::SVG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->isProduction);
    }
}
