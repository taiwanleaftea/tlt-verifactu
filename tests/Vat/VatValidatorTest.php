<?php

namespace Taiwanleaftea\TltVerifactu\Test\Vat;

use PHPUnit\Framework\Attributes\CoversClass;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;
use Orchestra\Testbench\TestCase;

#[CoversClass(VatValidator::class)]
class VatValidatorTest extends TestCase
{
    public function testOnline()
    {
        $response = VatValidator::online('ES', '123456789');
        $this->assertEquals('VAT number is invalid', $response->errors[0]);
        $this->assertFalse($response->success);

        $response = VatValidator::online('DE', '123456789');
        $this->assertFalse($response->valid);
    }

    public function testSanitize()
    {
        $this->assertEquals('ESY2127633H', VatValidator::sanitize('ES', 'es y-21.2 76 33_h'), 'Test with country failed.');
        $this->assertEquals('Y2127633H', VatValidator::sanitize('ES', 'es y-21.2 76 33_h', true), 'Test with country remove failed.');
    }

    public function testFormatValid()
    {
        $this->assertTrue(VatValidator::formatValid('ES', 'Y2127633H'));
        $this->assertTrue(VatValidator::formatValid('DE', '123456789'));
        $this->assertFalse(VatValidator::formatValid('RR', 'Y2127633H'));
        $this->assertFalse(VatValidator::formatValid('DE', 'Y2127633H'));
    }
}
