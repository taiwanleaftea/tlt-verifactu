<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceCancellation;
use Taiwanleaftea\TltVerifactu\Classes\InvoiceSubmission;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Classes\ResponseAeat;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;
use Taiwanleaftea\TltVerifactu\Services\CancelInvoice;
use Taiwanleaftea\TltVerifactu\Services\SubmitInvoice;
use Taiwanleaftea\TltVerifactu\Support\Verifactu;

#[CoversClass(Verifactu::class)]
#[CoversClass(VerifactuSettings::class)]
#[CoversClass(VerifactuMode::class)]
#[CoversClass(ResponseAeat::class)]
#[CoversClass(InvoiceSubmission::class)]
#[CoversClass(InvoiceCancellation::class)]
#[CoversClass(SubmitInvoice::class)]
#[CoversClass(CancelInvoice::class)]
class RegistryModeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('tlt-verifactu.mode', VerifactuMode::REGISTRY->value);
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

    public function test_submit_invoice_stores_record_without_certificate_or_soap(): void
    {
        $response = (new Verifactu)->submitInvoice(
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
        $this->assertTrue($response->storedOnly);
        $this->assertNotNull($response->registryRecordId);
        $this->assertStringContainsString('<sf:RegistroAlta', (string) $response->request);

        $record = DB::table('verifactu_records')->first();

        $this->assertSame('alta', $record->record_type);
        $this->assertSame('F1', $record->invoice_type);
        $this->assertSame('stored', $record->status);
        $this->assertSame($response->hash, $record->hash);
        $this->assertSame('A-1', $record->invoice_number);
        $this->assertNull($record->registry_scope);
        $this->assertStringContainsString('<sf:Huella>'.$response->hash.'</sf:Huella>', $record->request_xml);
        $this->assertNotEmpty($record->qr_url);
    }

    public function test_registry_scope_is_stored_and_isolates_previous_record_lookup(): void
    {
        config()->set('tlt-verifactu.registry_scope', 'main-backend');

        $previousHash = str_repeat('B', 64);

        DB::table('verifactu_records')->insert([
            'registry_scope' => 'other-backend',
            'issuer_nif' => '89890001K',
            'invoice_number' => 'A-1',
            'invoice_date' => '2026-01-01',
            'record_type' => 'alta',
            'status' => 'stored',
            'hash' => $previousHash,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = (new Verifactu)->submitInvoice(
            issuer: new LegalPerson('Issuer Name', '89890001K'),
            invoiceData: [
                'number' => 'A-2',
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
            previous: $this->previousPayload('A-1', $previousHash),
            recipient: new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF),
            timestamp: Carbon::parse('2026-01-01T10:01:00+01:00'),
        );

        $record = DB::table('verifactu_records')->where('invoice_number', 'A-2')->first();

        $this->assertTrue($response->success);
        $this->assertSame('main-backend', $record->registry_scope);
        $this->assertNull($record->previous_record_id);
        $this->assertSame($previousHash, $record->previous_hash);
    }

    public function test_cancel_invoice_stores_record_linked_by_previous_hash(): void
    {
        $previousHash = str_repeat('A', 64);

        DB::table('verifactu_records')->insert([
            'issuer_nif' => '89890001K',
            'invoice_number' => 'A-1',
            'invoice_date' => '2026-01-01',
            'record_type' => 'alta',
            'status' => 'stored',
            'hash' => $previousHash,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $response = (new Verifactu)->cancelInvoice(
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

        $this->assertTrue($response->success);
        $this->assertTrue($response->storedOnly);

        $record = DB::table('verifactu_records')->where('record_type', 'anulacion')->first();

        $this->assertSame(1, $record->previous_record_id);
        $this->assertSame($previousHash, $record->previous_hash);
        $this->assertSame('anulacion', $record->record_type);
        $this->assertNull($record->invoice_type);
        $this->assertStringContainsString('<sf:RegistroAnulacion', $record->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$previousHash.'</sf:Huella>', $record->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$response->hash.'</sf:Huella>', $record->request_xml);
    }

    public function test_it_stores_chain_with_three_invoices_cancellation_and_next_invoice(): void
    {
        $verifactu = new Verifactu;
        $issuer = new LegalPerson('Issuer Name', '89890001K');
        $recipient = new Recipient('Buyer Name', '12345678L', 'ES', IdType::NIF);

        $first = $this->submitRegistryInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-1',
            timestamp: '2026-01-01T10:00:00+01:00',
        );

        $second = $this->submitRegistryInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-2',
            timestamp: '2026-01-01T10:01:00+01:00',
            previous: $this->previousPayload('A-1', $first->hash),
        );

        $third = $this->submitRegistryInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-3',
            timestamp: '2026-01-01T10:02:00+01:00',
            previous: $this->previousPayload('A-2', $second->hash),
        );

        $cancellation = $verifactu->cancelInvoice(
            issuer: $issuer,
            invoiceData: [
                'number' => 'A-2',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
            ],
            previous: $this->previousPayload('A-3', $third->hash),
            timestamp: Carbon::parse('2026-01-01T10:03:00+01:00'),
        );

        $fourth = $this->submitRegistryInvoice(
            verifactu: $verifactu,
            issuer: $issuer,
            recipient: $recipient,
            number: 'A-4',
            timestamp: '2026-01-01T10:04:00+01:00',
            previous: [
                'number' => 'A-2',
                'date' => Carbon::createFromFormat('d-m-Y', '01-01-2026'),
                'hash' => $cancellation->hash,
            ],
        );

        $this->assertTrue($cancellation->success);
        $this->assertTrue($fourth->success);

        $records = DB::table('verifactu_records')->orderBy('id')->get();

        $this->assertCount(5, $records);
        $this->assertSame(['alta', 'alta', 'alta', 'anulacion', 'alta'], $records->pluck('record_type')->all());
        $this->assertSame(['A-1', 'A-2', 'A-3', 'A-2', 'A-4'], $records->pluck('invoice_number')->all());
        $this->assertNull($records[0]->previous_record_id);
        $this->assertSame($records[0]->id, $records[1]->previous_record_id);
        $this->assertSame($records[1]->id, $records[2]->previous_record_id);
        $this->assertSame($records[2]->id, $records[3]->previous_record_id);
        $this->assertSame($records[3]->id, $records[4]->previous_record_id);
        $this->assertSame($records[3]->hash, $records[4]->previous_hash);
        $this->assertStringContainsString('<sf:RegistroAnulacion', $records[3]->request_xml);
        $this->assertStringContainsString('<sf:NumSerieFactura>A-2</sf:NumSerieFactura>', $records[4]->request_xml);
        $this->assertStringContainsString('<sf:Huella>'.$cancellation->hash.'</sf:Huella>', $records[4]->request_xml);
    }

    private function submitRegistryInvoice(
        Verifactu $verifactu,
        LegalPerson $issuer,
        Recipient $recipient,
        string $number,
        string $timestamp,
        ?array $previous = null,
    ): ResponseAeat {
        return $verifactu->submitInvoice(
            issuer: $issuer,
            invoiceData: [
                'number' => $number,
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
}
