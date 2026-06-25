<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Taiwanleaftea\TltVerifactu\Enums\GeneratorType;
use Taiwanleaftea\TltVerifactu\Enums\IdType;

class Generator extends LegalPerson
{
    public GeneratorType $generatorType;

    public function __construct(GeneratorType $generatorType, string $name, string $id, string $countryCode, IdType $idType)
    {
        $this->generatorType = $generatorType;
        parent::__construct($name, $id, $countryCode, $idType);
    }
}
