<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;

class XadesEpesSigner
{
    private const string XADES_NS = 'http://uri.etsi.org/01903/v1.3.2#';

    private const string XADES141_NS = 'http://uri.etsi.org/01903/v1.4.1#';

    public const string SIGNATURE_FORMAT = 'XAdES-EPES';

    public const string SIGNATURE_ALGORITHM = XMLSecurityKey::RSA_SHA256;

    public const string DIGEST_ALGORITHM = XMLSecurityDSig::SHA256;

    public const string POLICY_ID = 'urn:oid:2.16.724.1.3.1.1.2.1.9';

    public const string POLICY_URL = 'https://sede.administracion.gob.es/politica_de_firma_anexo_1.pdf';

    public const string POLICY_HASH = 'G7roucf600+f03r/o0bAOQ6WAs0=';

    public const string POLICY_HASH_ALGORITHM = XMLSecurityDSig::SHA1;

    public const string CERTIFICATE_DIGEST_ALGORITHM = XMLSecurityDSig::SHA1;

    public function sign(DOMDocument $dom, Certificate $certificate, ?Carbon $signingTime = null): DOMDocument
    {
        $signatureId = XMLSecurityDSig::generateGUID('xmldsig-');
        $referenceId = $signatureId.'-ref0';
        $signedPropertiesId = $signatureId.'-signedprops';

        $signature = new XMLSecurityDSig;
        $signature->sigNode->setAttribute('Id', $signatureId);
        $signatureNode = $signature->appendSignature($dom->documentElement);

        if (! $signatureNode instanceof DOMElement) {
            throw new RuntimeException('XAdES signature node cannot be appended.');
        }

        $signature->sigNode = $signatureNode;
        $signature->setCanonicalMethod(XMLSecurityDSig::C14N);

        $signature->addReference(
            $dom,
            self::DIGEST_ALGORITHM,
            [XMLSecurityDSig::XMLDSIGNS.'enveloped-signature'],
            ['force_uri' => true],
        );
        $this->setFirstReferenceId($signature, $referenceId);

        $qualifyingProperties = $this->buildQualifyingProperties(
            $signature->sigNode->ownerDocument,
            $signatureId,
            $signedPropertiesId,
            $referenceId,
            $certificate,
            $signingTime ?? Carbon::now(),
        );
        $object = $signature->addObject($qualifyingProperties);
        $signedProperties = $this->firstElement($object, 'SignedProperties');

        $signature->addReference(
            $signedProperties,
            self::DIGEST_ALGORITHM,
            [XMLSecurityDSig::C14N],
            ['overwrite' => false],
        );
        $this->setSignedPropertiesReferenceType($signature, $signedPropertiesId);

        $key = new XMLSecurityKey(self::SIGNATURE_ALGORITHM, ['type' => 'private']);
        $key->loadKey($certificate->getPrivateKey());

        $signature->add509Cert($certificate->getCertificate(), true, false, ['subjectName' => true]);
        $signature->sign($key);

        return $dom;
    }

    private function buildQualifyingProperties(
        DOMDocument $dom,
        string $signatureId,
        string $signedPropertiesId,
        string $referenceId,
        Certificate $certificate,
        Carbon $signingTime,
    ): DOMElement {
        $qualifyingProperties = $dom->createElementNS(self::XADES_NS, 'xades:QualifyingProperties');
        $qualifyingProperties->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xades141', self::XADES141_NS);
        $qualifyingProperties->setAttribute('Target', '#'.$signatureId);

        $signedProperties = $this->append($dom, $qualifyingProperties, self::XADES_NS, 'xades:SignedProperties');
        $signedProperties->setAttribute('Id', $signedPropertiesId);

        $signedSignatureProperties = $this->append($dom, $signedProperties, self::XADES_NS, 'xades:SignedSignatureProperties');
        $this->append($dom, $signedSignatureProperties, self::XADES_NS, 'xades:SigningTime', $signingTime->toAtomString());
        $this->appendSigningCertificate($dom, $signedSignatureProperties, $certificate);
        $this->appendSignaturePolicy($dom, $signedSignatureProperties);

        $signedDataObjectProperties = $this->append($dom, $signedProperties, self::XADES_NS, 'xades:SignedDataObjectProperties');
        $dataObjectFormat = $this->append($dom, $signedDataObjectProperties, self::XADES_NS, 'xades:DataObjectFormat');
        $dataObjectFormat->setAttribute('ObjectReference', '#'.$referenceId);
        $objectIdentifier = $this->append($dom, $dataObjectFormat, self::XADES_NS, 'xades:ObjectIdentifier');
        $this->append($dom, $objectIdentifier, self::XADES_NS, 'xades:Identifier', 'urn:oid:1.2.840.10003.5.109.10');
        $this->append($dom, $objectIdentifier, self::XADES_NS, 'xades:Description', '');
        $this->append($dom, $dataObjectFormat, self::XADES_NS, 'xades:MimeType', 'text/xml');
        $this->append($dom, $dataObjectFormat, self::XADES_NS, 'xades:Encoding', 'UTF8');

        return $qualifyingProperties;
    }

