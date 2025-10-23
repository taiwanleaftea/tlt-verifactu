<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Constants\VIES;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Exceptions\RecipientException;

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
        if (!$id && in_array($countryCode, VIES::MEMBERS)) {
            throw new RecipientException('VAT ID required for the country ' . $countryCode);
        }

        parent::__construct($name, $id, $countryCode, $idType);
    }
}
