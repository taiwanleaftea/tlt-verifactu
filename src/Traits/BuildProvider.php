<?php

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\Provider;

trait BuildProvider
{
    /**
     * @param DOMDocument $dom
     * @param $namespace
     * @param Provider $provider
     * @return \DOMElement|false
     * @throws \DOMException
     */
    private static function buildProvider(DOMDocument $dom, $namespace, Provider $provider): false|\DOMElement
    {
        $sistemaInformatico = $dom->createElementNS($namespace, 'sf:SistemaInformatico');
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NombreRazon', $provider->providerName));

        if ($provider->isDomestic()) {
            $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NIF', $provider->providerId));
        } else {
            $idOtro = $dom->createElementNS($namespace, 'sf:IDOtro');
            $sistemaInformatico->appendChild($idOtro);

            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:CodigoPais', $provider->countryCode));
            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:IDType', $provider->idType->value));
            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $provider->providerId));
        }

        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NombreSistemaInformatico', $provider->systemName));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:IdSistemaInformatico', $provider->systemId));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:Version', $provider->systemVersion));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NumeroInstalacion', (string) $provider->installationNumber));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:TipoUsoPosibleSoloVerifactu', $provider->getVerifactuOnly()));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:TipoUsoPosibleMultiOT', $provider->getMultipleTaxpayers()));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:IndicadorMultiplesOT', $provider->getSingleTaxpayerMode()));

        return $sistemaInformatico;
    }
}
