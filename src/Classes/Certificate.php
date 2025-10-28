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
     * @return array
     * @throws CertificateException
     */
    public function readP12Certificate(): array
    {
        if (Storage::disk($this->disk)->missing($this->certPath)) {
            throw new CertificateException('Certificate not found: ' . $this->certPath);
        }

        $ext = Str::lower(pathinfo($this->certPath, PATHINFO_EXTENSION));
        $certificate = Storage::disk($this->disk)->get($this->certPath);

        if ($ext === 'p12') {
            if (openssl_pkcs12_read($certificate, $p12Certificate, $this->password) === false) {
                throw new CertificateException('Failed to read p12 certificate: ' . openssl_error_string());
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
     * @return string
     * @throws CertificateException
     */
    public function getCertificate(): string
    {
        if (!isset($this->certificate)) {
            $this->certificate = $this->readP12Certificate();
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
            $this->certificate = $this->readP12Certificate();
        }

        return $this->certificate['private_key'];
    }

    /**
     * Generate PEM certificate for SOAP request
     *
     * @return false|string
     * @throws CertificateException
     */
    public function generatePem(): false|string
    {
        if (openssl_pkey_export(Str::finish($this->getPrivateKey(), '\n'), $pKey, $this->password) === false) {
            return false;
        }

        //$pKey = $this->getPrivateKey();
        $pemContent = $this->getCertificate() . $pKey;


        //$pemContent = Str::finish($this->getCertificate(), '\n') . Str::finish($this->getPrivateKey(), '\n');
        $name = Str::lower(pathinfo($this->certPath, PATHINFO_FILENAME)) . '.pem';

        if (Storage::disk($this->disk)->put($name, $pemContent)) {
            return Storage::disk($this->disk)->path($name);
        } else {
            return false;
        }
    }

    /**
     * Get certificate password
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }
}
