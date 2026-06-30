<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuRecordVariant;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verifactu_records')) {
            throw new RuntimeException('Cannot create verifactu_records table because it already exists. Rename the existing table or disable the TLT Verifactu registry migration.');
        }

        Schema::create('verifactu_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('previous_record_id')->nullable()->index();
            $table->string('registry_scope', 80)->nullable()->index();
            $table->string('issuer_nif', 20)->index();
            $table->string('issuer_name', 120)->nullable();
            $table->string('invoice_number', 60)->index();
            $table->date('invoice_date');
            $table->enum('record_type', VerifactuRecordType::values())->index();
            $table->enum('record_variant', VerifactuRecordVariant::values())->nullable()->index();
            $table->enum('invoice_type', InvoiceType::values())->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_id', 40)->nullable()->index();
            $table->string('recipient_country_code', 2)->nullable();
            $table->enum('recipient_id_type', IdType::values())->nullable();
            $table->string('status', 40)->nullable()->index();
            $table->string('estado_envio', 40)->nullable();
            $table->string('estado_registro', 40)->nullable();
            $table->string('hash', 64)->nullable()->unique();
            $table->string('previous_hash', 64)->nullable();
            $table->longText('request_xml')->nullable();
            $table->longText('signed_xml')->nullable();
            $table->json('invoice_payload')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_format', 30)->nullable();
            $table->string('signature_algorithm', 80)->nullable();
            $table->string('signature_policy_id', 100)->nullable();
            $table->text('signature_policy_url')->nullable();
            $table->string('signature_policy_hash', 128)->nullable();
            $table->string('signature_policy_hash_algorithm', 40)->nullable();
            $table->text('certificate_subject')->nullable();
            $table->text('certificate_issuer')->nullable();
            $table->string('certificate_serial_number', 120)->nullable();
            $table->string('certificate_digest', 128)->nullable();
            $table->string('certificate_digest_algorithm', 40)->nullable();
            $table->json('response_json')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('csv', 32)->nullable();
            $table->text('qr_url')->nullable();
            $table->unsignedInteger('aeat_error_code')->nullable();
            $table->text('aeat_error_description')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifactu_records');
    }
};
