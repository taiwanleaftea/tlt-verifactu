<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;

class VerifactuSettings
{
    public const string VERSION = '2.2.0';

    public const string SYSTEM_ID = '01';

    private InformationSystem $informationSystem;

    private VerifactuMode $mode;

    private ?string $registryScope;

    private bool $onlineSignRecords;

    private bool $allowRepresentativeCertificate;

    private bool $production;

    private bool $enableCancelInvoiceInProduction;

    public function __construct()
    {
        $providerIdType = config('tlt-verifactu.provider_id_type')
            ?? config('tlt-verifactu.provider_certificate_type', IdType::NIF);

        $provider = new LegalPerson(
            config('tlt-verifactu.provider_name', 'Software Provider Ltd'),
            config('tlt-verifactu.provider_nif', '12312367X'),
            config('tlt-verifactu.provider_country', 'ES'),
            $providerIdType,
        );

        $this->informationSystem = new InformationSystem(
            $provider,
            config('tlt-verifactu.system_name', 'Invoicing Software'),
            self::SYSTEM_ID,
            self::VERSION,
            config('tlt-verifactu.installation_number', 1),
            config('tlt-verifactu.verifactu_only', true),
            config('tlt-verifactu.multiple_tax_payers', true),
            config('tlt-verifactu.single_tax_payer_mode', false)
        );

        $this->production = config('tlt-verifactu.production', false);
        $this->mode = VerifactuMode::fromConfig(config('tlt-verifactu.mode', VerifactuMode::ONLINE->value));

        $registryScope = config('tlt-verifactu.registry_scope');
        $this->registryScope = $registryScope === null || $registryScope === ''
            ? null
            : (string) $registryScope;

        $this->onlineSignRecords = (bool) config('tlt-verifactu.online_sign_records', false);
        $this->allowRepresentativeCertificate = (bool) config('tlt-verifactu.allow_representative_certificate', false);
        $this->enableCancelInvoiceInProduction = (bool) config('tlt-verifactu.enable_cancel_invoice_in_production', false);
    }

    /**
     * Get information system info
     */
    public function getInformationSystem(): InformationSystem
    {
        return $this->informationSystem;
    }

    /**
     * Check if in production mode
     */
    public function isProduction(): bool
    {
        return $this->production;
    }

    /**
     * Get VERIFACTU operating mode
     */
    public function getMode(): VerifactuMode
    {
        return $this->mode;
    }

    /**
     * Check if records should be sent to AEAT immediately
     */
    public function sendsRecordsOnline(): bool
    {
        return $this->mode->sendsRecordsOnline();
    }

    /**
     * Check if records should only be stored in the local registry
     */
    public function storesRecordsOnly(): bool
    {
        return $this->mode->storesRecordsOnly();
    }

    /**
     * Check if locally stored records must be XAdES-EPES signed
     */
    public function signsStoredRecords(): bool
    {
        return $this->mode->signsStoredRecords();
    }

    /**
     * Get local registry chain scope
     */
    public function getRegistryScope(): ?string
    {
        return $this->registryScope;
    }

    /**
     * Check if online VERIFACTU records should be XAdES-EPES signed before SOAP submission
     */
    public function signsOnlineRecords(): bool
    {
        return $this->onlineSignRecords;
    }

    /**
     * Check if an authorized representative certificate can be used for issuer records
     */
    public function allowsRepresentativeCertificate(): bool
    {
        return $this->allowRepresentativeCertificate;
    }

    /**
     * Check if cancelInvoice/RegistroAnulacion is explicitly enabled in production
     */
    public function enablesCancelInvoiceInProduction(): bool
    {
        return $this->enableCancelInvoiceInProduction;
    }

    /**
     * Get VERIFACTU Service Url
     */
    public function getVerifactuServiceUrl(): string
    {
        return $this->production ? AEAT::URL_PRODUCTION : AEAT::URL_SANDBOX;
    }

    /**
     * Get VERIFACTU WSDL Url
     */
    public function getVerifactuWsdlUrl(): string
    {
        return $this->production ? AEAT::WSDL : AEAT::WSDL_SANDBOX;
    }

    /**
     * Get QR Check Url
     */
    public function getQrCheckUrl(): string
    {
        return $this->production ? AEAT::QR_VERIFICATION_PRODUCTION : AEAT::QR_VERIFICATION_SANDBOX;
    }
}
