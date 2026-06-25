<?php

namespace Taiwanleaftea\TltVerifactu\Providers;

use Illuminate\Support\ServiceProvider;
use Taiwanleaftea\TltVerifactu\Support\VatValidator;

class VatValidatorServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(VatValidator::class, function (): VatValidator {
            return new VatValidator;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
