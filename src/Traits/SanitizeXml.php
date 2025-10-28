<?php

namespace Taiwanleaftea\TltVerifactu\Traits;

use DOMXPath;

trait SanitizeXml
{
    /**
     * Get XML without the XML declaration to avoid issues in SOAP body
     *
     * @param $xml
     * @return mixed
     */
    public function sanitizeXml($xml)
    {
        $dom_xpath = new DOMXPath($xml);
        $root = $dom_xpath->query('/')->item(0)->firstChild;
        return $xml->saveXML($root);
    }
}
