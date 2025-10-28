<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use SoapClient;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;

class Soap
{
    /**
     * @param string $wsdl
     * @param array $options
     * @return SoapClient
     * @throws SoapClientException
     */
    public static function createClient(string $wsdl, array $options = []): SoapClient
    {
        try {
            $client = new SoapClient($wsdl, $options);
        } catch (SoapFault $e) {
            throw new SoapClientException('SOAP connection fault: ' . $e->getMessage());
        }

        return $client;
    }
}
