<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;
use Taiwanleaftea\TltVerifactu\Services\XadesEpesSigner;
use Taiwanleaftea\TltVerifactu\Support\Verifactu;

#[CoversClass(Verifactu::class)]
#[CoversClass(VerifactuSettings::class)]
#[CoversClass(VerifactuMode::class)]
#[CoversClass(XadesEpesSigner::class)]
class NoVerifactuModeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('tlt-verifactu.mode', VerifactuMode::NO_VERIFACTU->value);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/2026_06_25_000000_create_verifactu_records_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('verifactu_records');

        parent::tearDown();
    }

    public function test_submit_invoice_requires_signing_certificate(): void
    {
        $response = (new Verifactu)->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['NO VERIFACTU mode requires a signing certificate. Call config() before storing signed records.'], $response->errors);
        $this->assertSame(0, DB::table('verifactu_records')->count());
    }

    public function test_submit_invoice_stores_signed_record(): void
    {
        $verifactu = $this->configuredVerifactu();

        $response = $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->first();

        $this->assertTrue($response->success);
        $this->assertNotNull($response->signedRequest);
        $this->assertSame('signed', $record->status);
        $this->assertStringContainsString('<sf:RegistroAlta', $record->request_xml);
        $this->assertStringNotContainsString('<ds:Signature', $record->request_xml);
        $this->assertStringContainsString('<ds:Signature', $record->signed_xml);
        $this->assertStringContainsString('<xades:SignedProperties', $record->signed_xml);
        $this->assertSame(XadesEpesSigner::SIGNATURE_FORMAT, $record->signature_format);
        $this->assertSame(XadesEpesSigner::SIGNATURE_ALGORITHM, $record->signature_algorithm);
        $this->assertSame(XadesEpesSigner::POLICY_ID, $record->signature_policy_id);
        $this->assertSame(XadesEpesSigner::POLICY_URL, $record->signature_policy_url);
        $this->assertSame(XadesEpesSigner::POLICY_HASH, $record->signature_policy_hash);
        $this->assertSame(XadesEpesSigner::POLICY_HASH_ALGORITHM, $record->signature_policy_hash_algorithm);
        $this->assertNotEmpty($record->certificate_subject);
        $this->assertNotEmpty($record->certificate_issuer);
        $this->assertNotEmpty($record->certificate_serial_number);
        $this->assertNotEmpty($record->certificate_digest);
        $this->assertSame(XadesEpesSigner::CERTIFICATE_DIGEST_ALGORITHM, $record->certificate_digest_algorithm);
        $this->assertNotNull($record->signed_at);
    }

    public function test_submit_invoice_rejects_certificate_with_different_nif(): void
    {
        $verifactu = $this->configuredVerifactu('12345678L');

        $response = $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['Certificate NIF 12345678L does not match issuer NIF 89890001K. Set allow_representative_certificate to true only when using an authorized representative certificate.'], $response->errors);
        $this->assertSame(0, DB::table('verifactu_records')->count());
    }

    public function test_submit_invoice_accepts_representative_certificate_when_enabled(): void
    {
        config()->set('tlt-verifactu.allow_representative_certificate', true);

        $response = $this->configuredVerifactu('12345678L')->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->first();

        $this->assertTrue($response->success);
        $this->assertSame('signed', $record->status);
        $this->assertStringContainsString('<ds:Signature', $record->signed_xml);
    }

    public function test_online_mode_rejects_certificate_with_different_nif_before_soap_call(): void
    {
        config()->set('tlt-verifactu.mode', VerifactuMode::ONLINE->value);

        $verifactu = $this->configuredVerifactu('12345678L');

        $response = $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['Certificate NIF 12345678L does not match issuer NIF 89890001K. Set allow_representative_certificate to true only when using an authorized representative certificate.'], $response->errors);
    }

    public function test_cancel_invoice_stores_signed_record(): void
    {
        $previousHash = str_repeat('A', 64);

        DB::table('verifactu_records')->insert([
            'issuer_nif' => '89890001K',
            'invoice_number' => 'A-1',
            'invoice_date' => '2026-01-01',
            'record_type' => 'alta',
            'status' => 'signed',
            'hash' => $previousHash,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = $this->configuredVerifactu()->cancelInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            ],
            previous: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'hash' => $previousHash,
            ],
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', 'anulacion')->first();

        $this->assertTrue($response->success);
        $this->assertSame('signed', $record->status);
        $this->assertSame(1, $record->previous_record_id);
        $this->assertStringContainsString('<sf:RegistroAnulacion', $record->request_xml);
        $this->assertStringContainsString('<ds:Signature', $record->signed_xml);
    }

    private function configuredVerifactu(string $certificateNif = '89890001K'): Verifactu
    {
        Storage::fake('local');
        config()->set('tlt-verifactu.disk', 'local');
        Storage::disk('local')->put('test-certificate.p12', $this->createPkcs12Certificate('secret', $certificateNif));

        $verifactu = new Verifactu;
        $verifactu->config(new Certificate('test-certificate.p12', 'secret'));

        return $verifactu;
    }

    private function invoiceData(string $number): array
    {
        return [
            'number' => $number,
            'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            'description' => 'Invoice description',
            'type' => InvoiceType::STANDARD,
            'amount' => 121,
            'base' => 100,
            'vat' => 21,
            'rate' => 21,
        ];
    }

    private function createPkcs12Certificate(string $password, string $nif): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new([
            'commonName' => 'Issuer Name',
            'countryName' => 'ES',
            'organizationName' => 'Issuer Org',
            'serialNumber' => 'IDCES-'.$nif,
        ], $privateKey);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, serial: 123456789);

        openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password);

        return $pkcs12;
    }
}
