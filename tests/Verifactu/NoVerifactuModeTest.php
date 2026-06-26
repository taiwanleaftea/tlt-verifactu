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
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\CancellationRejectionStatus;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\PreviousRecordStatus;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Models\VerifactuRecord;
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
        $this->assertInstanceOf(VerifactuRecord::class, $response->registryRecord);
        $this->assertSame($response->registryRecordId, $response->registryRecord->id);
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
        $invoicePayload = json_decode($record->invoice_payload, true);
        $this->assertSame('Buyer Name', $invoicePayload['recipient']['name']);
        $this->assertEquals(100.0, $invoicePayload['invoice']['taxable_base']);
        $this->assertEquals(21.0, $invoicePayload['invoice']['tax_amount']);
        $this->assertEquals(121.0, $invoicePayload['invoice']['total_amount']);
        $this->assertSame(OperationQualificationType::SUBJECT_DIRECT->value, $invoicePayload['tax']['operation_qualification']);
        $this->assertNotEmpty($record->certificate_subject);
        $this->assertNotEmpty($record->certificate_issuer);
        $this->assertNotEmpty($record->certificate_serial_number);
        $this->assertNotEmpty($record->certificate_digest);
        $this->assertSame(XadesEpesSigner::CERTIFICATE_DIGEST_ALGORITHM, $record->certificate_digest_algorithm);
        $this->assertNotNull($record->signed_at);
    }

    public function test_submit_invoice_returns_error_when_signature_metadata_cannot_be_read(): void
    {
        Storage::fake('local');
        config()->set('tlt-verifactu.disk', 'local');
        Storage::disk('local')->put('test-certificate.p12', $this->createPkcs12Certificate('secret', '89890001K'));

        $verifactu = new Verifactu;
        $verifactu->config(new BrokenSignatureMetadataCertificate('test-certificate.p12', 'secret'));

        $response = $verifactu->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: $this->invoiceData('A-1'),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['Signature metadata cannot be read: Metadata failure'], $response->errors);
        $this->assertSame(0, DB::table('verifactu_records')->count());
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

    public function test_cancel_invoice_stores_signed_record_in_sandbox(): void
    {
        $previousHash = str_repeat('A', 64);
        $recordId = $this->insertRegistryRecord('A-1', $previousHash);

        $response = $this->configuredVerifactu()->cancelInvoice(
            record: VerifactuRecord::findOrFail($recordId),
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertSame('signed', $record->status);
        $this->assertSame(1, $record->previous_record_id);
        $this->assertStringContainsString('<sf:RegistroAnulacion', $record->request_xml);
        $this->assertStringContainsString('<ds:Signature', $record->signed_xml);
    }

    public function test_cancel_invoice_can_include_sin_registro_previo_and_rechazo_previo(): void
    {
        $previousHash = str_repeat('A', 64);
        $recordId = $this->insertRegistryRecord('A-1', $previousHash);

        $response = $this->configuredVerifactu()->cancelInvoice(
            record: $recordId,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
            options: [
                'sin_registro_previo' => PreviousRecordStatus::YES,
                'rechazo_previo' => CancellationRejectionStatus::NO,
            ],
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertStringContainsString('<sf:SinRegistroPrevio>S</sf:SinRegistroPrevio>', $record->request_xml);
        $this->assertStringContainsString('<sf:RechazoPrevio>N</sf:RechazoPrevio>', $record->request_xml);
    }

    public function test_cancel_invoice_is_disabled_in_production_by_default(): void
    {
        config()->set('tlt-verifactu.production', true);

        $response = $this->configuredVerifactu()->cancelInvoice(
            record: 1,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['Invoice cancellation is disabled in production. Set VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION=true only when RegistroAnulacion is intentionally required.'], $response->errors);
        $this->assertSame(0, DB::table('verifactu_records')->count());
    }

    public function test_cancel_invoice_can_be_enabled_in_production(): void
    {
        config()->set('tlt-verifactu.production', true);
        config()->set('tlt-verifactu.enable_cancel_invoice_in_production', true);

        $previousHash = str_repeat('A', 64);
        $recordId = $this->insertRegistryRecord('A-1', $previousHash);

        $response = $this->configuredVerifactu()->cancelInvoice(
            record: $recordId,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertSame('signed', $record->status);
    }

    public function test_cancel_invoice_by_record_id_uses_registry_record_and_latest_chain_record(): void
    {
        $firstHash = str_repeat('A', 64);
        $secondHash = str_repeat('B', 64);
        $firstRecordId = $this->insertRegistryRecord('A-1', $firstHash);
        $secondRecordId = $this->insertRegistryRecord('A-2', $secondHash);

        $response = $this->configuredVerifactu()->cancelInvoiceByRecordId(
            recordId: VerifactuRecord::findOrFail($firstRecordId),
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertSame($secondHash, $record->previous_hash);
        $this->assertSame($secondRecordId, $record->previous_record_id);
        $this->assertSame('A-1', $record->invoice_number);
        $this->assertStringContainsString('<sf:NumSerieFacturaAnulada>A-1</sf:NumSerieFacturaAnulada>', $record->request_xml);
        $this->assertStringContainsString('<sf:NumSerieFactura>A-2</sf:NumSerieFactura>', $record->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$secondHash.'</sf:Huella>', $record->request_xml);
    }

    public function test_cancel_invoice_by_record_id_keeps_separate_issuer_chains(): void
    {
        $issuerHash = str_repeat('A', 64);
        $otherIssuerHash = str_repeat('B', 64);
        $recordId = $this->insertRegistryRecord('A-1', $issuerHash);
        $this->insertRegistryRecord('B-1', $otherIssuerHash, issuerNif: '12345678L', issuerName: 'Other Issuer');

        $response = $this->configuredVerifactu()->cancelInvoiceByRecordId(
            recordId: $recordId,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertSame($issuerHash, $record->previous_hash);
        $this->assertStringContainsString('<sf:Huella>'.$issuerHash.'</sf:Huella>', $record->request_xml);
        $this->assertStringNotContainsString('<sf:Huella>'.$otherIssuerHash.'</sf:Huella>', $record->request_xml);
    }

    public function test_cancel_invoice_by_record_id_keeps_separate_registry_scope_chains(): void
    {
        config()->set('tlt-verifactu.registry_scope', 'main');

        $mainHash = str_repeat('A', 64);
        $otherScopeHash = str_repeat('B', 64);
        $recordId = $this->insertRegistryRecord('A-1', $mainHash, registryScope: 'main');
        $this->insertRegistryRecord('S-1', $otherScopeHash, registryScope: 'secondary');

        $response = $this->configuredVerifactu()->cancelInvoiceByRecordId(
            recordId: $recordId,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first();

        $this->assertTrue($response->success);
        $this->assertSame($mainHash, $record->previous_hash);
        $this->assertSame('main', $record->registry_scope);
        $this->assertStringContainsString('<sf:Huella>'.$mainHash.'</sf:Huella>', $record->request_xml);
        $this->assertStringNotContainsString('<sf:Huella>'.$otherScopeHash.'</sf:Huella>', $record->request_xml);
    }

    public function test_previous_helpers_can_resolve_chain_from_record_id(): void
    {
        config()->set('tlt-verifactu.registry_scope', 'main');

        $mainHash = str_repeat('A', 64);
        $secondaryFirstHash = str_repeat('B', 64);
        $secondaryLatestHash = str_repeat('C', 64);
        $this->insertRegistryRecord('A-1', $mainHash, registryScope: 'main');
        $secondaryFirstRecordId = $this->insertRegistryRecord('S-1', $secondaryFirstHash, registryScope: 'secondary');
        $secondaryLatestRecordId = $this->insertRegistryRecord('S-2', $secondaryLatestHash, registryScope: 'secondary');

        $verifactu = new Verifactu;
        $sourceRecord = VerifactuRecord::findOrFail($secondaryFirstRecordId);

        $this->assertSame($secondaryLatestRecordId, $verifactu->getPreviousId(recordId: $secondaryFirstRecordId));
        $this->assertSame($secondaryLatestRecordId, $verifactu->getPreviousRecordId(recordId: $sourceRecord));
        $this->assertSame($secondaryLatestHash, $verifactu->getPreviousHash(recordId: $secondaryFirstRecordId));
        $this->assertSame($secondaryLatestHash, $sourceRecord->getPreviousHash());
        $this->assertSame($secondaryLatestRecordId, $sourceRecord->getPreviousRecordId());
        $this->assertSame($secondaryLatestRecordId, $sourceRecord->getPreviousRecord()?->id);
    }

    public function test_previous_helpers_can_resolve_chain_from_issuer_and_scope(): void
    {
        $mainHash = str_repeat('A', 64);
        $secondaryHash = str_repeat('B', 64);
        $otherIssuerHash = str_repeat('C', 64);
        $mainRecordId = $this->insertRegistryRecord('A-1', $mainHash, registryScope: 'main');
        $this->insertRegistryRecord('S-1', $secondaryHash, registryScope: 'secondary');
        $this->insertRegistryRecord('B-1', $otherIssuerHash, issuerNif: '12345678L', issuerName: 'Other Issuer', registryScope: 'main');

        $verifactu = new Verifactu;

        $this->assertSame($mainRecordId, $verifactu->getPreviousId(issuerNif: '89890001K', registryScope: 'main'));
        $this->assertSame($mainRecordId, $verifactu->getPreviousRecord(issuerNif: '89890001K', registryScope: 'main')?->id);
        $this->assertSame($mainHash, $verifactu->getPreviousHash(issuerNif: '89890001K', registryScope: 'main'));
    }

    public function test_cancel_invoice_by_record_id_is_disabled_in_production_by_default(): void
    {
        config()->set('tlt-verifactu.production', true);

        $recordId = $this->insertRegistryRecord('A-1', str_repeat('A', 64));

        $response = $this->configuredVerifactu()->cancelInvoiceByRecordId(
            recordId: $recordId,
            timestamp: Carbon::parse('2026-01-02T10:00:00+01:00'),
        );

        $this->assertFalse($response->success);
        $this->assertSame(['Invoice cancellation is disabled in production. Set VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION=true only when RegistroAnulacion is intentionally required.'], $response->errors);
        $this->assertNull(DB::table('verifactu_records')->where('record_type', VerifactuRecordType::ANULACION->value)->first());
    }

    public function test_it_stores_chained_invoice_records(): void
    {
        $verifactu = $this->configuredVerifactu();
        $issuer = new LegalPerson('Issuer Name', '89890001K');
        $recipient = new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF);

        $first = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-1',
            timestamp: '2026-01-01T10:00:00+01:00',
        );

        $second = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-2',
            timestamp: '2026-01-01T10:01:00+01:00',
            previous: $this->previousPayload('A-1', $first->hash),
        );

        $third = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-3',
            timestamp: '2026-01-01T10:02:00+01:00',
            previous: $this->previousPayload('A-2', $second->hash),
        );

        $fourth = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-4',
            timestamp: '2026-01-01T10:03:00+01:00',
            previous: $this->previousPayload('A-3', $third->hash),
        );

        $this->assertTrue($fourth->success);

        $records = DB::table('verifactu_records')->orderBy('id')->get();

        $this->assertCount(4, $records);
        $this->assertSame([
            VerifactuRecordType::ALTA->value,
            VerifactuRecordType::ALTA->value,
            VerifactuRecordType::ALTA->value,
            VerifactuRecordType::ALTA->value,
        ], $records->pluck('record_type')->all());
        $this->assertSame(['A-1', 'A-2', 'A-3', 'A-4'], $records->pluck('invoice_number')->all());
        $this->assertNull($records[0]->previous_record_id);
        $this->assertSame($records[0]->id, $records[1]->previous_record_id);
        $this->assertSame($records[1]->id, $records[2]->previous_record_id);
        $this->assertSame($records[2]->id, $records[3]->previous_record_id);
        $this->assertSame($records[2]->hash, $records[3]->previous_hash);
        $this->assertTrue($records->every(fn ($record): bool => $record->status === 'signed'));
        $this->assertStringContainsString('<ds:Signature', $records[3]->signed_xml);
        $this->assertStringContainsString('<sf:NumSerieFactura>A-4</sf:NumSerieFactura>', $records[3]->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$third->hash.'</sf:Huella>', $records[3]->request_xml);
    }

    public function test_subsanate_invoice_uses_registry_for_previous_record(): void
    {
        $verifactu = $this->configuredVerifactu();
        $issuer = new LegalPerson('Issuer Name', '89890001K');
        $recipient = new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF);

        $first = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-1',
            timestamp: '2026-01-01T10:00:00+01:00',
        );

        $second = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-2',
            timestamp: '2026-01-01T10:01:00+01:00',
            previous: $this->previousPayload('A-1', $first->hash),
        );

        $response = $verifactu->subsanateInvoice(
            issuer: $issuer,
            recordId: VerifactuRecord::findOrFail($first->registryRecordId),
            invoiceData: $this->invoiceData('A-1'),
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            recipient: $recipient,
            timestamp: Carbon::parse('2026-01-01T10:02:00+01:00'),
        );

        $record = DB::table('verifactu_records')->orderByDesc('id')->first();

        $this->assertTrue($response->success);
        $this->assertSame($second->hash, $record->previous_hash);
        $this->assertSame('A-1', $record->invoice_number);
        $this->assertStringContainsString('<sf:Subsanacion>S</sf:Subsanacion>', $record->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$second->hash.'</sf:Huella>', $record->request_xml);
    }

    public function test_submit_rectification_invoice_uses_rectified_registry_record(): void
    {
        $verifactu = $this->configuredVerifactu();
        $issuer = new LegalPerson('Issuer Name', '89890001K');
        $recipient = new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF);

        $first = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-1',
            timestamp: '2026-01-01T10:00:00+01:00',
        );

        $second = $this->submitNoVerifactuInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-2',
            timestamp: '2026-01-01T10:01:00+01:00',
            previous: $this->previousPayload('A-1', $first->hash),
        );

        $response = $verifactu->submitRectificationInvoice(
            rectifiedRecordId: VerifactuRecord::findOrFail($first->registryRecordId),
            invoiceData: ['number' => 'R-1'],
            timestamp: Carbon::parse('2026-01-01T10:02:00+01:00'),
        );

        $record = DB::table('verifactu_records')->orderByDesc('id')->first();

        $this->assertTrue($response->success);
        $this->assertSame($second->hash, $record->previous_hash);
        $this->assertSame('R-1', $record->invoice_number);
        $this->assertSame(InvoiceType::RECTIFICATION_4->value, $record->invoice_type);
        $this->assertStringContainsString('<sf:TipoFactura>R4</sf:TipoFactura>', $record->request_xml);
        $this->assertStringContainsString('<sf:TipoRectificativa>I</sf:TipoRectificativa>', $record->request_xml);
        $this->assertStringContainsString('<sf:IDFacturaRectificada>', $record->request_xml);
        $this->assertStringContainsString('<sf:NumSerieFactura>A-1</sf:NumSerieFactura>', $record->request_xml);
        $this->assertStringContainsString('<sf:BaseImponibleOimporteNoSujeto>-100.00</sf:BaseImponibleOimporteNoSujeto>', $record->request_xml);
        $this->assertStringContainsString('<sf:CuotaRepercutida>-21.00</sf:CuotaRepercutida>', $record->request_xml);
        $this->assertStringContainsString('<sf:ImporteTotal>-121.00</sf:ImporteTotal>', $record->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$second->hash.'</sf:Huella>', $record->request_xml);
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

    private function invoiceData(string $number, InvoiceType $type = InvoiceType::STANDARD): array
    {
        return [
            'number' => $number,
            'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            'description' => 'Invoice description',
            'type' => $type,
            'amount' => 121,
            'base' => 100,
            'vat' => 21,
            'rate' => 21,
        ];
    }

    private function insertRegistryRecord(
        string $number,
        string $hash,
        string $issuerNif = '89890001K',
        string $issuerName = 'Issuer Name',
        ?string $registryScope = null,
    ): int {
        return (int) DB::table('verifactu_records')->insertGetId([
            'registry_scope' => $registryScope,
            'issuer_nif' => $issuerNif,
            'issuer_name' => $issuerName,
            'invoice_number' => $number,
            'invoice_date' => '2026-01-01',
            'record_type' => VerifactuRecordType::ALTA->value,
            'status' => 'signed',
            'hash' => $hash,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function submitNoVerifactuInvoice(
        Verifactu $verifactu,
        LegalPerson $issuer,
        Recipient $recipient,
        string $number,
        string $timestamp,
        ?array $previous = null,
    ): ResponseAeat {
        return $verifactu->submitInvoice(
            issuer: $issuer,
            invoiceData: $this->invoiceData($number),
            options: [],
            operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
            previous: $previous,
            recipient: $recipient,
            timestamp: Carbon::parse($timestamp),
        );
    }

    private function previousPayload(string $number, ?string $hash): array
    {
        return [
            'number' => $number,
            'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            'hash' => $hash,
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

class BrokenSignatureMetadataCertificate extends Certificate
{
    public function getSubjectNif(): ?string
    {
        return '89890001K';
    }

    /**
     * @throws CertificateException
     */
    public function getSubjectName(): string
    {
        throw new CertificateException('Metadata failure');
    }
}
