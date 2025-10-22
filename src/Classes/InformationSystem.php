<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Constants\Verifactu;

/**
 * Datos del sistema informático de facturación utilizado.
 */
class InformationSystem
{
    public LegalPerson $provider;
    public string $name;
    public string $id;
    public string $version;
    public int $installationNumber;
    public bool $verifactuOnly;
    public bool $multipleTaxpayers;
    public bool $singleTaxpayerMode;

    public function __construct(
        LegalPerson $provider,
        string $name,
        string $id,
        string $version,
        int $installationNumber,
        bool $verifactuOnly,
        bool $multipleTaxpayers,
        bool $singleTaxpayerMode
    )
    {
        $this->provider = $provider;
        $this->name = Str::trim($name);
        $this->id = Str::trim($id);
        $this->version = Str::trim($version);
        $this->installationNumber = $installationNumber;
        $this->verifactuOnly = $verifactuOnly;
        $this->multipleTaxpayers = $multipleTaxpayers;
        $this->singleTaxpayerMode = $singleTaxpayerMode;
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
