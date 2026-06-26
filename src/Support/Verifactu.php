<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use DOMDocument;
use DOMException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
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

    public function __construct()
    {
        $this->settings = new VerifactuSettings;
    }

    public function config(Certificate $certificate): void
    {
        $this->certificate = $certificate;
    }

    public function getPreviousId(?int $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?int
    {
        $record = $this->previousRegistryRecord($recordId, $issuerNif, $registryScope);

        return $record === null ? null : (int) $record->id;
    }

    public function getPreviousHash(?int $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?string
    {
        $record = $this->previousRegistryRecord($recordId, $issuerNif, $registryScope);

        return $record === null ? null : (string) $record->hash;
    }

    /**
     * Submit a corrected RegistroAlta for an accepted/accepted-with-errors invoice record.
     *
     * @throws CertificateException
     */
    public function subsanateInvoice(
        LegalPerson $issuer,
        int $recordId,
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
     * Submit a factura rectificativa using the rectified invoice data stored in the local registry.
     *
     * @throws CertificateException
     */
    public function submitRectificationInvoice(
        LegalPerson $issuer,
        array $invoiceData,
        int $rectifiedRecordId,
        OperationQualificationType $operationQualificationType = OperationQualificationType::SUBJECT_DIRECT,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat {
        if (! $this->isRectificationInvoiceType($invoiceData['type'] ?? null)) {
            return $this->responseWithErrors('Rectification invoice type must be one of R1, R2, R3, R4 or R5.');
        }

        $rectifiedRecord = $this->findRegistryRecordById($issuer, $rectifiedRecordId);

        if ($rectifiedRecord instanceof ResponseAeat) {
            return $rectifiedRecord;
        }

        if ($rectifiedRecord === null) {
            return $this->responseWithErrors('Invoice registry record was not found in verifactu_records.');
        }

        $previous = $this->previousPayloadFromLatestRegistryRecord($issuer);

        if ($previous instanceof ResponseAeat) {
            return $previous;
        }

        return $this->submitInvoice(
            issuer: $issuer,
            invoiceData: $invoiceData,
            options: [
                'rectificado' => [
                    'invoice_number' => $rectifiedRecord->invoice_number,
                    'invoice_date' => Carbon::parse($rectifiedRecord->invoice_date),
                    'simplified' => $rectifiedRecord->invoice_type === InvoiceType::SIMPLIFIED->value,
                ],
            ],
            operationQualificationType: $operationQualificationType,
            previous: $previous,
            recipient: $recipient,
            timestamp: $timestamp,
        );
    }

    /**
     * Invoice submission service
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
                recordType: 'alta',
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
        $response->timestamp = $timestamp;

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
            recordType: 'alta',
            requestXml: $registroXml,
            signedXml: $signedXml,
            signedAt: $signedAt,
            invoiceType: $invoice->type->value,
            qrUri: $response->qrURI,
            aeatResponse: $response,
        );
    }

    /**
     * Cancel an invoice using the local registry record id as the source of invoice and issuer data.
     *
     * @throws CertificateException
     */
    public function cancelInvoiceByRecordId(
        int $recordId,
        ?Generator $generator = null,
        ?Carbon $timestamp = null,
        array $options = [],
    ): ResponseAeat {
        $record = $this->findRegistryRecordByIdForCurrentScope($recordId);

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

        return $this->cancelInvoice(
            issuer: $issuer,
            invoiceData: [
                'number' => $record->invoice_number,
                'date' => Carbon::parse($record->invoice_date),
            ],
            previous: $previous,
            generator: $generator,
            timestamp: $timestamp,
            options: $options,
        );
    }

    /**
     * Invoice cancellation fallback for sandbox and explicitly enabled production use.
     *
     * @throws CertificateException
     */
    public function cancelInvoice(
        LegalPerson $issuer,
        array $invoiceData,
        array $previous,
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

        $keys = ['number', 'date'];
        if (($key = $this->checkArray($keys, $invoiceData)) !== true) {
            return $this->responseWithErrors('Invoice cancellation key '.$key.' is missing.');
        }

        $invoice = new InvoiceCancellation(
            issuer: $issuer,
            invoiceNumber: $invoiceData['number'],
            invoiceDate: $invoiceData['date'],
            timestamp: $timestamp,
        );

        $keys = ['number', 'date', 'hash'];
        if (! $this->checkArray($keys, $previous)) {
            return $this->responseWithErrors('Previous invoice key is missing.');
        }

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
                recordType: 'anulacion',
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
        $response->timestamp = $timestamp;

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
            recordType: 'anulacion',
            requestXml: $registroXml,
            signedXml: $signedXml,
            signedAt: $signedAt,
            aeatResponse: $response,
        );
    }

    public function generateQrSVG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string {
        return QRCode::SVG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    /**
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
        string $recordType,
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
        $signatureMetadata = $signedXml === null ? [] : $this->signatureMetadata();
        $previousRecordId = $previousHash
            ? DB::table('verifactu_records')
                ->where('registry_scope', $registryScope)
                ->where('issuer_nif', $invoice->issuer->id)
                ->where('hash', $previousHash)
                ->value('id')
            : null;

        $recordId = DB::table('verifactu_records')->insertGetId([
            'recordable_type' => null,
            'recordable_id' => null,
            'previous_record_id' => $previousRecordId,
            'registry_scope' => $registryScope,
            'issuer_nif' => $invoice->issuer->id,
            'issuer_name' => $invoice->issuer->name,
            'invoice_number' => $invoice->invoiceNumber,
            'invoice_date' => $invoice->invoiceDate->format('Y-m-d'),
            'record_type' => $recordType,
            'invoice_type' => $invoiceType,
            'status' => $this->storedRecordStatus($signedXml, $aeatResponse),
            'estado_envio' => $this->estadoEnvioFromResponse($aeatResponse),
            'estado_registro' => $aeatResponse?->status?->value ?? $aeatResponse?->statusRaw,
            'hash' => $hash,
            'previous_hash' => $previousHash,
            'request_xml' => $requestXml,
            'signed_xml' => $signedXml,
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
            'response_json' => $aeatResponse?->json ?: null,
            'raw_response' => $aeatResponse?->rawResponse,
            'csv' => $aeatResponse?->csv,
            'qr_url' => $qrUri,
            'aeat_error_code' => $aeatResponse?->aeatErrorCode,
            'aeat_error_description' => $this->aeatErrorDescription($aeatResponse),
            'sent_at' => $aeatResponse === null ? null : Carbon::now(),
            'accepted_at' => $aeatResponse?->success ? $aeatResponse->timestamp : null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        if ($aeatResponse !== null) {
            $aeatResponse->registryRecordId = (int) $recordId;
            $aeatResponse->signedRequest = $signedXml;

            return $aeatResponse;
        }

        $response = new ResponseAeat;
        $response->success = true;
        $response->storedOnly = true;
        $response->registryRecordId = (int) $recordId;
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

    private function previousPayloadFromLatestRegistryRecord(LegalPerson $issuer): array|ResponseAeat
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        $record = $this->previousRegistryRecord(issuerNif: $issuer->id, registryScope: $this->settings->getRegistryScope());

        if ($record === null) {
            return $this->responseWithErrors('Previous registry record was not found in verifactu_records.');
        }

        return [
            'number' => $record->invoice_number,
            'date' => Carbon::parse($record->invoice_date),
            'hash' => $record->hash,
        ];
    }

    private function findRegistryRecordById(LegalPerson $issuer, int $recordId): \stdClass|ResponseAeat|null
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        return DB::table('verifactu_records')
            ->where('id', $recordId)
            ->where('registry_scope', $this->settings->getRegistryScope())
            ->where('issuer_nif', $issuer->id)
            ->where('record_type', 'alta')
            ->first();
    }

    private function previousRegistryRecord(?int $recordId = null, ?string $issuerNif = null, ?string $registryScope = null): ?\stdClass
    {
        if (! Schema::hasTable('verifactu_records')) {
            return null;
        }

        if ($recordId !== null) {
            $sourceRecord = DB::table('verifactu_records')
                ->where('id', $recordId)
                ->first(['issuer_nif', 'registry_scope']);

            if ($sourceRecord === null) {
                return null;
            }

            $issuerNif = (string) $sourceRecord->issuer_nif;
            $registryScope = $sourceRecord->registry_scope === null ? null : (string) $sourceRecord->registry_scope;
        }

        if ($issuerNif === null || trim($issuerNif) === '') {
            return null;
        }

        return DB::table('verifactu_records')
            ->where('registry_scope', $registryScope)
            ->where('issuer_nif', $issuerNif)
            ->whereNotNull('hash')
            ->orderByDesc('id')
            ->first();
    }

    private function findRegistryRecordByIdForCurrentScope(int $recordId): \stdClass|ResponseAeat|null
    {
        if (! Schema::hasTable('verifactu_records')) {
            return $this->responseWithErrors('VERIFACTU local registry requires the verifactu_records table. Run the package migrations before using registry-backed invoice operations.');
        }

        return DB::table('verifactu_records')
            ->where('id', $recordId)
            ->where('registry_scope', $this->settings->getRegistryScope())
            ->where('record_type', 'alta')
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
