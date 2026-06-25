<?php

namespace Taiwanleaftea\TltVerifactu\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Taiwanleaftea\TltVerifactu\Support\VatValidator as VatValidatorHelper;

class VatValidator extends Facade
{
    /**
     * Initiate a mock expectation on the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return VatValidatorHelper::class;
    }
}
