<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use Orchestra\Testbench\TestCase;

class FeatureTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            'Taiwanleaftea\TltVerifactu\Providers\TltVerifactuServiceProvider',
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUpDatabase(): void
    {
        $this->artisan('migrate')->run();
    }
}
