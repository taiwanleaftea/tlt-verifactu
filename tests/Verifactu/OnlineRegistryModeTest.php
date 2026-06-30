<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SoapClient;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Services\XadesEpesSigner;
use Taiwanleaftea\TltVerifactu\Support\Verifactu;

#[CoversClass(Verifactu::class)]
#[CoversClass(VerifactuSettings::class)]
#[CoversClass(VerifactuMode::class)]
#[CoversClass(XadesEpesSigner::class)]
class OnlineRegistryModeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('tlt-verifactu.mode', VerifactuMode::ONLINE->value);
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

    public function test_submit_invoice_sends_online_and_stores_registry_record_with_signed_copy(): void
    {
        $soapClient = new FakeVerifactuSoapClient((object) [
            'CSV' => 'CSV123456789',
            'EstadoEnvio' => 'Correcto',
            'DatosPresentacion' => (object) [
                'TimestampPresentacion' => '2026-01-01T10:00:05+01:00',
            ],
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => EstadoRegistro::ACCEPTED->value,
            ],
        ]);

        $response = $this->configuredVerifactu($soapClient)->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'description' => 'Invoice description',
                'type' => InvoiceType::STANDARD,
                'amount' => 121,
                'base' => 100,
                'vat' => 21,
                'rate' => 21,
            ],
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->first();

        $this->assertTrue($response->success);
        $this->assertFalse($response->storedOnly);
        $this->assertSame(1, $response->registryRecordId);
        $this->assertSame(1, $response->registryRecord?->id);
        $this->assertSame('CSV123456789', $response->csv);
        $this->assertSame(AEAT::WSDL_SANDBOX, $soapClient->wsdl);
        $this->assertSame(AEAT::URL_SANDBOX, $soapClient->options['location']);
        $this->assertSame('RegFactuSistemaFacturacion', $soapClient->calls[0]['name']);
        $this->assertStringContainsString('<sfLR:RegFactuSistemaFacturacion', (string) $response->request);
        $this->assertStringNotContainsString('<ds:Signature', (string) $response->request);
        $this->assertStringContainsString('<ds:Signature', (string) $response->signedRequest);

        $this->assertSame('accepted', $record->status);
        $this->assertSame('Correcto', $record->estado_envio);
        $this->assertSame('Correcto', $record->estado_registro);
        $this->assertSame('CSV123456789', $record->csv);
        $this->assertSame('<soap-response/>', $record->raw_response);
        $this->assertSame('Correcto', json_decode($record->response_json, true)['EstadoEnvio']);
        $this->assertStringContainsString('<sf:RegistroAlta', $record->request_xml);
        $this->assertStringNotContainsString('<ds:Signature', $record->request_xml);
        $this->assertStringContainsString('<ds:Signature', $record->signed_xml);
        $this->assertNotNull($record->sent_at);
        $this->assertNotNull($record->accepted_at);
    }

    public function test_submit_invoice_uses_production_wsdl_and_location_when_production_is_enabled(): void
    {
        config()->set('tlt-verifactu.production', true);

        $soapClient = new FakeVerifactuSoapClient((object) [
            'CSV' => 'CSV123456789',
            'EstadoEnvio' => 'Correcto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => EstadoRegistro::ACCEPTED->value,
            ],
        ]);

        $response = $this->configuredVerifactu($soapClient)->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'description' => 'Invoice description',
                'type' => InvoiceType::STANDARD,
                'amount' => 121,
                'base' => 100,
                'vat' => 21,
                'rate' => 21,
            ],
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertTrue($response->success);
        $this->assertSame(AEAT::WSDL, $soapClient->wsdl);
        $this->assertSame(AEAT::URL_PRODUCTION, $soapClient->options['location']);
    }

    public function test_submit_invoice_does_not_store_registry_record_when_aeat_rejects_record(): void
    {
        $soapClient = new FakeVerifactuSoapClient((object) [
            'EstadoEnvio' => 'Incorrecto',
            'RespuestaLinea' => (object) [
                'EstadoRegistro' => EstadoRegistro::NOT_ACCEPTED->value,
                'CodigoErrorRegistro' => 1234,
                'DescripcionErrorRegistro' => 'Rejected by AEAT sandbox',
            ],
        ]);

        $response = $this->configuredVerifactu($soapClient)->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'description' => 'Invoice description',
                'type' => InvoiceType::STANDARD,
                'amount' => 121,
                'base' => 100,
                'vat' => 21,
                'rate' => 21,
            ],
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(EstadoRegistro::NOT_ACCEPTED, $response->status);
        $this->assertSame(['Error 1234: Rejected by AEAT sandbox'], $response->errors);
        $this->assertNull($response->registryRecordId);
        $this->assertNull($response->registryRecord);
        $this->assertStringContainsString('<sfLR:RegFactuSistemaFacturacion', (string) $response->request);
        $this->assertStringContainsString('<ds:Signature', (string) $response->signedRequest);
        $this->assertSame(0, DB::table('verifactu_records')->count());
    }

    public function test_generate_qr_uri_uses_production_url_when_production_is_enabled(): void
    {
        config()->set('tlt-verifactu.production', true);

        $uri = (new Verifactu)->generateQrURI(
            issuerNIF: '89890001K',
            invoiceDate: Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            number: 'A-1',
            totalAmount: 121,
        );

        $this->assertStringStartsWith(AEAT::QR_VERIFICATION_PRODUCTION, $uri);
    }

    public function test_submit_invoice_returns_error_when_soap_client_cannot_be_created(): void
    {
        Storage::fake('local');
        config()->set('tlt-verifactu.disk', 'local');
        Storage::disk('local')->put('test-certificate.p12', $this->createPkcs12Certificate('secret'));

        $verifactu = new FakeFailingOnlineRegistryVerifactu;
        $verifactu->config(new Certificate('test-certificate.p12', 'secret'));

        $response = $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'description' => 'Invoice description',
                'type' => InvoiceType::STANDARD,
                'amount' => 121,
                'base' => 100,
                'vat' => 21,
                'rate' => 21,
            ],
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['SOAP client error: SOAP unavailable'], $response->errors);
        $this->assertSame(0, DB::table('verifactu_records')->count());
    }

    private function configuredVerifactu(FakeVerifactuSoapClient $soapClient): FakeOnlineRegistryVerifactu
    {
        Storage::fake('local');
        config()->set('tlt-verifactu.disk', 'local');
        Storage::disk('local')->put('test-certificate.p12', $this->createPkcs12Certificate('secret'));

        $verifactu = new FakeOnlineRegistryVerifactu($soapClient);
        $verifactu->config(new Certificate('test-certificate.p12', 'secret'));

        return $verifactu;
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
}

