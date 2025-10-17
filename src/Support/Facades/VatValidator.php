<?php

namespace Taiwanleaftea\TltVerifactu\Support\Facades;

use Taiwanleaftea\TltVerifactu\Support\VatValidator as VatValidatorHelper;
use Illuminate\Support\Facades\Facade;

class VatValidator extends Facade
{
    /**
     * Initiate a mock expectation on the facade.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return VatValidatorHelper::class;
    }
}
