<?php

namespace Taiwanleaftea\TltVerifactu\Providers;

use Illuminate\Support\ServiceProvider;
use Taiwanleaftea\TltVerifactu\Support\Verifactu;

class VerifactuServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(Verifactu::class, function (): Verifactu {
            return new Verifactu;
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
