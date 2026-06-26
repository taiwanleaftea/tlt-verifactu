<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use DOMDocument;
use DOMException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use SoapClient;
use SoapFault;
use SoapVar;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\Generator;
use Taiwanleaftea\TltVerifactu\Classes\Invoice;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceCancellation;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\EstadoEnvio;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Enums\ExemptOperationType;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Models\VerifactuRecord;
use Taiwanleaftea\TltVerifactu\Services\CancelInvoice;
use Taiwanleaftea\TltVerifactu\Services\QRCode;
use Taiwanleaftea\TltVerifactu\Services\Soap;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;
use Taiwanleaftea\TltVerifactu\Services\XadesEpesSigner;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;
use Taiwanleaftea\TltVerifactu\Traits\CheckArray;

class Verifactu
{
    use CheckArray;

    private VerifactuSettings $settings;

    private Certificate $certificate;

    /**
     * Create a VERIFACTU support service instance using the current package configuration.
     */
    public function __construct()
    {
        $this->settings = new VerifactuSettings;
    }

    /**
     * Configure the certificate used for online SOAP communication and XAdES signing.
     */
    public function config(Certificate $certificate): void
    {
        $this->certificate = $certificate;
    }

    /**
     * Return the latest registry record for a record's issuer/scope chain or for an explicitly provided issuer/scope.
     */
    public function getPreviousRecord(VerifactuRecord|int|null $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?VerifactuRecord
    {
        if (! Schema::hasTable('verifactu_records')) {
            return null;
        }

        if ($recordId !== null) {
            return $this->resolveRegistryRecord($recordId)?->getPreviousRecord();
        }

        return VerifactuRecord::previousForChain($issuerNif ?? '', $registryScope);
    }

    /**
     * Return the id of the latest registry record for a record's issuer/scope chain.
     */
    public function getPreviousRecordId(VerifactuRecord|int|null $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?int
    {
        $record = $this->getPreviousRecord($recordId, $issuerNif, $registryScope);

        return $record === null ? null : (int) $record->getKey();
    }

    /**
     * Backward-compatible alias for getPreviousRecordId().
     */
    public function getPreviousId(VerifactuRecord|int|null $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?int
    {
        return $this->getPreviousRecordId($recordId, $issuerNif, $registryScope);
    }

    /**
     * Return the hash of the latest registry record for a record's issuer/scope chain.
     */
    public function getPreviousHash(VerifactuRecord|int|null $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?string
    {
        return $this->getPreviousRecord($recordId, $issuerNif, $registryScope)?->hash;
    }

    /**
     * Submit a corrected RegistroAlta with Subsanacion=S for an existing local registry record.
     *
     * @throws CertificateException
     */
    public function subsanateInvoice(
        LegalPerson $issuer,
        VerifactuRecord|int $recordId,
        array $invoiceData,
        OperationQualificationType $operationQualificationType = OperationQualificationType::SUBJECT_DIRECT,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat {
        $record = $this->findRegistryRecordById($issuer, $recordId);

        if ($record instanceof ResponseAeat) {
            return $record;
        }

        if ($record === null) {
            return $this->responseWithErrors('Invoice registry record was not found in verifactu_records.');
        }

        if ($record->status === 'rejected') {
            return $this->responseWithErrors('Rejected invoice records must be fixed and retried, not subsanated.');
        }

        $previous = $this->previousPayloadFromLatestRegistryRecord($issuer);

        if ($previous instanceof ResponseAeat) {
            return $previous;
        }

        return $this->submitInvoice(
            issuer: $issuer,
            invoiceData: $invoiceData,
            options: ['subsanacion' => true],
            operationQualificationType: $operationQualificationType,
            previous: $previous,
            recipient: $recipient,
            timestamp: $timestamp,
        );
    }

    /**
     * Submit a difference-based factura rectificativa using invoice_payload from the rectified registry record.
     *
     * @throws CertificateException
     */
    public function submitRectificationInvoice(
        VerifactuRecord|int|null $rectifiedRecordId = null,
        ?array $invoiceData = null,
        ?LegalPerson $issuer = null,
        ?OperationQualificationType $operationQualificationType = null,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat {
        if ($rectifiedRecordId === null) {
            return $this->responseWithErrors('Invoice registry record is required for submitRectificationInvoice.');
        }

        $rectifiedRecord = $this->findRegistryRecordById($issuer, $rectifiedRecordId);

        if ($rectifiedRecord instanceof ResponseAeat) {
            return $rectifiedRecord;
        }

        if ($rectifiedRecord === null) {
            return $this->responseWithErrors('Invoice registry record was not found in verifactu_records.');
        }

        $issuer ??= new LegalPerson(
            name: (string) ($rectifiedRecord->issuer_name ?: $rectifiedRecord->issuer_nif),
            id: (string) $rectifiedRecord->issuer_nif,
        );

        $rectification = $this->buildRectificationInvoiceFromRecord(
            rectifiedRecord: $rectifiedRecord,
            invoiceData: $invoiceData,
            operationQualificationType: $operationQualificationType,
            recipient: $recipient,
            timestamp: $timestamp,
        );

        if ($rectification instanceof ResponseAeat) {
            return $rectification;
        }

        $previous = $this->previousPayloadFromLatestRegistryRecord($issuer);

        if ($previous instanceof ResponseAeat) {
            return $previous;
        }

        return $this->submitInvoice(
            issuer: $issuer,
            invoiceData: $rectification['invoiceData'],
            options: array_merge(
                $rectification['options'],
                [
                    'rectificado' => [
                        'invoice_number' => $rectifiedRecord->invoice_number,
                        'invoice_date' => Carbon::parse($rectifiedRecord->invoice_date),
                        'simplified' => $rectifiedRecord->invoice_type === InvoiceType::SIMPLIFIED,
                    ],
                ]
            ),
            operationQualificationType: $rectification['operationQualificationType'],
            previous: $previous,
            recipient: $rectification['recipient'],
            timestamp: $timestamp,
        );
    }

    /**
     * Generate and either store or submit a RegistroAlta for a new invoice.
     *
     * @throws CertificateException
     */
    public function submitInvoice(
        LegalPerson $issuer,
        array $invoiceData,
        array $options,
        OperationQualificationType $operationQualificationType = OperationQualificationType::SUBJECT_DIRECT,
        ?array $previous = null,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat {
        if (is_null($timestamp)) {
            $timestamp = Carbon::now();
        }

        $keys = ['number', 'date', 'description', 'type', 'amount', 'base', 'vat', 'rate'];
        if (($key = $this->checkArray($keys, $invoiceData)) !== true) {
            return $this->responseWithErrors('Invoice key '.$key.' is missing.');
        }

        $invoice = new InvoiceSubmission(
            issuer: $issuer,
            invoiceNumber: $invoiceData['number'],
            invoiceDate: $invoiceData['date'],
            description: $invoiceData['description'],
            type: $invoiceData['type'],
            taxRate: $invoiceData['rate'],
            taxableBase: $invoiceData['base'],
            taxAmount: $invoiceData['vat'],
            totalAmount: $invoiceData['amount'],
            timestamp: $timestamp,
        );

        if (! $invoice->isSimplified()) {
            if (is_null($recipient)) {
                return $this->responseWithErrors('Recipient object is missing.');
            } else {
                $invoice->setRecipient($recipient);
            }

            if ($operationQualificationType == OperationQualificationType::SUBJECT_REVERSE && ! VatValidator::isEU($recipient->countryCode)) {
                return $this->responseWithErrors('Recipient must be from EU to apply reverse charge.');
            } else {
                $invoice->setOperationQualification($operationQualificationType);
            }
        } else {
            $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);
        }

        if ($previous !== null) {
            $keys = ['number', 'date', 'hash'];
            if (! $this->checkArray($keys, $previous)) {
                return $this->responseWithErrors('Previous invoice key is missing.');
            } else {
                $invoice->setPreviousInvoice(
                    number: $previous['number'],
                    date: $previous['date'],
                    hash: $previous['hash'],
                );
            }
        }

        if (! empty($options)) {
            try {
                $invoice->setOptions($options);
            } catch (InvoiceValidationException $e) {
                return $this->responseWithErrors('Invoice cannot be validated (getXml): '.$e->getMessage());
            }
        }

        if ($invoice->isRectificado() && $invoice->getOption('rectificado') === null) {
            return $this->responseWithErrors('Credit note (factura rectificada) does not contain necessary data (submitInvoice).');
        }

        $submission = new SubmitInvoice($this->settings);

        try {
            $registroDom = $submission->getXml($invoice);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be created (getXml): '.$e->getMessage());
        } catch (InvoiceValidationException $e) {
            return $this->responseWithErrors('Invoice cannot be validated (getXml): '.$e->getMessage());
        } catch (RecipientException $e) {
            return $this->responseWithErrors('Recipient cannot be validated (getXml): '.$e->getMessage());
        }

        if ($this->settings->storesRecordsOnly()) {
            $registroXml = $registroDom->saveXML($registroDom->documentElement);

            if ($registroXml === false) {
                return $this->responseWithErrors('XML cannot be serialized for registry storage.');
            }

            $signedXml = null;
            $signedAt = null;

            if ($this->settings->signsStoredRecords()) {
                if (! isset($this->certificate)) {
                    return $this->responseWithErrors('NO VERIFACTU mode requires a signing certificate. Call config() before storing signed records.');
                }

                if (($certificateError = $this->validateIssuerCertificate($issuer)) !== null) {
                    return $certificateError;
                }

                $signedAt = Carbon::now();

                try {
                    $submission->signXml($this->certificate, $signedAt);
                } catch (CertificateException|\Exception $e) {
                    return $this->responseWithErrors('XML cannot be signed: '.$e->getMessage());
                }

                $signedXml = $registroDom->saveXML($registroDom->documentElement);

                if ($signedXml === false) {
                    return $this->responseWithErrors('Signed XML cannot be serialized for registry storage.');
                }
            }

            return $this->storeGeneratedRecord(
                invoice: $invoice,
                recordType: VerifactuRecordType::ALTA,
                requestXml: $registroXml,
                signedXml: $signedXml,
                signedAt: $signedAt,
                invoiceType: $invoice->type->value,
                qrUri: QRCode::buildUrl(
                    issuerNIF: $invoice->issuer->id,
                    invoiceDate: $invoice->invoiceDate,
                    number: $invoice->invoiceNumber,
                    totalAmount: $invoice->totalAmount,
                    isProduction: $this->settings->isProduction()
                )
            );
        }

        if (! isset($this->certificate)) {
            return $this->responseWithErrors('Online VERIFACTU mode requires a certificate. Call config() before sending records.');
        }

        if (($certificateError = $this->validateIssuerCertificate($issuer)) !== null) {
            return $certificateError;
        }

        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('Online VERIFACTU mode requires the verifactu_records table. Run the package migrations before sending records.');
        }

        $registroXml = $registroDom->saveXML($registroDom->documentElement);

        if ($registroXml === false) {
            return $this->responseWithErrors('XML cannot be serialized for registry storage.');
        }

        $signedXml = null;
        $signedAt = Carbon::now();

        if ($this->settings->signsOnlineRecords()) {
            try {
                $submission->signXml($this->certificate, $signedAt);
            } catch (CertificateException|\Exception $e) {
                return $this->responseWithErrors('XML cannot be signed: '.$e->getMessage());
            }

            $signedXml = $registroDom->saveXML($registroDom->documentElement);
        } else {
            try {
                $signedXml = $this->signedRegistryXml($registroXml, $signedAt);
            } catch (CertificateException|\Exception $e) {
                return $this->responseWithErrors('XML cannot be signed for registry storage: '.$e->getMessage());
            }
        }

        if ($signedXml === false || $signedXml === null) {
            return $this->responseWithErrors('Signed XML cannot be serialized for registry storage.');
        }

        try {
            $envelopedDom = $submission->createEnvelopedXml($issuer);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be enveloped: '.$e->getMessage());
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
            $soapClient = $this->createSoapClient($this->settings->getVerifactuWsdlUrl(), $soapOptions);
        } catch (SoapClientException $e) {
            return $this->responseWithErrors('SOAP client error: '.$e->getMessage());
        }

        $soapVar = new SoapVar($finalXml, XSD_ANYXML);

        try {
            $soapResponse = $soapClient->__soapCall('RegFactuSistemaFacturacion', [$soapVar]);
        } catch (SoapFault $e) {
            $errors = [];
            $errors[] = 'SOAP call failed: '.$e->getMessage();
            $errors[] = 'XML sent: '.PHP_EOL.$finalXml;
            $errors[] = 'Last SOAP call: '.$soapClient->__getLastRequest();
            $errors[] = 'Last SOAP response: '.$soapClient->__getLastResponse();
            $errors[] = 'Last request header: '.$soapClient->__getLastRequestHeaders();

            return $this->responseWithErrors($errors, ['request' => $finalXml]);
        }

        $response = new ResponseAeat;
        $response->success = false;
        $response->hash = $invoice->hash();
        $response->csv = $soapResponse->CSV ?? null;
        $response->json = json_encode($soapResponse, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);
        $response->timestamp = $invoice->timestamp;

        if (! isset($soapResponse->EstadoEnvio)) {
            $response->errors[] = 'EstadoEnvio has not been received.';
            $response->errors[] = 'Last SOAP response: '.$soapClient->__getLastResponse();
        } elseif ($soapResponse->EstadoEnvio == EstadoEnvio::ACCEPTED->value) {
            $response->success = true;
            if (isset($soapResponse->RespuestaLinea->EstadoRegistro)) {
                $response->status = EstadoRegistro::tryFrom($soapResponse->RespuestaLinea->EstadoRegistro);
            }

            if (config('tlt-verifactu.generate_svg')) {
                $response->qrSVG = QRCode::SVG(
                    issuerNIF: $invoice->issuer->id,
                    invoiceDate: $invoice->invoiceDate,
                    number: $invoice->invoiceNumber,
                    totalAmount: $invoice->totalAmount,
                    isProduction: $this->settings->isProduction()
                );
            }

            $response->qrURI = QRCode::buildUrl(
                issuerNIF: $invoice->issuer->id,
                invoiceDate: $invoice->invoiceDate,
                number: $invoice->invoiceNumber,
                totalAmount: $invoice->totalAmount,
                isProduction: $this->settings->isProduction()
            );
            // TODO разобрать все три варианта ответа от VF
        } else {
            $response->success = false;

            if (isset($soapResponse->RespuestaLinea->EstadoRegistro)) {
                $response->status = EstadoRegistro::tryFrom($soapResponse->RespuestaLinea->EstadoRegistro);

                if ($response->status === null) {
                    $response->statusRaw = $soapResponse->RespuestaLinea->EstadoRegistro;
                }
            }

            if (isset($soapResponse->RespuestaLinea->EstadoRegistro, $soapResponse->RespuestaLinea->DescripcionErrorRegistro)) {
                $response->errors[] = 'Error '.$soapResponse->RespuestaLinea->CodigoErrorRegistro.': '.$soapResponse->RespuestaLinea->DescripcionErrorRegistro;
                $response->aeatErrorCode = $soapResponse->RespuestaLinea->CodigoErrorRegistro;
            }

            if (isset($soapResponse->RespuestaLinea->RegistroDuplicado)) {
                $response->duplicate = true;
                $response->duplicateStatus = $soapResponse->RespuestaLinea->RegistroDuplicado->EstadoRegistroDuplicado;
            }
        }

        if (isset($soapResponse->DatosPresentacion->TimestampPresentacion)) {
            $response->timestamp = Carbon::parse($soapResponse->DatosPresentacion->TimestampPresentacion);
        }
        $response->request = $finalXml;
        $response->response = $soapResponse ?? null;
        $response->rawResponse = $soapClient->__getLastResponse();

        return $this->storeGeneratedRecord(
            invoice: $invoice,
            recordType: VerifactuRecordType::ALTA,
            requestXml: $registroXml,
            signedXml: $signedXml,
            signedAt: $signedAt,
            invoiceType: $invoice->type->value,
            qrUri: $response->qrURI,
            aeatResponse: $response,
        );
    }

    /**
     * Build a RegistroAnulacion from a local registry record and store or submit it as a fallback operation.
     *
     * In production this method is disabled unless enable_cancel_invoice_in_production is explicitly enabled.
     *
     * @throws CertificateException
     */
    public function cancelInvoice(
        VerifactuRecord|int $record,
        ?Generator $generator = null,
        ?Carbon $timestamp = null,
        array $options = [],
    ): ResponseAeat {
        if ($this->settings->isProduction() && ! $this->settings->enablesCancelInvoiceInProduction()) {
            return $this->responseWithErrors('Invoice cancellation is disabled in production. Set VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION=true only when RegistroAnulacion is intentionally required.');
        }

        if (is_null($timestamp)) {
            $timestamp = Carbon::now();
        }

        $record = $this->findRegistryRecordByIdForCurrentScope($record);

        if ($record instanceof ResponseAeat) {
            return $record;
        }

        if ($record === null) {
            return $this->responseWithErrors('Invoice registry record was not found in verifactu_records.');
        }

        $issuer = new LegalPerson(
            name: (string) ($record->issuer_name ?: $record->issuer_nif),
            id: (string) $record->issuer_nif,
        );

        $previous = $this->previousPayloadFromLatestRegistryRecord($issuer);

        if ($previous instanceof ResponseAeat) {
            return $previous;
        }

        $invoice = new InvoiceCancellation(
            issuer: $issuer,
            invoiceNumber: $record->invoice_number,
            invoiceDate: Carbon::parse($record->invoice_date),
            timestamp: $timestamp,
        );

        $invoice->setPreviousInvoice(
            number: $previous['number'],
            date: $previous['date'],
            hash: $previous['hash'],
        );

        if ($generator !== null) {
            $invoice->setGenerator($generator);
        }

        if ($options !== []) {
            try {
                $invoice->setOptions($options);
            } catch (InvoiceValidationException $e) {
                return $this->responseWithErrors('Invoice cancellation cannot be validated (setOptions): '.$e->getMessage());
            }
        }

        return $this->submitCancellationInvoice($issuer, $invoice);
    }

    /**
     * Backward-compatible alias for cancelInvoice().
     *
     * @throws CertificateException
     */
    public function cancelInvoiceByRecordId(
        VerifactuRecord|int $recordId,
        ?Generator $generator = null,
        ?Carbon $timestamp = null,
        array $options = [],
    ): ResponseAeat {
        return $this->cancelInvoice($recordId, $generator, $timestamp, $options);
    }

    /**
     * @throws CertificateException
     */
    private function submitCancellationInvoice(LegalPerson $issuer, InvoiceCancellation $invoice): ResponseAeat
    {
        $cancellation = new CancelInvoice($this->settings);

        try {
            $registroDom = $cancellation->getXml($invoice);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be created (getXml): '.$e->getMessage());
        } catch (InvoiceValidationException $e) {
            return $this->responseWithErrors('Invoice cancellation cannot be validated (getXml): '.$e->getMessage());
        } catch (GeneratorException $e) {
            return $this->responseWithErrors('Invoice cancellation generator cannot be set (getXml): '.$e->getMessage());
        }

        if ($this->settings->storesRecordsOnly()) {
            $registroXml = $registroDom->saveXML($registroDom->documentElement);

            if ($registroXml === false) {
                return $this->responseWithErrors('XML cannot be serialized for registry storage.');
            }

            $signedXml = null;
            $signedAt = null;

            if ($this->settings->signsStoredRecords()) {
                if (! isset($this->certificate)) {
                    return $this->responseWithErrors('NO VERIFACTU mode requires a signing certificate. Call config() before storing signed records.');
                }

                if (($certificateError = $this->validateIssuerCertificate($issuer)) !== null) {
                    return $certificateError;
                }

                $signedAt = Carbon::now();

                try {
                    $cancellation->signXml($this->certificate, $signedAt);
                } catch (CertificateException|\Exception $e) {
                    return $this->responseWithErrors('XML cannot be signed: '.$e->getMessage());
                }

                $signedXml = $registroDom->saveXML($registroDom->documentElement);

                if ($signedXml === false) {
                    return $this->responseWithErrors('Signed XML cannot be serialized for registry storage.');
                }
            }

            return $this->storeGeneratedRecord(
                invoice: $invoice,
                recordType: VerifactuRecordType::ANULACION,
                requestXml: $registroXml,
                signedXml: $signedXml,
                signedAt: $signedAt,
            );
        }

        if (! isset($this->certificate)) {
            return $this->responseWithErrors('Online VERIFACTU mode requires a certificate. Call config() before sending records.');
        }

        if (($certificateError = $this->validateIssuerCertificate($issuer)) !== null) {
            return $certificateError;
        }

        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('Online VERIFACTU mode requires the verifactu_records table. Run the package migrations before sending records.');
        }

        $registroXml = $registroDom->saveXML($registroDom->documentElement);

        if ($registroXml === false) {
            return $this->responseWithErrors('XML cannot be serialized for registry storage.');
        }

        $signedXml = null;
        $signedAt = Carbon::now();

        if ($this->settings->signsOnlineRecords()) {
            try {
                $cancellation->signXml($this->certificate, $signedAt);
            } catch (CertificateException|\Exception $e) {
                return $this->responseWithErrors('XML cannot be signed: '.$e->getMessage());
            }

            $signedXml = $registroDom->saveXML($registroDom->documentElement);
        } else {
            try {
                $signedXml = $this->signedRegistryXml($registroXml, $signedAt);
            } catch (CertificateException|\Exception $e) {
                return $this->responseWithErrors('XML cannot be signed for registry storage: '.$e->getMessage());
            }
        }

        if ($signedXml === false || $signedXml === null) {
            return $this->responseWithErrors('Signed XML cannot be serialized for registry storage.');
        }

        try {
            $envelopedDom = $cancellation->createEnvelopedXml($issuer);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be enveloped: '.$e->getMessage());
        }

        $finalXml = $cancellation->sanitizeXml($envelopedDom);

        $soapOptions = [
            'location' => $this->settings->getVerifactuServiceUrl(),
            'trace' => 1,
            'exceptions' => true,
            'local_cert' => $this->certificate->generatePem(),
            'passphrase' => $this->certificate->getPassword(),
            'cache_wsdl' => WSDL_CACHE_NONE,
        ];

        try {
            $soapClient = $this->createSoapClient($this->settings->getVerifactuWsdlUrl(), $soapOptions);
        } catch (SoapClientException $e) {
            return $this->responseWithErrors('SOAP client error: '.$e->getMessage());
        }

        $soapVar = new SoapVar($finalXml, XSD_ANYXML);

        try {
            $soapResponse = $soapClient->__soapCall('RegFactuSistemaFacturacion', [$soapVar]);
        } catch (SoapFault $e) {
            $errors = [];
            $errors[] = 'SOAP call failed: '.$e->getMessage();
            $errors[] = 'XML sent: '.PHP_EOL.$finalXml;
            $errors[] = 'Last SOAP call: '.$soapClient->__getLastRequest();
            $errors[] = 'Last SOAP response: '.$soapClient->__getLastResponse();
            $errors[] = 'Last request header: '.$soapClient->__getLastRequestHeaders();

            return $this->responseWithErrors($errors, ['request' => $finalXml]);
        }

        $response = new ResponseAeat;
        $response->success = false;
        $response->hash = $invoice->hash();
        $response->csv = $soapResponse->CSV ?? null;
        $response->json = json_encode($soapResponse, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);
        $response->timestamp = $invoice->timestamp;

        if (! isset($soapResponse->EstadoEnvio)) {
            $response->errors[] = 'EstadoEnvio has not been received.';
            $response->errors[] = 'Last SOAP response: '.$soapClient->__getLastResponse();
        } elseif ($soapResponse->EstadoEnvio == EstadoEnvio::ACCEPTED->value) {
            $response->success = true;
            if (isset($soapResponse->RespuestaLinea->EstadoRegistro)) {
                $response->status = EstadoRegistro::tryFrom($soapResponse->RespuestaLinea->EstadoRegistro);
            }
        } else {
            $response->success = false;

            if (isset($soapResponse->RespuestaLinea->EstadoRegistro)) {
                $response->status = EstadoRegistro::tryFrom($soapResponse->RespuestaLinea->EstadoRegistro);

                if ($response->status === null) {
                    $response->statusRaw = $soapResponse->RespuestaLinea->EstadoRegistro;
                }
            }

            if (isset($soapResponse->RespuestaLinea->EstadoRegistro, $soapResponse->RespuestaLinea->DescripcionErrorRegistro)) {
                $response->errors[] = 'Error '.$soapResponse->RespuestaLinea->CodigoErrorRegistro.': '.$soapResponse->RespuestaLinea->DescripcionErrorRegistro;
                $response->aeatErrorCode = $soapResponse->RespuestaLinea->CodigoErrorRegistro;
            }

            if (isset($soapResponse->RespuestaLinea->RegistroDuplicado)) {
                $response->duplicate = true;
                $response->duplicateStatus = $soapResponse->RespuestaLinea->RegistroDuplicado->EstadoRegistroDuplicado;
            }
        }

        if (isset($soapResponse->DatosPresentacion->TimestampPresentacion)) {
            $response->timestamp = Carbon::parse($soapResponse->DatosPresentacion->TimestampPresentacion);
        }
        $response->request = $finalXml;
        $response->response = $soapResponse ?? null;
        $response->rawResponse = $soapClient->__getLastResponse();

        return $this->storeGeneratedRecord(
            invoice: $invoice,
            recordType: VerifactuRecordType::ANULACION,
            requestXml: $registroXml,
            signedXml: $signedXml,
            signedAt: $signedAt,
            aeatResponse: $response,
        );
    }

    /**
     * Generate the AEAT QR verification code as an SVG image.
     */
    public function generateQrSVG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string {
        return QRCode::SVG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    /**
     * Generate the AEAT QR verification code as a PNG image.
     *
     * @throws QRGeneratorException
     */
    public function generateQrPNG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string {
        return QRCode::PNG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    /**
     * Generate the AEAT QR verification URL for an invoice.
     */
    public function generateQrURI(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string {
        return QRCode::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    /**
     * Return response with errors
     */
    private function responseWithErrors(string|array $messages, array $additionals = []): ResponseAeat
    {
        $response = new ResponseAeat;
        $response->success = false;
        $response->errors = is_array($messages) ? $messages : [$messages];

        foreach ($additionals as $key => $additional) {
            $response->{$key} = $additional;
        }

        return $response;
    }

    private function storeGeneratedRecord(
        Invoice $invoice,
        VerifactuRecordType $recordType,
        string $requestXml,
        ?string $signedXml = null,
        ?Carbon $signedAt = null,
        ?string $invoiceType = null,
        ?string $qrUri = null,
        ?ResponseAeat $aeatResponse = null,
    ): ResponseAeat {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before storing records.');
        }

        $hash = $invoice->hash();
        $previousHash = $invoice->previousHash ?: null;
        $registryScope = $this->settings->getRegistryScope();
        $signatureMetadata = [];

        if ($signedXml !== null) {
            try {
                $signatureMetadata = $this->signatureMetadata();
            } catch (CertificateException $e) {
                return $this->responseWithErrors('Signature metadata cannot be read: '.$e->getMessage());
            }
        }

        $previousRecordId = $previousHash
            ? VerifactuRecord::query()
                ->where('registry_scope', $registryScope)
                ->where('issuer_nif', $invoice->issuer->id)
                ->where('hash', $previousHash)
                ->value('id')
            : null;

        $record = VerifactuRecord::create([
            'recordable_type' => null,
            'recordable_id' => null,
            'previous_record_id' => $previousRecordId,
            'registry_scope' => $registryScope,
            'issuer_nif' => $invoice->issuer->id,
            'issuer_name' => $invoice->issuer->name,
            'invoice_number' => $invoice->invoiceNumber,
            'invoice_date' => $invoice->invoiceDate->format('Y-m-d'),
            'record_type' => $recordType->value,
            'invoice_type' => $invoiceType,
            'status' => $this->storedRecordStatus($signedXml, $aeatResponse),
            'estado_envio' => $this->estadoEnvioFromResponse($aeatResponse),
            'estado_registro' => $aeatResponse?->status?->value ?? $aeatResponse?->statusRaw,
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'request_xml' => $requestXml,
            'signed_xml' => $signedXml,
            'invoice_payload' => $this->invoicePayload($invoice),
            'signed_at' => $signedAt,
            'signature_format' => $signatureMetadata['signature_format'] ?? null,
            'signature_algorithm' => $signatureMetadata['signature_algorithm'] ?? null,
            'signature_policy_id' => $signatureMetadata['signature_policy_id'] ?? null,
            'signature_policy_url' => $signatureMetadata['signature_policy_url'] ?? null,
            'signature_policy_hash' => $signatureMetadata['signature_policy_hash'] ?? null,
            'signature_policy_hash_algorithm' => $signatureMetadata['signature_policy_hash_algorithm'] ?? null,
            'certificate_subject' => $signatureMetadata['certificate_subject'] ?? null,
            'certificate_issuer' => $signatureMetadata['certificate_issuer'] ?? null,
            'certificate_serial_number' => $signatureMetadata['certificate_serial_number'] ?? null,
            'certificate_digest' => $signatureMetadata['certificate_digest'] ?? null,
            'certificate_digest_algorithm' => $signatureMetadata['certificate_digest_algorithm'] ?? null,
            'response_json' => $this->responseJsonFromResponse($aeatResponse),
            'raw_response' => $aeatResponse?->rawResponse,
            'csv' => $aeatResponse?->csv,
            'qr_url' => $qrUri,
            'aeat_error_code' => $aeatResponse?->aeatErrorCode,
            'aeat_error_description' => $this->aeatErrorDescription($aeatResponse),
            'sent_at' => $aeatResponse === null ? null : Carbon::now(),
            'accepted_at' => $aeatResponse?->success ? $aeatResponse->timestamp : null,
        ]);

        $recordId = (int) $record->getKey();

        if ($aeatResponse !== null) {
            $aeatResponse->registryRecordId = $recordId;
            $aeatResponse->signedRequest = $signedXml;

            return $aeatResponse;
        }

        $response = new ResponseAeat;
        $response->success = true;
        $response->storedOnly = true;
        $response->registryRecordId = $recordId;
        $response->hash = $hash;
        $response->request = $requestXml;
        $response->signedRequest = $signedXml;
        $response->qrURI = $qrUri;
        $response->timestamp = $invoice->timestamp;

        return $response;
    }

    /**
     * @throws CertificateException
     * @throws \Exception
     */
    private function signedRegistryXml(string $registryXml, Carbon $signedAt): string
    {
        $dom = new DOMDocument('1.0', 'utf-8');

        if ($dom->loadXML($registryXml) === false) {
            throw new DOMException('Registry XML cannot be loaded for signing.');
        }

        $signedDom = (new XadesEpesSigner)->sign($dom, $this->certificate, $signedAt);
        $signedXml = $signedDom->saveXML($signedDom->documentElement);

        if ($signedXml === false) {
            throw new DOMException('Signed XML cannot be serialized.');
        }

        return $signedXml;
    }

    /**
     * @throws SoapClientException
     */
    protected function createSoapClient(string $wsdl, array $options): SoapClient
    {
        return Soap::createClient($wsdl, $options);
    }

    private function validateIssuerCertificate(LegalPerson $issuer): ?ResponseAeat
    {
        if ($this->settings->allowsRepresentativeCertificate()) {
            return null;
        }

        try {
            $certificateNif = $this->certificate->getSubjectNif();
        } catch (CertificateException $e) {
            return $this->responseWithErrors('Certificate cannot be validated: '.$e->getMessage());
        }

        if ($certificateNif === null) {
            return $this->responseWithErrors('Certificate NIF cannot be determined. Set allow_representative_certificate to true only when using an authorized representative certificate.');
        }

        $issuerNif = $this->normalizeSpanishId($issuer->id);

        if ($issuerNif !== $certificateNif) {
            return $this->responseWithErrors('Certificate NIF '.$certificateNif.' does not match issuer NIF '.$issuer->id.'. Set allow_representative_certificate to true only when using an authorized representative certificate.');
        }

        return null;
    }

    private function normalizeSpanishId(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));

        return is_string($normalized) ? $normalized : strtoupper($value);
    }

    private function storedRecordStatus(?string $signedXml, ?ResponseAeat $aeatResponse): string
    {
        if ($aeatResponse === null) {
            return $signedXml === null ? 'stored' : 'signed';
        }

        if ($aeatResponse->status === EstadoRegistro::ACCEPTED_ERRORES) {
            return 'accepted_with_errors';
        }

        if ($aeatResponse->success) {
            return 'accepted';
        }

        if ($aeatResponse->status === EstadoRegistro::NOT_ACCEPTED) {
            return 'rejected';
        }

        return 'sent';
    }

    private function estadoEnvioFromResponse(?ResponseAeat $response): ?string
    {
        if ($response === null || ! is_object($response->response) || ! isset($response->response->EstadoEnvio)) {
            return null;
        }

        return (string) $response->response->EstadoEnvio;
    }

    private function aeatErrorDescription(?ResponseAeat $response): ?string
    {
        if ($response === null || $response->errors === []) {
            return null;
        }

        return implode(PHP_EOL, $response->errors);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoicePayload(Invoice $invoice): ?array
    {
        if (! $invoice instanceof InvoiceSubmission) {
            return null;
        }

        $recipient = null;

        try {
            $invoiceRecipient = $invoice->getRecipient();
            $recipient = [
                'name' => $invoiceRecipient->name,
                'id' => $invoiceRecipient->id,
                'country_code' => $invoiceRecipient->countryCode,
                'id_type' => $invoiceRecipient->idType->value,
            ];
        } catch (RecipientException) {
            // Simplified invoices do not carry recipient data.
        }

        return [
            'issuer' => [
                'name' => $invoice->issuer->name,
                'nif' => $invoice->issuer->id,
            ],
            'recipient' => $recipient,
            'invoice' => [
                'number' => $invoice->invoiceNumber,
                'date' => $invoice->invoiceDate->format('Y-m-d'),
                'type' => $invoice->type->value,
                'description' => $invoice->description,
                'taxable_base' => (float) $invoice->getTaxableBase(),
                'tax_amount' => (float) $invoice->getTaxAmount(),
                'total_amount' => (float) $invoice->getTotalAmount(),
                'tax_rate' => (float) $invoice->getTaxRate(),
            ],
            'tax' => [
                'operation_qualification' => isset($invoice->exemptOperation) ? null : $this->invoicePayloadOperationQualification($invoice),
                'exempt_operation' => $invoice->exemptOperation?->value,
            ],
        ];
    }

    private function invoicePayloadOperationQualification(InvoiceSubmission $invoice): ?string
    {
        try {
            return $invoice->getOperationQualification();
        } catch (InvoiceValidationException) {
            return null;
        }
    }

    /**
     * @return array{invoiceData: array<string, mixed>, options: array<string, mixed>, operationQualificationType: OperationQualificationType, recipient: ?Recipient}|ResponseAeat
     */
    private function buildRectificationInvoiceFromRecord(
        VerifactuRecord $rectifiedRecord,
        ?array $invoiceData,
        ?OperationQualificationType $operationQualificationType,
        ?Recipient $recipient,
        ?Carbon $timestamp,
    ): array|ResponseAeat {
        if ($rectifiedRecord->invoice_payload === null) {
            return $this->responseWithErrors('Rectified registry record does not contain invoice_payload.');
        }

        return $this->buildRectificationInvoiceFromPayload(
            rectifiedRecord: $rectifiedRecord,
            invoiceData: $invoiceData,
            operationQualificationType: $operationQualificationType,
            recipient: $recipient,
            timestamp: $timestamp,
        );
    }

    /**
     * @return array{invoiceData: array<string, mixed>, options: array<string, mixed>, operationQualificationType: OperationQualificationType, recipient: ?Recipient}|ResponseAeat
     */
    private function buildRectificationInvoiceFromPayload(
        VerifactuRecord $rectifiedRecord,
        ?array $invoiceData,
        ?OperationQualificationType $operationQualificationType,
        ?Recipient $recipient,
        ?Carbon $timestamp,
    ): array|ResponseAeat {
        $payload = $rectifiedRecord->invoice_payload ?? [];
        $invoicePayload = $payload['invoice'] ?? [];
        $taxPayload = $payload['tax'] ?? [];

        if (! is_array($invoicePayload) || ! is_array($taxPayload)) {
            return $this->responseWithErrors('Rectified registry record invoice_payload is invalid.');
        }

        $base = $this->negativePayloadAmount($invoicePayload['taxable_base'] ?? null);
        $tax = $this->negativePayloadAmount($invoicePayload['tax_amount'] ?? null) ?? 0.0;
        $total = $this->negativePayloadAmount($invoicePayload['total_amount'] ?? null);

        if ($base === null || $total === null) {
            return $this->responseWithErrors('Rectified registry record invoice_payload does not contain invoice amounts.');
        }

        $sourceInvoiceType = InvoiceType::tryFrom((string) ($invoicePayload['type'] ?? ''))
            ?? $rectifiedRecord->invoice_type;
        $defaultInvoiceType = $sourceInvoiceType === InvoiceType::SIMPLIFIED
            ? InvoiceType::RECTIFICATION_SIMPLIFIED
            : InvoiceType::RECTIFICATION_4;

        $data = array_merge([
            'date' => $timestamp ?? Carbon::now(),
            'description' => 'Rectification of invoice '.$rectifiedRecord->invoice_number,
            'type' => $defaultInvoiceType,
            'amount' => $total,
            'base' => $base,
            'vat' => $tax,
            'rate' => $this->payloadAmount($invoicePayload['tax_rate'] ?? null) ?? 0.0,
        ], $invoiceData ?? []);

        $validated = $this->validateRectificationInvoiceData($data);

        if ($validated instanceof ResponseAeat) {
            return $validated;
        }

        $options = [];
        $exemptOperation = is_string($taxPayload['exempt_operation'] ?? null) ? $taxPayload['exempt_operation'] : null;

        if ($exemptOperation !== null && ExemptOperationType::tryFrom($exemptOperation) !== null) {
            $options['exempt_operation'] = $exemptOperation;
        }

        $qualification = $operationQualificationType
            ?? OperationQualificationType::tryFrom(is_string($taxPayload['operation_qualification'] ?? null) ? $taxPayload['operation_qualification'] : '')
            ?? OperationQualificationType::SUBJECT_DIRECT;

        if ($recipient === null && $this->invoiceTypeFromData($data) !== InvoiceType::RECTIFICATION_SIMPLIFIED) {
            $recipient = $this->recipientFromPayload($payload);

            if ($recipient instanceof ResponseAeat) {
                return $recipient;
            }
        }

        return [
            'invoiceData' => $data,
            'options' => $options,
            'operationQualificationType' => $qualification,
            'recipient' => $recipient,
        ];
    }

    private function recipientFromPayload(array $payload): Recipient|ResponseAeat|null
    {
        $recipient = $payload['recipient'] ?? null;

        if ($recipient === null) {
            return null;
        }

        if (! is_array($recipient)) {
            return $this->responseWithErrors('Recipient data in invoice_payload is invalid.');
        }

        $idType = IdType::tryFrom(is_string($recipient['id_type'] ?? null) ? $recipient['id_type'] : '');
        $name = is_string($recipient['name'] ?? null) ? $recipient['name'] : null;
        $id = is_string($recipient['id'] ?? null) ? $recipient['id'] : null;
        $countryCode = is_string($recipient['country_code'] ?? null) ? $recipient['country_code'] : null;

        if ($idType === null || $name === null || $id === null || $countryCode === null) {
            return $this->responseWithErrors('Recipient data could not be derived from invoice_payload. Pass recipient explicitly.');
        }

        try {
            return new Recipient($name, $id, $countryCode, $idType);
        } catch (RecipientException $e) {
            return $this->responseWithErrors('Recipient cannot be derived from invoice_payload: '.$e->getMessage());
        }
    }

    private function validateRectificationInvoiceData(array $data): ?ResponseAeat
    {
        if (! isset($data['number']) || trim((string) $data['number']) === '') {
            return $this->responseWithErrors('Rectification invoice number is required in invoiceData["number"].');
        }

        if (! $this->isRectificationInvoiceType($data['type'] ?? null)) {
            return $this->responseWithErrors('Rectification invoice type must be one of R1, R2, R3, R4 or R5.');
        }

        return null;
    }

    private function invoiceTypeFromData(array $data): ?InvoiceType
    {
        $type = $data['type'] ?? null;

        return $type instanceof InvoiceType ? $type : (is_string($type) ? InvoiceType::tryFrom($type) : null);
    }

    private function negativePayloadAmount(mixed $value): ?float
    {
        $amount = $this->payloadAmount($value);

        return $amount === null ? null : -abs($amount);
    }

    private function payloadAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseJsonFromResponse(?ResponseAeat $response): ?array
    {
        if ($response === null || $response->json === null || $response->json === '') {
            return null;
        }

        $decoded = json_decode($response->json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function previousPayloadFromLatestRegistryRecord(LegalPerson $issuer): array|ResponseAeat
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        $record = VerifactuRecord::previousForChain($issuer->id, $this->settings->getRegistryScope());

        if ($record === null) {
            return $this->responseWithErrors('Previous registry record was not found in verifactu_records.');
        }

        return [
            'number' => $record->invoice_number,
            'date' => $record->invoice_date,
            'hash' => $record->hash,
        ];
    }

    private function findRegistryRecordById(?LegalPerson $issuer, VerifactuRecord|int $record): VerifactuRecord|ResponseAeat|null
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        $recordId = $this->recordKey($record);

        if ($recordId === null) {
            return null;
        }

        $query = VerifactuRecord::query()
            ->whereKey($recordId)
            ->where('registry_scope', $this->settings->getRegistryScope())
            ->where('record_type', VerifactuRecordType::ALTA->value);

        if ($issuer !== null) {
            $query->where('issuer_nif', $issuer->id);
        }

        return $query->first();
    }

    private function resolveRegistryRecord(VerifactuRecord|int $record): ?VerifactuRecord
    {
        if ($record instanceof VerifactuRecord) {
            if (! $record->exists) {
                return null;
            }

            return $record;
        }

        return VerifactuRecord::query()->whereKey($record)->first();
    }

    private function recordKey(VerifactuRecord|int $record): ?int
    {
        if (is_int($record)) {
            return $record;
        }

        $key = $record->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function findRegistryRecordByIdForCurrentScope(VerifactuRecord|int $record): VerifactuRecord|ResponseAeat|null
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        $recordId = $this->recordKey($record);

        if ($recordId === null) {
            return null;
        }

        return VerifactuRecord::query()
            ->whereKey($recordId)
            ->where('registry_scope', $this->settings->getRegistryScope())
            ->where('record_type', VerifactuRecordType::ALTA->value)
            ->first();
    }

    private function isRectificationInvoiceType(mixed $value): bool
    {
        $type = $value instanceof InvoiceType ? $value : (is_string($value) ? InvoiceType::tryFrom($value) : null);

        return in_array($type, [
            InvoiceType::RECTIFICATION_1,
            InvoiceType::RECTIFICATION_2,
            InvoiceType::RECTIFICATION_3,
            InvoiceType::RECTIFICATION_4,
            InvoiceType::RECTIFICATION_SIMPLIFIED,
        ], true);
    }

    /**
     * @return array{
     *     signature_format: string,
     *     signature_algorithm: string,
     *     signature_policy_id: string,
     *     signature_policy_url: string,
     *     signature_policy_hash: string,
     *     signature_policy_hash_algorithm: string,
     *     certificate_subject: string,
     *     certificate_issuer: string,
     *     certificate_serial_number: string,
     *     certificate_digest: string,
     *     certificate_digest_algorithm: string
     * }
     *
     * @throws CertificateException
     */
    private function signatureMetadata(): array
    {
        return [
            'signature_format' => XadesEpesSigner::SIGNATURE_FORMAT,
            'signature_algorithm' => XadesEpesSigner::SIGNATURE_ALGORITHM,
            'signature_policy_id' => XadesEpesSigner::POLICY_ID,
            'signature_policy_url' => XadesEpesSigner::POLICY_URL,
            'signature_policy_hash' => XadesEpesSigner::POLICY_HASH,
            'signature_policy_hash_algorithm' => XadesEpesSigner::POLICY_HASH_ALGORITHM,
            'certificate_subject' => $this->certificate->getSubjectName(),
            'certificate_issuer' => $this->certificate->getIssuerName(),
            'certificate_serial_number' => $this->certificate->getSerialNumber(),
            'certificate_digest' => $this->certificate->getDigest('sha1'),
            'certificate_digest_algorithm' => XadesEpesSigner::CERTIFICATE_DIGEST_ALGORITHM,
        ];
    }
}
