<?php

namespace Taiwanleaftea\TltVerifactu\Providers;

use Taiwanleaftea\TltVerifactu\Support\Facades\Verifactu;
use Illuminate\Support\ServiceProvider;

class VerifactuServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(Verifactu::class, function () {
            return new Verifactu();
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
