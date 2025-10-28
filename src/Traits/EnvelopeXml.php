<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Constants\AEAT;
use Taiwanleaftea\TltVerifactu\Exceptions\XmlGenerationException;

trait EnvelopeXml
{
    /**
     * Create Cabecera in accordance with VERIFACTU format for signed XML
     *
     * @param DOMDocument $signedDom
     * @param LegalPerson $issuer
     * @return DOMDocument
     * @throws \DOMException
     */
    public function createEnvelopedXml(LegalPerson $issuer): DOMDocument
    {
        if (!$this->generated) {
            throw new XmlGenerationException('XML must be generated first.');
        }

        $signedDom = $this->document;

        $envelopedDom = new DOMDocument('1.0', 'UTF-8');
        $envelopedDom->formatOutput = true;

        $root = $envelopedDom->createElementNS(AEAT::SFLR_NAMESPACE, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', AEAT::SF_NAMESPACE);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', AEAT::DS_NAMESPACE);
        $envelopedDom->appendChild($root);

        $cabecera = $envelopedDom->createElementNS(AEAT::SFLR_NAMESPACE, 'sfLR:Cabecera');
        $root->appendChild($cabecera);

        $obligadoEmision = $envelopedDom->createElementNS(AEAT::SF_NAMESPACE, 'sf:ObligadoEmision');
        $cabecera->appendChild($obligadoEmision);

        // Issuer data
        $obligadoEmision->appendChild($envelopedDom->createElementNS(AEAT::SF_NAMESPACE, 'sf:NombreRazon', $issuer->name));
        $obligadoEmision->appendChild($envelopedDom->createElementNS(AEAT::SF_NAMESPACE, 'sf:NIF', $issuer->id));

        $registroFactura = $envelopedDom->createElementNS(AEAT::SFLR_NAMESPACE, 'sfLR:RegistroFactura');
        $root->appendChild($registroFactura);

        $imported = $envelopedDom->importNode($signedDom->documentElement, true);
        $registroFactura->appendChild($imported);

        $this->document = $envelopedDom;

        return $envelopedDom;
    }
}
