<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;

class Recipient extends LegalPerson
{
    /**
     * @param string $name
     * @param string $id
     * @param string $countryCode
     * @param IdType $idType
     * @throws RecipientException
     */
    public function __construct(string $name, string $id, string $countryCode, IdType $idType)
    {
        if (!$id && VatValidator::isEU($countryCode)) {
            throw new RecipientException('VAT ID required for the country ' . $countryCode);
        }

        parent::__construct($name, $id, $countryCode, $idType);
    }
}