    private function appendSigningCertificate(DOMDocument $dom, DOMElement $parent, Certificate $certificate): void
    {
        $certPem = $certificate->getCertificate();
        $certBody = XMLSecurityDSig::get509XCert($certPem);
        $certInfo = openssl_x509_parse($certPem);

        $signingCertificate = $this->append($dom, $parent, self::XADES_NS, 'xades:SigningCertificate');
        $cert = $this->append($dom, $signingCertificate, self::XADES_NS, 'xades:Cert');

        $certDigest = $this->append($dom, $cert, self::XADES_NS, 'xades:CertDigest');
        $digestMethod = $this->append($dom, $certDigest, XMLSecurityDSig::XMLDSIGNS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::CERTIFICATE_DIGEST_ALGORITHM);
        $this->append($dom, $certDigest, XMLSecurityDSig::XMLDSIGNS, 'ds:DigestValue', base64_encode(sha1(base64_decode($certBody), true)));

        $issuerSerial = $this->append($dom, $cert, self::XADES_NS, 'xades:IssuerSerial');
        $this->append($dom, $issuerSerial, XMLSecurityDSig::XMLDSIGNS, 'ds:X509IssuerName', $this->distinguishedName($certInfo['issuer'] ?? []));
        $this->append($dom, $issuerSerial, XMLSecurityDSig::XMLDSIGNS, 'ds:X509SerialNumber', (string) ($certInfo['serialNumber'] ?? $certInfo['serialNumberHex'] ?? ''));
    }

    private function appendSignaturePolicy(DOMDocument $dom, DOMElement $parent): void
    {
        $policyIdentifier = $this->append($dom, $parent, self::XADES_NS, 'xades:SignaturePolicyIdentifier');
        $policyId = $this->append($dom, $policyIdentifier, self::XADES_NS, 'xades:SignaturePolicyId');
        $sigPolicyId = $this->append($dom, $policyId, self::XADES_NS, 'xades:SigPolicyId');
        $this->append($dom, $sigPolicyId, self::XADES_NS, 'xades:Identifier', self::POLICY_ID);
        $this->append($dom, $sigPolicyId, self::XADES_NS, 'xades:Description', '');

        $sigPolicyHash = $this->append($dom, $policyId, self::XADES_NS, 'xades:SigPolicyHash');
        $digestMethod = $this->append($dom, $sigPolicyHash, XMLSecurityDSig::XMLDSIGNS, 'ds:DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::POLICY_HASH_ALGORITHM);
        $this->append($dom, $sigPolicyHash, XMLSecurityDSig::XMLDSIGNS, 'ds:DigestValue', self::POLICY_HASH);

        $qualifiers = $this->append($dom, $policyId, self::XADES_NS, 'xades:SigPolicyQualifiers');
        $qualifier = $this->append($dom, $qualifiers, self::XADES_NS, 'xades:SigPolicyQualifier');
        $this->append($dom, $qualifier, self::XADES_NS, 'xades:SPURI', self::POLICY_URL);
    }

    private function setFirstReferenceId(XMLSecurityDSig $signature, string $referenceId): void
    {
        $reference = $signature->sigNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Reference')->item(0);

        if ($reference instanceof DOMElement) {
            $reference->setAttribute('Id', $referenceId);
        }
    }

    private function setSignedPropertiesReferenceType(XMLSecurityDSig $signature, string $signedPropertiesId): void
    {
        foreach ($signature->sigNode->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'Reference') as $reference) {
            if ($reference instanceof DOMElement && $reference->getAttribute('URI') === '#'.$signedPropertiesId) {
                $reference->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');

                return;
            }
        }
    }

    private function firstElement(DOMElement $parent, string $localName): DOMElement
    {
        foreach ($parent->getElementsByTagNameNS(self::XADES_NS, $localName) as $element) {
            if ($element instanceof DOMElement) {
                return $element;
            }
        }

        throw new RuntimeException('XAdES '.$localName.' element was not created.');
    }

    private function append(DOMDocument $dom, DOMElement $parent, string $namespace, string $qualifiedName, ?string $value = null): DOMElement
    {
        $element = $dom->createElementNS($namespace, $qualifiedName);

        if ($value !== null) {
            $element->appendChild($dom->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function distinguishedName(array $parts): string
    {
        $dn = [];

        foreach ($parts as $key => $value) {
            foreach ((array) $value as $part) {
                array_unshift($dn, $key.'='.$part);
            }
        }

        return implode(',', $dn);
    }
}
