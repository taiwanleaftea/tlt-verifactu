<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;
use Taiwanleaftea\TltVerifactu\Services\XadesEpesSigner;

#[CoversClass(XadesEpesSigner::class)]
class XadesEpesSignatureTest extends TestCase
{
    public function test_sign_xml_generates_xades_epes_signature_for_registry_record(): void
    {
        Storage::fake('local');
        config()->set('tlt-verifactu.disk', 'local');

        Storage::disk('local')->put('test-certificate.p12', $this->createPkcs12Certificate('secret'));

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
        $invoice->setRecipient(new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF));
        $invoice->setOperationQualification(OperationQualificationType::SUBJECT_DIRECT);

        $submitInvoice = new SubmitInvoice(new VerifactuSettings);
        $submitInvoice->getXml($invoice);
        $dom = $submitInvoice->signXml(new Certificate('test-certificate.p12', 'secret'));
        $xml = $dom->saveXML();

        $this->assertStringContainsString('<ds:Signature', $xml);
        $this->assertStringContainsString('<xades:SignedProperties', $xml);
        $this->assertStringContainsString('<xades:SignaturePolicyIdentifier>', $xml);
        $this->assertStringContainsString(XadesEpesSigner::POLICY_ID, $xml);
        $this->assertStringContainsString(XadesEpesSigner::POLICY_URL, $xml);
        $this->assertStringContainsString(XadesEpesSigner::POLICY_HASH, $xml);
        $this->assertStringContainsString('Type="http://uri.etsi.org/01903#SignedProperties"', $xml);
        $this->assertStringContainsString('ObjectReference="#xmldsig-', $xml);
        $this->assertSame('RegistroAlta', $dom->documentElement->localName);

        $xpath = $this->xpath($dom);
        $this->assertSame('', $xpath->evaluate('string(/sf:RegistroAlta/ds:Signature/ds:SignedInfo/ds:Reference[1]/@URI)'));
        $this->assertSame('http://www.w3.org/2000/09/xmldsig#enveloped-signature', $xpath->evaluate('string(/sf:RegistroAlta/ds:Signature/ds:SignedInfo/ds:Reference[1]/ds:Transforms/ds:Transform/@Algorithm)'));
        $this->assertSame('http://uri.etsi.org/01903#SignedProperties', $xpath->evaluate('string(/sf:RegistroAlta/ds:Signature/ds:SignedInfo/ds:Reference[2]/@Type)'));

        $signature = new XMLSecurityDSig;
        $signature->locateSignature($dom);
        $signedPropertiesReference = $xpath->query('/sf:RegistroAlta/ds:Signature/ds:SignedInfo/ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $signedPropertiesReference);
        $this->assertTrue($signature->processRefNode($signedPropertiesReference));
    }

    private function createPkcs12Certificate(string $password): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new([
            'commonName' => 'Issuer Name',
            'countryName' => 'ES',
            'organizationName' => 'Issuer Org',
            'serialNumber' => 'IDCES-89890001K',
        ], $privateKey);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, serial: 123456789);

        openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password);

        return $pkcs12;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sf', 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SuministroInformacion.xsd');
        $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        return $xpath;
    }
}
