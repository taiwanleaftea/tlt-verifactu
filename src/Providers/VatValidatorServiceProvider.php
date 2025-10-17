<?php

namespace Taiwanleaftea\TltVerifactu\Providers;

use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;
use Illuminate\Support\ServiceProvider;

class VatValidatorServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(VatValidator::class, function () {
            return new VatValidator();
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
