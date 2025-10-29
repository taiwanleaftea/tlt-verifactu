<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use DOMException;
use Illuminate\Support\Carbon;
use SoapFault;
use SoapVar;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\EstadoEnvio;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Services\QRCode;
use Taiwanleaftea\TltVerifactu\Services\Soap;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;

class Verifactu
{
    private VerifactuSettings $settings;
    private Certificate $certificate;

    public function __construct()
    {
        $this->settings = new VerifactuSettings();
    }

    public function config(Certificate $certificate): void
    {
        $this->certificate = $certificate;
    }

    /**
     * Invoice submission service
     *
     * @param LegalPerson $issuer
     * @param array $invoiceData
     * @param array $options
     * @param array|null $previous
     * @param Recipient|null $recipient
     * @param Carbon|null $timestamp
     * @return ResponseAeat
     * @throws CertificateException
     */
    public function submitInvoice(
        LegalPerson $issuer,
        array $invoiceData,
        array $options,
        ?array $previous = null,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat
    {
        $keys = ['number', 'date', 'description', 'type', 'amount', 'base', 'vat', 'rate'];
        if (($key = $this->checkArray($keys, $invoiceData)) !== true) {
            return $this->responseWithErrors('Invoice key ' . $key . ' is missing.');
        }

        $invoice = new InvoiceSubmission(
            issuer:$issuer,
            invoiceNumber: $invoiceData['number'],
            invoiceDate: $invoiceData['date'],
            description: $invoiceData['description'],
            type: $invoiceData['type'],
            taxRate: $invoiceData['rate'],
            taxableBase: $invoiceData['base'],
            taxAmount: $invoiceData['vat'],
            totalAmount: $invoiceData['amount'],
            timestamp: $timestamp ?? Carbon::now('Europe/Madrid'),
        );

        if (!$invoice->isSimplified()) {
            if (is_null($recipient)) {
                return $this->responseWithErrors('Recipient object is missing.');
            } else {
                $invoice->setRecipient($recipient);
            }
        }

        if ($previous !== null) {
            $keys = ['number', 'date', 'hash'];
            if (!$this->checkArray($keys, $invoiceData)) {
                return $this->responseWithErrors('Previous invoice key is missing.');
            } else {
                $invoice->setPreviousInvoice(
                    number: $previous['number'],
                    date: $previous['date'],
                    hash: $previous['hash'],
                );
            }
        }

        $submission = new SubmitInvoice($this->settings);

        try {
            $submission->getXml($invoice);
        } catch (DOMException|InvoiceValidationException|RecipientException $e) {
            return $this->responseWithErrors('XML cannot be created: ' . $e->getMessage());
        }

        try {
            $submission->signXml($this->certificate);
        } catch (CertificateException $e) {
            return $this->responseWithErrors('XML cannot be signed: ' . $e->getMessage());
        }

        try {
            $envelopedDom = $submission->createEnvelopedXml($issuer);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be enveloped: ' . $e->getMessage());
        }

        $finalXml = $submission->sanitizeXml($envelopedDom);

        $soapOptions = [
            'location' => $this->settings->getVerifactuServiceUrl(),
            'trace' => 1,
            'exceptions' => true,
            'local_cert' => $this->certificate->generatePem(),
            'passphrase' => $this->certificate->getPassword(),
            'cache_wsdl' => WSDL_CACHE_NONE,
        ];

        try {
            $soapClient = Soap::createClient(AEAT::WSDL_SANDBOX, $soapOptions);
        } catch (SoapClientException $e) {
            return $this->responseWithErrors('SOAP client error: ' . $e->getMessage());
        }

        $soapVar = new SoapVar($finalXml, XSD_ANYXML);

        try {
            $soapResponse = $soapClient->__soapCall('RegFactuSistemaFacturacion', [$soapVar]);
        } catch (SoapFault $e) {
            $errors = [];
            $errors[] = 'SOAP call failed: ' . $e->getMessage();
            $errors[] = 'XML sent: ' . PHP_EOL . $finalXml;
            $errors[] = 'Last SOAP call: ' . $soapClient->__getLastRequest();
            $errors[] = 'Last SOAP response: ' . $soapClient->__getLastResponse();
            $errors[] = 'Last request header: ' . $soapClient->__getLastRequestHeaders();

            return $this->responseWithErrors($errors, ['request' => $finalXml]);
        }

        $response = new ResponseAeat();

        if ($soapResponse->EstadoEnvio == EstadoEnvio::ACCEPTED->value) {
            $response->success = true;
            $response->csv = $soapResponse->CSV;
        } else {
            $response->success = false;
            $response->status = EstadoEnvio::tryFrom($soapResponse->EstadoEnvio);

            if ($response->status === null) {
                $response->statusRaw = $soapResponse->EstadoEnvio;
            }

            $response->errors[] = 'Error ' . $soapResponse->RespuestaLinea->CodigoErrorRegistro . ': ' . $soapResponse->RespuestaLinea->DescripcionErrorRegistro;

            if (isset($soapResponse->RespuestaLinea->RegistroDuplicado)) {
                $response->duplicate = true;
                $response->duplicateStatus = $soapResponse->RespuestaLinea->RegistroDuplicado->EstadoRegistroDuplicado;
            }
        }

        $response->timestamp = Carbon::parse($soapResponse->DatosPresentacion->TimestampPresentacion);
        $response->request = $finalXml;
        $response->response = $soapResponse ?? null;
        $response->rawResponse = $soapClient->__getLastResponse();

        return $response;
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
        return QRCode::SVG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    private function checkArray(array $keys, array $array): bool|string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) {
                return $key;
            }
        }

        return true;
    }

    /**
     * Return response with errors
     *
     * @param string|array $messages
     * @param array $additionals
     * @return ResponseAeat
     */
    private function responseWithErrors(string|array $messages, array $additionals = []): ResponseAeat
    {
        $response = new ResponseAeat();
        $response->success = false;
        $response->errors = is_array($messages) ? $messages : [$messages];

        foreach ($additionals as $key => $additional) {
            $response->{$key} = $additional;
        }

        return $response;
    }
}
