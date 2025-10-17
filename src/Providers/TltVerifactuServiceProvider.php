<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Providers;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Taiwanleaftea\TltVerifactu\Support\Verifactu;

/**
 * Class TltVerifactuServiceProvider.
 */
class TltVerifactuServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        AboutCommand::add('TLT Verifactu', fn() => ['Version' => Verifactu::VERSION]);

        $this->publishes([
            __DIR__.'/../../config/tlt-verifactu.php' => config_path('tlt-verifactu.php'),
        ], 'tlt-verifactu');

        $this->loadJsonTranslationsFrom(__DIR__.'/../../lang');
    }

    /**
     * Register provider.
     *
     * @return $this
     */
    public function registerProviders(): self
    {
        foreach ($this->providers() as $provider) {
            $this->app->register($provider);
        }

        return $this;
    }

    /**
     * Get the service providers.
     */
    public function providers(): array
    {
        return [
            VerifactuServiceProvider::class,
            VatValidatorServiceProvider::class,
        ];
    }

    /**
     * Register bindings the service provider.
     */
    public function register(): void
    {
        $this->registerProviders();

        $this->mergeConfigFrom(__DIR__.'/../../config/tlt-verifactu.php', 'tlt-verifactu');
    }
}
