<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Exceptions\XmlGenerationException;

trait SignXml
{
    /**
     * Sign XML in accordance with VERIFACTU specification
     *
     * @param DOMDocument $dom
     * @param Certificate $certificate
     * @return DOMDocument
     * @throws \Taiwanleaftea\TltVerifactu\Exceptions\CertificateException
     * @throws \Exception
     */
    public function signXml(Certificate $certificate): DOMDocument
    {
        if (!$this->generated) {
            throw new XmlGenerationException('XML must be generated first.');
        }

        $dom = $this->document;

        $dSig = new XMLSecurityDSig();
        $dSig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dSig->addReference(
            $dom,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($certificate->getPrivateKey());

        // Attach the public certificate to the KeyInfo
        $dSig->add509Cert($certificate->getCertificate(), true, false, ['subjectName' => true]);

        $dSig->sign($key);
        $dSig->appendSignature($dom->documentElement);

        $this->document = $dom;
        $this->signed = true;

        return $dom;
    }
}
