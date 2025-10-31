<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;

class LegalPerson
{
    public string $name;
    public string $id;
    public string $countryCode;
    public IdType $idType;

    public function __construct(
        string $name,
        string $id,
        string $countryCode = 'ES',
        IdType $idType = IdType::NIF
    )
    {
        $this->name = $name;
        $this->id = $id;
        $this->countryCode = Str::of($countryCode)->trim()->upper()->toString();
        $this->idType = $idType;
    }

    /**
     * Check if legal person is domestic
     *
     * @return bool
     */
    public function isDomestic(): bool
    {
        return $this->countryCode == 'ES';
    }

    /**
     * Get ID with country code for EU
     *
     * @return string
     */
    public function getId(): string
    {
        if (VatValidator::isEU($this->countryCode)) {
            return $this->countryCode . $this->id;
        } else {
            return $this->id;
        }
    }
}
