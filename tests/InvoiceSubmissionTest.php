<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\Verifactu;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Helpers\SubmitInvoice;

#[CoversClass(InvoiceSubmission::class)]
class InvoiceSubmissionTest extends TestCase
{
    private $xsd;
    private string $recipientName = 'Buyer Inc.';
    private string $recipientId = '12345678L';

    public function testStandardESRecipientESProvider()
    {
        $settings = new VerifactuSettings();

        $issuer = new LegalPerson(
            'Issuer Name',
            '89890001K',
        );

        $invoice = new InvoiceSubmission(
            $issuer,
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

        $recipient = new Recipient(
            $this->recipientName,
            $this->recipientId,
            'ES',
            IdType::NIF
        );

        $invoice->setRecipient($recipient);
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $settings);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML StandardESRecipientESProvider validation failed.' . PHP_EOL . $validation['errors']);
    }

    public function testSimplifiedESProvider()
    {
        $settings = new VerifactuSettings();

        $issuer = new LegalPerson(
            'Issuer Name',
            '89890001K',
        );

        $invoice = new InvoiceSubmission(
            $issuer,
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

        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $settings);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML testSimplifiedESProvider validation failed.' . PHP_EOL . $validation['errors']);
    }

    public function testStandardNONESRecipientNONESProvider()
    {
        $settings = new VerifactuSettings();

        $issuer = new LegalPerson(
            'Issuer Name',
            '89890001K',
        );

        $invoice = new InvoiceSubmission(
            $issuer,
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

        $recipient = new Recipient(
            $this->recipientName,
            $this->recipientId,
            'AT',
            IdType::NATIONAL_ID
        );

        $invoice->setRecipient($recipient);
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = SubmitInvoice::getXml($invoice, $settings);
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
