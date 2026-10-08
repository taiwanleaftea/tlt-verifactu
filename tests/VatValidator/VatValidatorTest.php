<?php

namespace Taiwanleaftea\TltVerifactu\Test\VatValidator;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionObject;
use RuntimeException;
use SoapClient;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Constants\VIES;
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
        $previousTimeout = ini_get('default_socket_timeout');
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
        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_online_returns_errors_when_client_cannot_be_created()
    {
        $previousTimeout = ini_get('default_socket_timeout');
        $response = (new FakeVatValidator(exception: new SoapClientException('SOAP connection fault')))->online('DE', '123456789');

        $this->assertFalse($response->success);
        $this->assertEquals(['SOAP connection fault'], $response->errors);
        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_online_returns_errors_when_vies_call_fails()
    {
        $previousTimeout = ini_get('default_socket_timeout');
        $soapClient = new FakeVatSoapClient(fault: new SoapFault('SERVER', 'VIES unavailable'));

        $response = (new FakeVatValidator($soapClient))->online('DE', '123456789');

        $this->assertFalse($response->success);
        $this->assertEquals(['VIES unavailable'], $response->errors);
        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_read_timeout_applies_to_wsdl_creation_and_soap_call()
    {
        config()->set('tlt-verifactu.vies.read_timeout', 3);
        $previousTimeout = ini_get('default_socket_timeout');
        $checkTimeout = function () {
            $this->assertSame('3', ini_get('default_socket_timeout'));
        };
        $client = new FakeVatSoapClient(
            fault: new SoapFault('HTTP', 'Error Fetching http headers'),
            onCheck: $checkTimeout,
        );

        $response = (new FakeVatValidator($client, onCreate: $checkTimeout))->online('DE', '123456789');

        $this->assertFalse($response->success);
        $this->assertSame(['Error Fetching http headers'], $response->errors);
        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_non_positive_read_timeout_is_clamped_to_one_second()
    {
        config()->set('tlt-verifactu.vies.read_timeout', 0);
        $previousTimeout = ini_get('default_socket_timeout');
        $client = new FakeVatSoapClient(
            fault: new SoapFault('HTTP', 'Timed out'),
            onCheck: function () {
                $this->assertSame('1', ini_get('default_socket_timeout'));
            },
        );

        (new FakeVatValidator($client))->online('DE', '123456789');

        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_timeout_is_restored_when_an_unexpected_exception_is_thrown()
    {
        $previousTimeout = ini_get('default_socket_timeout');
        $client = new FakeVatSoapClient(onCheck: function () {
            throw new RuntimeException('Unexpected failure');
        });

        try {
            (new FakeVatValidator($client))->online('DE', '123456789');
            $this->fail('The unexpected exception should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected failure', $exception->getMessage());
        }

        $this->assertSame($previousTimeout, ini_get('default_socket_timeout'));
    }

    public function test_soap_client_uses_https_and_configured_connection_and_read_timeouts()
    {
        config()->set('tlt-verifactu.vies.connect_timeout', 2);
        config()->set('tlt-verifactu.vies.read_timeout', 4);

        $client = $this->createLocalSoapClient();
        $reflection = new ReflectionObject($client);

        $this->assertSame(2, $reflection->getProperty('_connection_timeout')->getValue($client));
        $context = $reflection->getProperty('_stream_context')->getValue($client);
        $this->assertSame(4, stream_context_get_options($context)['http']['timeout']);
        $this->assertSame(
            VIES::EU_VAT_API_URL.VIES::EU_VAT_SERVICE_ENDPOINT,
            $client->__setLocation('http://localhost/unused'),
        );
    }

    public function test_soap_client_uses_default_timeouts_when_published_config_has_no_vies_settings()
    {
        config()->offsetUnset('tlt-verifactu.vies');

        $client = $this->createLocalSoapClient();
        $reflection = new ReflectionObject($client);

        $this->assertSame(5, $reflection->getProperty('_connection_timeout')->getValue($client));
        $context = $reflection->getProperty('_stream_context')->getValue($client);
        $this->assertSame(10, stream_context_get_options($context)['http']['timeout']);
    }

    public function test_non_positive_connection_timeout_is_clamped_to_one_second()
    {
        config()->set('tlt-verifactu.vies.connect_timeout', -1);

        $client = $this->createLocalSoapClient();

        $this->assertSame(1, (new ReflectionObject($client))->getProperty('_connection_timeout')->getValue($client));
    }

    private function createLocalSoapClient(): SoapClient
    {
        return (new class extends VatValidator
        {
            public function client(): SoapClient
            {
                return $this->createSoapClient(__DIR__.'/fixtures/check-vat.wsdl');
            }
        })->client();
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
        private ?\Closure $onCreate = null,
    ) {}

    protected function createSoapClient(string $wsdl): SoapClient
    {
        if ($this->onCreate) {
            ($this->onCreate)();
        }

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
        private ?\Closure $onCheck = null,
    ) {}

    public function checkVat(array $query): object
    {
        $this->queries[] = $query;

        if ($this->onCheck) {
            ($this->onCheck)();
        }

        if ($this->fault) {
            throw $this->fault;
        }

        return $this->response;
    }
}
