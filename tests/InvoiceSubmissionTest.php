<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\Provider;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Constants\Verifactu;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Helpers\SubmitInvoice;

class InvoiceSubmissionTest extends TestCase
{
    private $xsd;
    private string $providerName = 'Software Ltd.';
    private string $providerId = '89890001K';
    private string $systemName = 'Invoicing Software';
    private string $systemId = '01';
    private string $systemVersion = '1.0';
    private int $installationNumber = 10;
    private bool $verifactuOnly  = false;
    private bool $multipleTaxpayers = true;
    private bool $singleTaxpayerMode = false;
    private string $recipientName = 'Buyer Inc.';
    private string $recipientId = '12345678L';

    public function testStandardESRecipientESProvider()
    {
        $invoice = new InvoiceSubmission(
            '89890001K',
            'Issuer Name',
            '12345678/G33',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'Description',
            InvoiceType::STANDARD,
            21,
            110,
            12.35,
            123.45,
            Carbon::now()
        );

        $provider = new Provider(
            $this->providerName,
            $this->providerId,
            'ES',
            IdType::NIF,
            $this->systemName,
            $this->systemId,
            $this->systemVersion,
            $this->installationNumber,
            $this->verifactuOnly,
            $this->multipleTaxpayers,
            $this->singleTaxpayerMode,
        );

        $recipient = new Recipient(
            $this->recipientName,
            'ES',
            $this->recipientId,
            IdType::NIF
        );

        $invoice->setRecipient($recipient);
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $provider);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML StandardESRecipientESProvider validation failed.' . PHP_EOL . $validation['errors']);
    }

    public function testSimplifiedESProvider()
    {
        $invoice = new InvoiceSubmission(
            '89890001K',
            'Issuer Name',
            '12345678/G33',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'Description',
            InvoiceType::SIMPLIFIED,
            21,
            110,
            12.35,
            123.45,
            Carbon::now()
        );

        $provider = new Provider(
            $this->providerName,
            $this->providerId,
            'ES',
            IdType::NIF,
            $this->systemName,
            $this->systemId,
            $this->systemVersion,
            $this->installationNumber,
            $this->verifactuOnly,
            $this->multipleTaxpayers,
            $this->singleTaxpayerMode,
        );

        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $provider);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML testSimplifiedESProvider validation failed.' . PHP_EOL . $validation['errors']);
    }

    public function testStandardNONESRecipientNONESProvider()
    {
        $invoice = new InvoiceSubmission(
            '89890001K',
            'Issuer Name',
            '12345678/G33',
            Carbon::createFromFormat('d-m-Y', '01-01-2024'),
            'Description',
            InvoiceType::STANDARD,
            21,
            110,
            12.35,
            123.45,
            Carbon::now()
        );

        $provider = new Provider(
            $this->providerName,
            $this->providerId,
            'DE',
            IdType::NIF,
            $this->systemName,
            $this->systemId,
            $this->systemVersion,
            $this->installationNumber,
            $this->verifactuOnly,
            $this->multipleTaxpayers,
            $this->singleTaxpayerMode,
        );

        $recipient = new Recipient(
            $this->recipientName,
            'AT',
            $this->recipientId,
            IdType::NATIONAL_ID
        );

        $invoice->setRecipient($recipient);
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $provider);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML testStandardNONESRecipientNONESProvider validation failed.' . PHP_EOL . $validation['errors']);
    }

    private function validateXml(DOMDocument $dom): array
    {
        libxml_use_internal_errors(true);
        $errors = '';
        $validation = $dom->schemaValidateSource($this->getXSD(Verifactu::SF_NAMESPACE));
        if ($validation === false) {
            foreach (libxml_get_errors() as $error) {
                $errors .= $error->message . PHP_EOL;
            }
        }

        return [
            'result' => $validation,
            'errors' => $errors
        ];
    }

    private function getXSD(string $url): false|string
    {
        if (!isset($this->xsd)) {
            try {
                $response = Http::get($url);
            } catch (ConnectionException $e) {
                return false;
            }

            if ($response->failed()) {
                return false;
            } else {
                $this->xsd = $response->body();
            }
        }

        return $this->xsd;
    }
}
