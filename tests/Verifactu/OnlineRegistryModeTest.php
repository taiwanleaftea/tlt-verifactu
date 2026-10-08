<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use SoapClient;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
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

    #[DataProvider('soapTimeoutCases')]
    public function test_aeat_timeouts_apply_to_wsdl_and_call_and_are_always_restored(
        string $operation,
        ?array $timeouts,
        int $expectedConnect,
        int $expectedRead,
        string $outcome,
    ): void {
        config()->set('tlt-verifactu.aeat', $timeouts ?? []);
        config()->set('tlt-verifactu.vies', ['connect_timeout' => 9, 'read_timeout' => 11]);
        $originalTimeout = ini_set('default_socket_timeout', '83');
        $creationCount = 0;

        $soapClient = new FakeVerifactuSoapClient((object) [
            'EstadoEnvio' => 'Correcto',
            'RespuestaLinea' => (object) ['EstadoRegistro' => EstadoRegistro::ACCEPTED->value],
        ]);
        $soapClient->onCreate = function (array $options) use (&$creationCount, $expectedConnect, $expectedRead, $outcome): void {
            $creationCount++;
            $this->assertSame((string) $expectedRead, ini_get('default_socket_timeout'));
            $this->assertSame($expectedConnect, $options['connection_timeout']);
            $this->assertSame($expectedRead, stream_context_get_options($options['stream_context'])['http']['timeout']);
            $this->assertTrue($options['exceptions']);
            $this->assertNotEmpty($options['local_cert']);
            $this->assertSame('secret', $options['passphrase']);

            if ($outcome === 'creation_fault') {
                throw new SoapClientException('WSDL timed out');
            }

            if ($outcome === 'unexpected_creation_error') {
                throw new RuntimeException('Unexpected SOAP error');
            }
        };
        $soapClient->onCall = function () use ($expectedRead, $outcome): void {
            $this->assertSame((string) $expectedRead, ini_get('default_socket_timeout'));

            if ($outcome === 'call_fault') {
                throw new SoapFault('HTTP', 'Error Fetching http headers');
            }

            if ($outcome === 'unexpected_call_error') {
                throw new RuntimeException('Unexpected SOAP error');
            }
        };

        try {
            try {
                $response = $this->sendForTimeoutTest($this->configuredVerifactu($soapClient), $operation);
                $this->assertNotContains($outcome, ['unexpected_creation_error', 'unexpected_call_error']);
                $this->assertSame($outcome === 'success', $response->success);

                if ($outcome === 'creation_fault') {
                    $this->assertSame(['SOAP client error: WSDL timed out'], $response->errors);
                } elseif ($outcome === 'call_fault') {
                    $this->assertSame('SOAP call failed: Error Fetching http headers', $response->errors[0]);
                    $this->assertStringContainsString('<sfLR:RegFactuSistemaFacturacion', (string) $response->request);
                }

                $initialRecords = $operation === 'cancellation' ? 1 : 0;
                $this->assertSame($initialRecords + ($outcome === 'success' ? 1 : 0), DB::table('verifactu_records')->count());
            } catch (RuntimeException $e) {
                $this->assertContains($outcome, ['unexpected_creation_error', 'unexpected_call_error']);
                $this->assertSame('Unexpected SOAP error', $e->getMessage());
            }

            $this->assertSame('83', ini_get('default_socket_timeout'));
            $this->assertSame(1, $creationCount);
            $this->assertCount(in_array($outcome, ['creation_fault', 'unexpected_creation_error'], true) ? 0 : 1, $soapClient->calls);
        } finally {
            if ($originalTimeout !== false) {
                ini_set('default_socket_timeout', $originalTimeout);
            }
        }
    }

    public static function soapTimeoutCases(): iterable
    {
        foreach (['registration', 'cancellation'] as $operation) {
            yield $operation.' defaults' => [$operation, null, 5, 20, 'success'];
            yield $operation.' configured' => [$operation, ['connect_timeout' => 3, 'read_timeout' => 7], 3, 7, 'success'];
            yield $operation.' minimum' => [$operation, ['connect_timeout' => 0, 'read_timeout' => -1], 1, 1, 'success'];

            foreach (['creation_fault', 'call_fault', 'unexpected_creation_error', 'unexpected_call_error'] as $outcome) {
                yield $operation.' '.$outcome => [$operation, ['connect_timeout' => 3, 'read_timeout' => 7], 3, 7, $outcome];
            }
        }
    }

    private function sendForTimeoutTest(Verifactu $verifactu, string $operation): ResponseAeat
    {
        if ($operation === 'cancellation') {
            $recordId = DB::table('verifactu_records')->insertGetId([
                'issuer_nif' => '89890001K',
                'issuer_name' => 'Issuer Name',
                'invoice_number' => 'A-1',
                'invoice_date' => '2026-01-01',
                'record_type' => 'alta',
                'status' => 'accepted',
                'hash' => str_repeat('A', 64),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $verifactu->cancelInvoice(record: (int) $recordId, timestamp: Carbon::parse('2026-01-01T10:01:00+01:00'));
        }

        return $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-1',
                'date' => Carbon::parse('2026-01-01'),
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
        $this->soapClient->onCreate?->__invoke($options);

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

    public ?Closure $onCreate = null;

    public ?Closure $onCall = null;

    public function __construct(private object $response) {}

    public function __soapCall(string $name, array $args, ?array $options = null, $inputHeaders = null, &$outputHeaders = null): mixed
    {
        $this->calls[] = [
            'name' => $name,
            'args' => $args,
        ];

        $this->onCall?->__invoke();

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
