<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Constants\Verifactu;
use Taiwanleaftea\TltVerifactu\Enums\IdType;

class VerifactuSettings
{
    public const string VERSION = '0.1.0';
    public const string SYSTEM_ID = '01';
    private InformationSystem $informationSystem;
    private bool $production;

    public function __construct()
    {
        $provider = new LegalPerson(
            config('tlt-verifactu.provider_name', 'Software Provider Ltd'),
            config('tlt-verifactu.provider_nif', '12312367X'),
            config('tlt-verifactu.provider_country', 'ES'),
            config('tlt-verifactu.provider_certificate_type', IdType::NIF),
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
    }

    /**
     * Get information system info
     *
     * @return InformationSystem
     */
    public function getInformationSystem(): InformationSystem
    {
        return $this->informationSystem;
    }

    /**
     * Check if in production mode
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this->production;
    }

    /**
     * Get VERIFACTU Service Url
     *
     * @return string
     */
    public function getVerifactuServiceUrl(): string
    {
        return $this->production ? Verifactu::URL_PRODUCTION : Verifactu::URL_SANDBOX;
    }

    /**
     * Get QR Check Url
     *
     * @return string
     */
    public function getQrCheckUrl(): string
    {
        return $this->production ? Verifactu::QR_VERIFICATION_PRODUCTION : Verifactu::QR_VERIFICATION_SANDBOX;
    }
}
