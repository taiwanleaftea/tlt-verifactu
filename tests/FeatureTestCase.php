<?php

namespace Taiwanleaftea\TltVerifactu\Test;

use Orchestra\Testbench\TestCase;

class FeatureTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            'Taiwanleaftea\TltVerifactu\Providers\TltVerifactuServiceProvider',
        ];
    }
}
