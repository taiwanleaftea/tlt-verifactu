<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

#[CoversNothing]
class RegistryMigrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_it_creates_registry_table_with_timestamps_at_the_end(): void
    {
        $migration = $this->migration();

        $migration->up();

        $this->assertTrue(Schema::hasTable('verifactu_records'));

        $columns = array_map(
            static fn (object $column): string => $column->name,
            DB::select('PRAGMA table_info(verifactu_records)')
        );

        $this->assertContains('request_xml', $columns);
        $this->assertContains('signed_xml', $columns);
        $this->assertContains('signed_at', $columns);
        $this->assertContains('signature_format', $columns);
        $this->assertContains('signature_algorithm', $columns);
        $this->assertContains('signature_policy_id', $columns);
        $this->assertContains('signature_policy_url', $columns);
        $this->assertContains('signature_policy_hash', $columns);
        $this->assertContains('signature_policy_hash_algorithm', $columns);
        $this->assertContains('certificate_subject', $columns);
        $this->assertContains('certificate_issuer', $columns);
        $this->assertContains('certificate_serial_number', $columns);
        $this->assertContains('certificate_digest', $columns);
        $this->assertContains('certificate_digest_algorithm', $columns);
        $this->assertContains('response_json', $columns);
        $this->assertContains('raw_response', $columns);
        $this->assertContains('registry_scope', $columns);
        $this->assertSame(['created_at', 'updated_at'], array_slice($columns, -2));

        $migration->down();
    }

    public function test_it_fails_with_clear_message_when_registry_table_already_exists(): void
    {
        Schema::create('verifactu_records', function (Blueprint $table): void {
            $table->id();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create verifactu_records table because it already exists.');

        $this->migration()->up();
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_06_25_000000_create_verifactu_records_table.php';
    }
}
