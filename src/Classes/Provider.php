<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Constants\Verifactu;
use Taiwanleaftea\TltVerifactu\Enums\IdType;

/**
 * Datos del sistema informático de facturación utilizado.
 */
class Provider
{
    public string $providerName;
    public string $providerId;
    public string $countryCode;
    public IdType $idType;
    public string $systemName;
    public string $systemId;
    public string $systemVersion;
    public int $installationNumber;
    public bool $verifactuOnly;
    public bool $multipleTaxpayers;
    public bool $singleTaxpayerMode;

    public function __construct(
        string $providerName,
        string $providerId,
        string $countryCode,
        IdType $idType,
        string $systemName,
        string $systemId,
        string $systemVersion,
        int $installationNumber,
        bool $verifactuOnly,
        bool $multipleTaxpayers,
        bool $singleTaxpayerMode
    )
    {
        $this->providerName = Str::trim($providerName);
        $this->providerId = Str::trim($providerId);
        $this->countryCode = Str::of($countryCode)->trim()->upper()->toString();
        $this->idType = $idType;
        $this->systemName = Str::trim($systemName);
        $this->systemId = Str::trim($systemId);
        $this->systemVersion = Str::trim($systemVersion);
        $this->installationNumber = $installationNumber;
        $this->verifactuOnly = $verifactuOnly;
        $this->multipleTaxpayers = $multipleTaxpayers;
        $this->singleTaxpayerMode = $singleTaxpayerMode;
    }

    /**
     * Check if provider is domestic
     *
     * @return bool
     */
    public function isDomestic(): bool
    {
        return $this->countryCode == 'ES';
    }

    /**
     * TipoUsoPosibleSoloVerifactu
     *
     * @return string
     */
    public function getVerifactuOnly(): string
    {
        return $this->verifactuOnly ? Verifactu::YES : Verifactu::NO;
    }

    /**
     * TipoUsoPosibleMultiOT
     *
     * @return string
     */
    public function getMultipleTaxpayers(): string
    {
        return $this->multipleTaxpayers ? Verifactu::YES : Verifactu::NO;
    }

    /**
     * IndicadorMultiplesOT
     *
     * @return string
     */
    public function getSingleTaxpayerMode(): string
    {
        return $this->singleTaxpayerMode ?  Verifactu::NO : Verifactu::YES;
    }
}
