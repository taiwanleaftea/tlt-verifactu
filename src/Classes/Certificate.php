<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;

class Certificate
{
    private string $disk;

    private string $certPath;

    private string $password;

    private array $certificate;

    public function __construct(string $certPath, string $password)
    {
        $this->certPath = $certPath;
        $this->password = $password;
        $this->disk = config('tlt-verifactu.disk');
    }

    /**
     * Get X.509 certificate contents.
     *
     * @throws CertificateException
     */
    public function readP12Certificate(): array
    {
        if (Storage::disk($this->disk)->missing($this->certPath)) {
            throw new CertificateException('Certificate not found: '.$this->certPath);
        }

        $ext = Str::lower(pathinfo($this->certPath, PATHINFO_EXTENSION));
        $certificate = Storage::disk($this->disk)->get($this->certPath);

        if ($ext === 'p12') {
            if (openssl_pkcs12_read($certificate, $p12Certificate, $this->password) === false) {
                throw new CertificateException('Failed to read p12 certificate: '.openssl_error_string());
            } else {
                $certInfo = openssl_x509_parse($p12Certificate['cert']);
                $now = time();

                if ($certInfo['validTo_time_t'] < $now || $certInfo['validFrom_time_t'] > $now) {
                    throw new CertificateException('Certificate has been expired.');
                }

                return [
                    'certificate' => $p12Certificate['cert'],
                    'private_key' => $p12Certificate['pkey'],
                ];
            }
        } else {
            throw new CertificateException('Unsupported certificate format.');
        }
    }

    /**
     * Get certificate
     *
     * @throws CertificateException
     */
    public function getCertificate(): string
    {
        if (! isset($this->certificate)) {
            $this->certificate = $this->readP12Certificate();
        }

        return $this->certificate['certificate'];
    }

    /**
     * Get private key
     *
     * @throws CertificateException
     */
    public function getPrivateKey(): string
    {
        if (! isset($this->certificate)) {
            $this->certificate = $this->readP12Certificate();
        }

        return $this->certificate['private_key'];
    }

    /**
     * Get parsed X.509 certificate information
     *
     * @throws CertificateException
     */
    public function getCertificateInfo(): array
    {
        $info = openssl_x509_parse($this->getCertificate());

        if ($info === false) {
            throw new CertificateException('Failed to parse certificate.');
        }

        return $info;
    }

    /**
     * Get certificate subject in XAdES-compatible DN format
     *
     * @throws CertificateException
     */
    public function getSubjectName(): string
    {
        return $this->distinguishedName($this->getCertificateInfo()['subject'] ?? []);
    }

    /**
     * Get certificate issuer in XAdES-compatible DN format
     *
     * @throws CertificateException
     */
    public function getIssuerName(): string
    {
        return $this->distinguishedName($this->getCertificateInfo()['issuer'] ?? []);
    }

    /**
     * Get certificate serial number
     *
     * @throws CertificateException
     */
    public function getSerialNumber(): string
    {
        $info = $this->getCertificateInfo();

        return (string) ($info['serialNumber'] ?? $info['serialNumberHex'] ?? '');
    }

    /**
     * Get Spanish NIF/NIE/CIF from certificate subject data when present
     *
     * @throws CertificateException
     */
    public function getSubjectNif(): ?string
    {
        $info = $this->getCertificateInfo();

        foreach ($this->subjectNifCandidates($info) as $candidate) {
            $nif = $this->extractSpanishId((string) $candidate);

            if ($nif !== null) {
                return $nif;
            }
        }

        return null;
    }

    /**
     * Get base64 digest of DER certificate bytes
     *
     * @throws CertificateException
     */
    public function getDigest(string $algorithm = 'sha1'): string
    {
        $certificateBody = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $this->getCertificate());

        if (! is_string($certificateBody)) {
            throw new CertificateException('Failed to normalize certificate.');
        }

        $certificateBytes = base64_decode($certificateBody, true);

        if ($certificateBytes === false) {
            throw new CertificateException('Failed to decode certificate.');
        }

        return base64_encode(hash($algorithm, $certificateBytes, true));
    }

    /**
     * Generate PEM certificate for SOAP request
     *
     * @throws CertificateException
     */
    public function generatePem(): false|string
    {
        if (openssl_pkey_export(Str::finish($this->getPrivateKey(), '\n'), $pKey, $this->password) === false) {
            return false;
        }

        // $pKey = $this->getPrivateKey();
        $pemContent = $this->getCertificate().$pKey;

        // $pemContent = Str::finish($this->getCertificate(), '\n') . Str::finish($this->getPrivateKey(), '\n');
        $name = Str::lower(pathinfo($this->certPath, PATHINFO_FILENAME)).'.pem';

        if (Storage::disk($this->disk)->put($name, $pemContent)) {
            return Storage::disk($this->disk)->path($name);
        } else {
            return false;
        }
    }

    /**
     * Get certificate password
     */
    public function getPassword(): string
    {
        return $this->password;
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

    private function subjectNifCandidates(array $info): array
    {
        $candidates = [];

        foreach (($info['subject'] ?? []) as $value) {
            foreach ((array) $value as $part) {
                $candidates[] = $part;
            }
        }

        if (isset($info['extensions']['subjectAltName'])) {
            $candidates[] = $info['extensions']['subjectAltName'];
        }

        return $candidates;
    }

    private function extractSpanishId(string $value): ?string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($value));

        if (! is_string($normalized)) {
            return null;
        }

        if (preg_match('/(?:IDCES|IDC|NIF|CIF|NIE)?([ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]|\d{8}[A-Z]|[XYZ]\d{7}[A-Z])/', $normalized, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
