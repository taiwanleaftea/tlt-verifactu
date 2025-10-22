<?php

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\InformationSystem;

trait BuildInformationSystem
{
    /**
     * @param DOMDocument $dom
     * @param $namespace
     * @param InformationSystem $system
     * @return \DOMElement|false
     * @throws \DOMException
     */
    private static function buildInformationSystem(DOMDocument $dom, $namespace, InformationSystem $system): false|\DOMElement
    {
        $sistemaInformatico = $dom->createElementNS($namespace, 'sf:SistemaInformatico');
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NombreRazon', $system->provider->name));

        if ($system->provider->isDomestic()) {
            $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NIF', $system->provider->id));
        } else {
            $idOtro = $dom->createElementNS($namespace, 'sf:IDOtro');
            $sistemaInformatico->appendChild($idOtro);

            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:CodigoPais', $system->provider->countryCode));
            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:IDType', $system->provider->idType->value));
            $idOtro->appendChild($dom->createElementNS($namespace, 'sf:ID', $system->provider->id));
        }

        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NombreSistemaInformatico', $system->name));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:IdSistemaInformatico', $system->id));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:Version', $system->version));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:NumeroInstalacion', (string) $system->installationNumber));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:TipoUsoPosibleSoloVerifactu', $system->getVerifactuOnly()));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:TipoUsoPosibleMultiOT', $system->getMultipleTaxpayers()));
        $sistemaInformatico->appendChild($dom->createElementNS($namespace, 'sf:IndicadorMultiplesOT', $system->getSingleTaxpayerMode()));

        return $sistemaInformatico;
    }
}
