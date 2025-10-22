<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Enums\IdType;

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

}
