<?php

namespace Taiwanleaftea\TltVerifactu\Test\Verifactu;

use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Classes\VerifactuSettings;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;

#[CoversClass(VerifactuSettings::class)]
#[CoversClass(VerifactuMode::class)]
class VerifactuSettingsTest extends TestCase
{
    public function test_default_mode_sends_records_online(): void
    {
        $settings = new VerifactuSettings;

        $this->assertSame(VerifactuMode::ONLINE, $settings->getMode());
        $this->assertTrue($settings->sendsRecordsOnline());
        $this->assertFalse($settings->storesRecordsOnly());
        $this->assertNull($settings->getRegistryScope());
        $this->assertFalse($settings->signsOnlineRecords());
        $this->assertFalse($settings->allowsRepresentativeCertificate());
        $this->assertFalse($settings->enablesCancelInvoiceInProduction());
        $this->assertSame(AEAT::URL_SANDBOX, $settings->getVerifactuServiceUrl());
        $this->assertSame(AEAT::WSDL_SANDBOX, $settings->getVerifactuWsdlUrl());
        $this->assertSame(AEAT::QR_VERIFICATION_SANDBOX, $settings->getQrCheckUrl());
    }

    public function test_production_mode_uses_production_service_wsdl_and_qr_urls(): void
    {
        config()->set('tlt-verifactu.production', true);

        $settings = new VerifactuSettings;

        $this->assertSame(AEAT::URL_PRODUCTION, $settings->getVerifactuServiceUrl());
        $this->assertSame(AEAT::WSDL, $settings->getVerifactuWsdlUrl());
        $this->assertSame(AEAT::QR_VERIFICATION_PRODUCTION, $settings->getQrCheckUrl());
    }

    public function test_no_verifactu_mode_stores_and_signs_records(): void
    {
        config()->set('tlt-verifactu.mode', 'no_verifactu');

        $settings = new VerifactuSettings;

        $this->assertSame(VerifactuMode::NO_VERIFACTU, $settings->getMode());
        $this->assertFalse($settings->sendsRecordsOnline());
        $this->assertTrue($settings->storesRecordsOnly());
        $this->assertTrue($settings->signsStoredRecords());
        $this->assertSame(AEAT::QR_NO_VERIFACTU_SANDBOX, $settings->getQrCheckUrl());
    }

    public function test_no_verifactu_production_mode_uses_no_verifactu_qr_url(): void
    {
        config()->set('tlt-verifactu.mode', 'no_verifactu');
        config()->set('tlt-verifactu.production', true);

        $settings = new VerifactuSettings;

        $this->assertSame(AEAT::QR_NO_VERIFACTU_PRODUCTION, $settings->getQrCheckUrl());
    }

    public function test_invalid_mode_fails_with_clear_message(): void
    {
        config()->set('tlt-verifactu.mode', 'registry');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VERIFACTU mode must be "online" or "no_verifactu".');

        new VerifactuSettings;
    }

    public function test_registry_scope_can_be_configured(): void
    {
        config()->set('tlt-verifactu.registry_scope', 'main-backend');

        $settings = new VerifactuSettings;

        $this->assertSame('main-backend', $settings->getRegistryScope());
    }

    public function test_online_record_signing_can_be_enabled(): void
    {
        config()->set('tlt-verifactu.online_sign_records', true);

        $settings = new VerifactuSettings;

        $this->assertTrue($settings->signsOnlineRecords());
    }

    public function test_representative_certificate_can_be_enabled(): void
    {
        config()->set('tlt-verifactu.allow_representative_certificate', true);

        $settings = new VerifactuSettings;

        $this->assertTrue($settings->allowsRepresentativeCertificate());
    }

    public function test_cancel_invoice_can_be_enabled_in_production(): void
    {
        config()->set('tlt-verifactu.enable_cancel_invoice_in_production', true);

        $settings = new VerifactuSettings;

        $this->assertTrue($settings->enablesCancelInvoiceInProduction());
    }

    public function test_provider_id_type_can_be_configured(): void
    {
        config()->set('tlt-verifactu.provider_id_type', IdType::PASSPORT);

        $settings = new VerifactuSettings;

        $this->assertSame(IdType::PASSPORT, $settings->getInformationSystem()->provider->idType);
    }

    public function test_legacy_provider_certificate_type_is_still_supported(): void
    {
        config()->offsetUnset('tlt-verifactu.provider_id_type');
        config()->set('tlt-verifactu.provider_certificate_type', IdType::OTHER);

        $settings = new VerifactuSettings;

        $this->assertSame(IdType::OTHER, $settings->getInformationSystem()->provider->idType);
    }
}
