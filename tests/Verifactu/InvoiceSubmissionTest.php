<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use DOMDocument;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;

#[CoversClass(InvoiceSubmission::class)]
class InvoiceSubmissionTest extends TestCase
{
    private $xsd;

    private string $recipientName = 'Buyer Inc.';

    private string $recipientId = '12345678L';

    public function test_standard_es_recipient_es_provider()
    {
        $settings = new VerifactuSettings;

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

        $dom = (new SubmitInvoice($settings))->getXml($invoice);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML StandardESRecipientESProvider validation failed.'.PHP_EOL.$validation['errors']);
    }

    public function test_verifactu_envelope_does_not_add_xml_signature()
    {
        $settings = new VerifactuSettings;
        $issuer = new LegalPerson('Issuer Name', '89890001K');

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
            Carbon::parse('2024-01-01T19:20:30+01:00')
        );

        $invoice->setRecipient(new Recipient($this->recipientName, $this->recipientId, 'ES', IdType::NIF));
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $submitInvoice = new SubmitInvoice($settings);
        $submitInvoice->getXml($invoice);
        $dom = $submitInvoice->createEnvelopedXml($issuer);

        $this->assertStringContainsString('<sfLR:RegFactuSistemaFacturacion', $dom->saveXML());
        $this->assertStringNotContainsString('<ds:Signature', $dom->saveXML());
    }

    public function test_simplified_es_provider()
    {
        $settings = new VerifactuSettings;

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

        $dom = (new SubmitInvoice($settings))->getXml($invoice);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML testSimplifiedESProvider validation failed.'.PHP_EOL.$validation['errors']);
    }

    public function test_standard_nones_recipient_nones_provider()
    {
        $settings = new VerifactuSettings;

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
            IdType::NIF
        );

        $invoice->setRecipient($recipient);
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $dom = (new SubmitInvoice($settings))->getXml($invoice);
        $validation = $this->validateXml($dom);
        $this->assertTrue($validation['result'], 'XML testStandardNONESRecipientNONESProvider validation failed.'.PHP_EOL.$validation['errors']);
    }

    private function validateXml(DOMDocument $dom): array
    {
        libxml_use_internal_errors(true);
        $errors = '';
        $xsd = $this->getXSDPath();

        if (empty($xsd)) {
            $this->markTestSkipped('AEAT XSD is not available.');
        }

        $validation = $dom->schemaValidate($xsd);
        if ($validation === false) {
            foreach (libxml_get_errors() as $error) {
                $errors .= $error->message.PHP_EOL;
            }
        }

        return [
            'result' => $validation,
            'errors' => $errors,
        ];
    }

    private function getXSDPath(): false|string
    {
        if (! isset($this->xsd)) {
            $path = __DIR__.'/Fixtures/xsd/SuministroInformacion.xsd';

            if (! is_file($path)) {
                return false;
            }

            $this->xsd = $path;
        }

        return $this->xsd;
    }
}
