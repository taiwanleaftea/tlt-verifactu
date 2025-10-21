<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Constants\EU;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;

class Recipient
{
    public string $name;
    public string $countryCode;
    public string $id;
    public IdType $idType;

    /**
     * @param string $name
     * @param string $countryCode
     * @param string $id
     * @param IdType $idType
     * @throws RecipientException
     */
    public function __construct(string $name, string $countryCode, string $id, IdType $idType)
    {
        $this->name = $name;
        $this->countryCode = Str::upper($countryCode);
        $this->idType = $idType;
        $this->id = $id;

        if (!$id && in_array($countryCode, EU::MEMBERS)) {
            throw new RecipientException('VAT ID required for the country ' . $countryCode);
        }
    }

    /**
     * Check if recipient is domestic
     *
     * @return bool
     */
    public function isDomestic(): bool
    {
        return $this->countryCode == 'ES';
    }
}
