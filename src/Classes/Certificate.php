<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Classes;

use Illuminate\Support\Str;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;

class Certificate
{
    private string $certPath;
    private string $password;
    private array $certificate;

    public function __construct(string $certPath, string $password)
    {
        $this->certPath = $certPath;
        $this->password = $password;
    }

    /**
     * Get X.509 certificate contents.
     *
     * @return array
     * @throws CertificateException
     */
    public function readP12Certificate(): array
    {
        if (!file_exists($this->certPath)) {
            throw new CertificateException('Certificate not found: ' . $this->certPath);
        }

        $ext = Str::lower(pathinfo($this->certPath, PATHINFO_EXTENSION));
        $certificate = file_get_contents($this->certPath);

        if ($ext === 'p12') {
            if (openssl_pkcs12_read($certificate, $p12Certificate, $this->password) === false) {
                throw new CertificateException('Failed to read p12 certificate: ' . openssl_error_string());
            } else {
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
     * @return string
     * @throws CertificateException
     */
    public function getCertificate(): string
    {
        if (!isset($this->certificate)) {
            $this->readP12Certificate();
        }

        return $this->certificate['certificate'];
    }

    /**
     * Get private key
     *
     * @return string
     * @throws CertificateException
     */
    public function getPrivateKey(): string
    {
        if (!isset($this->certificate)) {
            $this->readP12Certificate();
        }

        return $this->certificate['private_key'];
    }
}
