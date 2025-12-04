<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use DOMException;
use Illuminate\Support\Carbon;
use SoapFault;
use SoapVar;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\Generator;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceCancellation;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\EstadoEnvio;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Exceptions\GeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\InvoiceValidationException;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Services\CancelInvoice;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;
use Taiwanleaftea\TltVerifactu\Services\QRCode;
use Taiwanleaftea\TltVerifactu\Services\Soap;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;
use Taiwanleaftea\TltVerifactu\Traits\CheckArray;

class Verifactu
{
    use CheckArray;
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
     * @param OperationQualificationType $operationQualificationType
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
        OperationQualificationType $operationQualificationType = OperationQualificationType::SUBJECT_DIRECT,
        ?array $previous = null,
        ?Recipient $recipient = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat
    {
        if (is_null($timestamp)) {
            $timestamp = Carbon::now();
        }

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
            timestamp: $timestamp,
        );

        if (!$invoice->isSimplified()) {
            if (is_null($recipient)) {
                return $this->responseWithErrors('Recipient object is missing.');
            } else {
                $invoice->setRecipient($recipient);
            }

            if ($operationQualificationType == OperationQualificationType::SUBJECT_REVERSE && !VatValidator::isEU($recipient->countryCode)) {
                return $this->responseWithErrors('Recipient must be from EU to apply reverse charge.');
            } else {
                $invoice->setOperationQualification($operationQualificationType);
            }
        } else {
            $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);
        }

        if ($previous !== null) {
            $keys = ['number', 'date', 'hash'];
            if (!$this->checkArray($keys, $previous)) {
                return $this->responseWithErrors('Previous invoice key is missing.');
            } else {
                $invoice->setPreviousInvoice(
                    number: $previous['number'],
                    date: $previous['date'],
                    hash: $previous['hash'],
                );
            }
        }

        if (!empty($options)) {
            try {
                $invoice->setOptions($options);
            } catch (InvoiceValidationException $e) {
                return $this->responseWithErrors('Invoice cannot be validated (getXml): ' . $e->getMessage());
            }
        }

        if ($invoice->isRectificado() && $invoice->getOption('rectificado') === null) {
            return $this->responseWithErrors('Credit note (factura rectificada) does not contain necessary data (submitInvoice).');
        }

        $submission = new SubmitInvoice($this->settings);

        try {
            $submission->getXml($invoice);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be created (getXml): ' . $e->getMessage());
        } catch (InvoiceValidationException $e) {
            return $this->responseWithErrors('Invoice cannot be validated (getXml): ' . $e->getMessage());
        } catch (RecipientException $e) {
            return $this->responseWithErrors('Recipient cannot be validated (getXml): ' . $e->getMessage());
        }

        try {
            $submission->signXml($this->certificate);
        } catch (CertificateException|\Exception $e) {
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
        $response->hash = $invoice->hash();
        $response->csv = $soapResponse->CSV ?? null;
        $response->json = json_encode($soapResponse, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);
        $response->timestamp = $timestamp;

        if (!isset($soapResponse->EstadoEnvio)) {
            $response->errors[] = 'EstadoEnvio has not been received.';
            $response->errors[] = 'Last SOAP response: ' . $soapClient->__getLastResponse();
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
                $response->errors[] = 'Error ' . $soapResponse->RespuestaLinea->CodigoErrorRegistro . ': ' . $soapResponse->RespuestaLinea->DescripcionErrorRegistro;
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

        return $response;
    }

    /**
     * @param LegalPerson $issuer
     * @param array $invoiceData
     * @param array $previous
     * @param Generator|null $generator
     * @param Carbon|null $timestamp
     *
     * @return ResponseAeat
     *
     * @throws CertificateException
     */
    public function cancelInvoice(
        LegalPerson $issuer,
        array $invoiceData,
        array $previous,
        ?Generator $generator = null,
        ?Carbon $timestamp = null,
    ): ResponseAeat
    {
        if (is_null($timestamp)) {
            $timestamp = Carbon::now();
        }

        $keys = ['number', 'date'];
        if (($key = $this->checkArray($keys, $invoiceData)) !== true) {
            return $this->responseWithErrors('Invoice cancellation key ' . $key . ' is missing.');
        }

        $invoice = new InvoiceCancellation(
            issuer: $issuer,
            invoiceNumber: $invoiceData['number'],
            invoiceDate: $invoiceData['date'],
            timestamp: $timestamp,
        );

        if ($previous !== null) {
            $keys = ['number', 'date', 'hash'];
            if (!$this->checkArray($keys, $previous)) {
                return $this->responseWithErrors('Previous invoice key is missing.');
            } else {
                $invoice->setPreviousInvoice(
                    number: $previous['number'],
                    date: $previous['date'],
                    hash: $previous['hash'],
                );
            }
        }

        if ($generator !== null) {
            $invoice->setGenerator($generator);
        }

        $cancellation = new CancelInvoice($this->settings);

        try {
            $cancellation->getXml($invoice);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be created (getXml): ' . $e->getMessage());
        } catch (InvoiceValidationException $e) {
            return $this->responseWithErrors('Invoice cancellation cannot be validated (getXml): ' . $e->getMessage());
        } catch (GeneratorException $e) {
            return $this->responseWithErrors('Invoice cancellation generator cannot be set (getXml): ' . $e->getMessage());
        }

        try {
            $cancellation->signXml($this->certificate);
        } catch (CertificateException|\Exception $e) {
            return $this->responseWithErrors('XML cannot be signed: ' . $e->getMessage());
        }

        try {
            $envelopedDom = $cancellation->createEnvelopedXml($issuer);
        } catch (DOMException $e) {
            return $this->responseWithErrors('XML cannot be enveloped: ' . $e->getMessage());
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
        $response->hash = $invoice->hash();
        $response->csv = $soapResponse->CSV ?? null;
        $response->json = json_encode($soapResponse, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);
        $response->timestamp = $timestamp;

        if (!isset($soapResponse->EstadoEnvio)) {
            $response->errors[] = 'EstadoEnvio has not been received.';
            $response->errors[] = 'Last SOAP response: ' . $soapClient->__getLastResponse();
        } elseif ($soapResponse->EstadoEnvio == EstadoEnvio::ACCEPTED->value) {
            $response->success = true;
            if (isset($soapResponse->RespuestaLinea->EstadoRegistro)) {
                $response->status = EstadoRegistro::tryFrom($soapResponse->RespuestaLinea->EstadoRegistro);
            }
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
                $response->errors[] = 'Error ' . $soapResponse->RespuestaLinea->CodigoErrorRegistro . ': ' . $soapResponse->RespuestaLinea->DescripcionErrorRegistro;
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

        return $response;
    }

    /**
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     *
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

    /**
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     *
     * @return string
     *
     * @throws QRGeneratorException
     */
    public function generateQrPNG(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string
    {
        return QRCode::PNG($issuerNIF, $invoiceDate, $number, $totalAmount, $this->settings->isProduction());
    }

    /**
     * @param string $issuerNIF
     * @param Carbon $invoiceDate
     * @param string $number
     * @param float $totalAmount
     *
     * @return string
     */
    public function generateQrURI(
        string $issuerNIF,
        Carbon $invoiceDate,
        string $number,
        float $totalAmount,
    ): string
    {
        return QRCode::buildUrl($issuerNIF, $invoiceDate, $number, $totalAmount);
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
