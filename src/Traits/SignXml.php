<?php

declare(strict_types=1);

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMDocument;
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Exceptions\XmlGenerationException;
use Taiwanleaftea\TltVerifactu\Services\XadesEpesSigner;

trait SignXml
{
    /**
     * Sign XML in accordance with VERIFACTU specification
     *
     * @throws CertificateException
     * @throws \Exception
     */
    public function signXml(Certificate $certificate, ?Carbon $signingTime = null): DOMDocument
    {
        if (! $this->generated) {
            throw new XmlGenerationException('XML must be generated first.');
        }

        $this->document = (new XadesEpesSigner)->sign($this->document, $certificate, $signingTime);
        $this->signed = true;

        return $this->document;
    }
}
