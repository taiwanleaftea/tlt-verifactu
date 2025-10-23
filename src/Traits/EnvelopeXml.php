<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;

trait EnvelopeXml
{
    /**
     * @param DOMDocument $signedDom
     * @param LegalPerson $issuer
     * @return DOMDocument
     * @throws \DOMException
     */
    public static function createEnvelopedXml(DOMDocument $signedDom, LegalPerson $issuer): DOMDocument
    {
        $namespace = AEAT::SF_NAMESPACE;

        $envelopedDom = new DOMDocument('1.0', 'UTF-8');
        $envelopedDom->formatOutput = true;

        $root = $envelopedDom->createElementNS($namespace, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', $namespace);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', AEAT::DS_NAMESPACE);
        $envelopedDom->appendChild($root);

        $cabecera = $envelopedDom->createElementNS(AEAT::SFLR_NAMESPACE, 'sfLR:Cabecera');
        $root->appendChild($cabecera);

        $obligadoEmision = $envelopedDom->createElementNS($namespace, 'sf:ObligadoEmision');
        $cabecera->appendChild($obligadoEmision);

        // Issuer data
        $obligadoEmision->appendChild($envelopedDom->createElementNS($namespace, 'sf:NombreRazon', $issuer->name));
        $obligadoEmision->appendChild($envelopedDom->createElementNS($namespace, 'sf:NIF', $issuer->id));

        $registroFactura = $envelopedDom->createElementNS(AEAT::SFLR_NAMESPACE, 'sfLR:RegistroFactura');
        $root->appendChild($registroFactura);

        $imported = $envelopedDom->importNode($signedDom->documentElement, true);
        $registroFactura->appendChild($imported);

        return $envelopedDom;
    }
}