class FakeOnlineRegistryVerifactu extends Verifactu
{
    public function __construct(private FakeVerifactuSoapClient $soapClient)
    {
        parent::__construct();
    }

    protected function createSoapClient(string $wsdl, array $options): SoapClient
    {
        $this->soapClient->wsdl = $wsdl;
        $this->soapClient->options = $options;

        return $this->soapClient;
    }
}

class FakeFailingOnlineRegistryVerifactu extends Verifactu
{
    /**
     * @throws SoapClientException
     */
    protected function createSoapClient(string $wsdl, array $options): SoapClient
    {
        throw new SoapClientException('SOAP unavailable');
    }
}

class FakeVerifactuSoapClient extends SoapClient
{
    public array $calls = [];

    public ?string $wsdl = null;

    public array $options = [];

    public function __construct(private object $response) {}

    public function __soapCall(string $name, array $args, ?array $options = null, $inputHeaders = null, &$outputHeaders = null): mixed
    {
        $this->calls[] = [
            'name' => $name,
            'args' => $args,
        ];

        return $this->response;
    }

    public function __getLastResponse(): ?string
    {
        return '<soap-response/>';
    }

    public function __getLastRequest(): ?string
    {
        return '<soap-request/>';
    }

    public function __getLastRequestHeaders(): ?string
    {
        return '';
    }
}
