<?php

namespace Taiwanleaftea\TltVerifactu\Test\VatValidator;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use SoapClient;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator as VatValidatorFacade;
use Taiwanleaftea\TltVerifactu\Support\VatValidator;

#[CoversClass(VatValidator::class)]
class VatValidatorTest extends TestCase
{
    public function test_online_rejects_invalid_format()
    {
        $response = VatValidatorFacade::online('ES', '123456789');

        $this->assertEquals('VAT number is invalid', $response->errors[0]);
        $this->assertFalse($response->success);
    }

    public function test_online_returns_vies_response()
    {
        $soapClient = new FakeVatSoapClient((object) [
            'vatNumber' => '123456789',
            'countryCode' => 'DE',
            'valid' => true,
            'requestDate' => '2026-06-25+02:00',
            'name' => 'Buyer GmbH',
            'address' => 'Berlin',
        ]);

        $response = (new FakeVatValidator($soapClient))->online('de', '123456789');

        $this->assertTrue($response->success);
        $this->assertTrue($response->valid);
        $this->assertEquals('DE', $response->countryCode);
        $this->assertEquals('123456789', $response->vatNumber);
        $this->assertEquals('Buyer GmbH', $response->name);
        $this->assertEquals([
            'countryCode' => 'DE',
            'vatNumber' => '123456789',
        ], $soapClient->queries[0]);
    }

    public function test_online_returns_errors_when_client_cannot_be_created()
    {
        $response = (new FakeVatValidator(exception: new SoapClientException('SOAP connection fault')))->online('DE', '123456789');

        $this->assertFalse($response->success);
        $this->assertEquals(['SOAP connection fault'], $response->errors);
    }

    public function test_online_returns_errors_when_vies_call_fails()
    {
        $soapClient = new FakeVatSoapClient(fault: new SoapFault('SERVER', 'VIES unavailable'));

        $response = (new FakeVatValidator($soapClient))->online('DE', '123456789');

        $this->assertFalse($response->success);
        $this->assertEquals(['VIES unavailable'], $response->errors);
    }

    public function test_sanitize()
    {
        $this->assertEquals('ESY2127633H', VatValidatorFacade::sanitize('ES', 'es y-21.2 76 33_h'), 'Test with country failed.');
        $this->assertEquals('Y2127633H', VatValidatorFacade::sanitize('es', 'ES y-21.2 76 33_h', true), 'Test with country remove failed.');
        $this->assertSame('', VatValidatorFacade::sanitize('ES', ''));
        $this->assertNull(VatValidatorFacade::sanitize('ES', null));
    }

    public function test_format_valid()
    {
        $this->assertTrue(VatValidatorFacade::formatValid('ES', 'Y2127633H'));
        $this->assertTrue(VatValidatorFacade::formatValid('es', 'y2127633h'));
        $this->assertTrue(VatValidatorFacade::formatValid('DE', '123456789'));
        $this->assertFalse(VatValidatorFacade::formatValid('RR', 'Y2127633H'));
        $this->assertFalse(VatValidatorFacade::formatValid('DE', 'Y2127633H'));
        $this->assertFalse(VatValidatorFacade::formatValid('DE', ''));
        $this->assertFalse(VatValidatorFacade::formatValid('DE', null));
    }

    public function test_is_eu()
    {
        $this->assertTrue(VatValidatorFacade::isEU('ES'));
        $this->assertTrue(VatValidatorFacade::isEU('DE'));
        $this->assertFalse(VatValidatorFacade::isEU('US'));
        $this->assertFalse(VatValidatorFacade::isEU('GB'));
    }
}

class FakeVatValidator extends VatValidator
{
    public function __construct(
        private ?SoapClient $soapClient = null,
        private ?SoapClientException $exception = null,
    ) {}

    protected function createSoapClient(string $wsdl): SoapClient
    {
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->soapClient;
    }
}

class FakeVatSoapClient extends SoapClient
{
    public array $queries = [];

    public function __construct(
        private ?object $response = null,
        private ?SoapFault $fault = null,
    ) {}

    public function checkVat(array $query): object
    {
        $this->queries[] = $query;

        if ($this->fault) {
            throw $this->fault;
        }

        return $this->response;
    }
}
