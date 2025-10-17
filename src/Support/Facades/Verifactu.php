<?php

namespace Taiwanleaftea\TltVerifactu\Support\Facades;

use Taiwanleaftea\TltVerifactu\Support\Verifactu as VerifactuHelper;
use Illuminate\Support\Facades\Facade;

class Verifactu extends Facade
{
    /**
     * Initiate a mock expectation on the facade.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return VerifactuHelper::class;
    }
}
