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
        $isEU = VatValidator::isEU($countryCode);

        if (!$id && $isEU) {
            throw new RecipientException('VAT ID required for the country ' . $countryCode);
        }

        if ($isEU && $idType != IdType::NIF) {
            throw new RecipientException('VAT ID type must be ' . IdType::NIF->value . ' for EU country.');
        }

        if ($isEU) {
            $id = VatValidator::sanitize($countryCode, $id, true);
        }

        parent::__construct($name, $id, $countryCode, $idType);
    }
}
