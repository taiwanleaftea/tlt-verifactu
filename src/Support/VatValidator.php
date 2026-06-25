<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Support;

use Illuminate\Support\Str;
use SoapClient;
use SoapFault;
use Taiwanleaftea\TltVerifactu\Classes\ResponseVies;
use Taiwanleaftea\TltVerifactu\Constants\VIES;
use Taiwanleaftea\TltVerifactu\Exceptions\SoapClientException;
use Taiwanleaftea\TltVerifactu\Services\Soap;
use Taiwanleaftea\TltVerifactu\Services\Vat;

class VatValidator
{
    /**
     * Validate VAT number via VIES service
     */
    public function online(string $country, string $vatNumber): ResponseVies
    {
        $errors = [];

        $response = new ResponseVies;

        if (! Vat::validateFormat($country, $vatNumber)) {
            $response->success = false;
            $response->errors = ['VAT number is invalid'];

            return $response;
        }

        try {
            $client = $this->createSoapClient(VIES::EU_VAT_API_URL.VIES::EU_VAT_WSDL_ENDPOINT);
        } catch (SoapClientException $e) {
            $errors[] = $e->getMessage();
        }

        if (isset($client)) {
            $query = [
                'countryCode' => Str::upper($country),
                'vatNumber' => $this->sanitize($country, $vatNumber),
            ];

            try {
                $soapResponse = $client->checkVat($query);
            } catch (SoapFault $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (isset($soapResponse)) {
            $response->success = true;
            $response->vatNumber = $soapResponse->vatNumber;
            $response->countryCode = $soapResponse->countryCode;
            $response->valid = $soapResponse->valid;
            $response->requestDate = $soapResponse->requestDate;
            $response->name = $soapResponse->name;
            $response->address = $soapResponse->address;
        } else {
            $response->success = false;
            $response->errors = $errors;
        }

        return $response;
    }

    /**
     * Validate VAT number by format
     */
    public function formatValid(string $country, ?string $vatNumber): bool
    {
        return Vat::validateFormat($country, $vatNumber);
    }

    /**
     * Sanitize VAT string
     */
    public function sanitize(string $country, ?string $vatNumber, bool $removeCountry = false): ?string
    {
        if ($vatNumber === '' || $vatNumber === null) {
            return $vatNumber;
        }

        if ($removeCountry) {
            return Str::of($vatNumber)->trim()->replace([$country, ' ', '.', '-', '_'], '', false)->upper()->toString();
        } else {
            return Str::of($vatNumber)->trim()->replace([' ', '.', '-', '_'], '', false)->upper()->toString();
        }
    }

    /**
     * Check if country is EU member
     */
    public function isEU(string $countryCode): bool
    {
        return in_array($countryCode, VIES::MEMBERS);
    }

    /**
     * @throws SoapClientException
     */
    protected function createSoapClient(string $wsdl): SoapClient
    {
        return Soap::createClient($wsdl);
    }
}
