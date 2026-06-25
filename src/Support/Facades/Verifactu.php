<?php

namespace Taiwanleaftea\TltVerifactu\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Taiwanleaftea\TltVerifactu\Support\Verifactu as VerifactuHelper;

class Verifactu extends Facade
{
    /**
     * Initiate a mock expectation on the facade.
     */
    protected static function getFacadeAccessor(): string
    {
        return VerifactuHelper::class;
    }
}
